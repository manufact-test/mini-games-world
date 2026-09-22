<?php
declare(strict_types=1);

/**
 * MVP-21.8 canonical cancellation/emergency owner.
 *
 * LedgerWriteService remains the money owner. TournamentRoundProgressionService
 * remains the bracket/result-evidence owner. This service only closes the
 * official tournament, refunds the snapshotted entry, and marks all bracket
 * evidence annulled while preserving it for audit.
 */
final class TournamentCancellationService
{
    public const KIND_CANCEL = 'cancel';
    public const KIND_EMERGENCY = 'emergency';
    public const CONFIRMATION_MODE = 'double_confirm';

    public function __construct(
        private DatabaseConnectionInterface $database,
        private LedgerWriteService $ledger
    ) {}

    public function availability(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT tournament_id,tournament_state,title
             FROM mgw_tournaments
             WHERE active_slot=:active_slot
             LIMIT 2',
            ['active_slot'=>TournamentRegistrationService::ACTIVE_SLOT]
        );
        if ($rows === []) {
            return [
                'available'=>false,
                'reason'=>'no_active_tournament',
                'technical_cancel_required'=>false,
            ];
        }
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Tournament cancellation active slot is invalid.');
        }

        $row = $rows[0];
        $tournamentId = (string)$row['tournament_id'];
        $settled = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_tournament_results WHERE tournament_id=:tournament_id',
            ['tournament_id'=>$tournamentId]
        );
        $technicalRequired = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_tournament_round_matches
             WHERE tournament_id=:tournament_id
               AND launch_state=:launch_state
               AND annulled_at_utc IS NULL',
            [
                'tournament_id'=>$tournamentId,
                'launch_state'=>TournamentRoundProgressionService::STATE_TECHNICAL_CANCEL_REQUIRED,
            ]
        );

        return [
            'available'=>$settled === 0,
            'reason'=>$settled > 0 ? 'settlement_already_started' : 'ready',
            'tournament_id'=>$tournamentId,
            'state'=>(string)$row['tournament_state'],
            'title'=>(string)($row['title'] ?? ''),
            'technical_cancel_required'=>$technicalRequired > 0,
            'technical_cancel_required_count'=>$technicalRequired,
            'normal_cancel_available'=>$settled === 0,
            'emergency_stop_available'=>$settled === 0,
        ];
    }

    public function cancel(
        string $tournamentId,
        string $kind,
        string $reason,
        string $actorRef,
        array $confirmation,
        ?DateTimeImmutable $now = null
    ): array {
        $tournamentId = $this->required($tournamentId, 64, 'tournament id');
        $kind = strtolower($this->required($kind, 24, 'cancellation kind'));
        if (!in_array($kind, [self::KIND_CANCEL, self::KIND_EMERGENCY], true)) {
            throw new InvalidArgumentException('Unknown tournament cancellation kind.');
        }
        $actorRef = $this->required($actorRef, 191, 'actor');
        $reason = trim($reason);
        if ($kind === self::KIND_EMERGENCY && $reason === '') {
            throw new InvalidArgumentException('Для аварийной остановки обязательно укажите причину.');
        }
        if ($reason === '') {
            $reason = 'Отменено администратором.';
        }
        if (mb_strlen($reason) > 1200) {
            throw new InvalidArgumentException('Причина отмены слишком длинная.');
        }
        $this->assertDoubleConfirmation($tournamentId, $kind, $confirmation);

        $cancelledAt = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $tournamentId,
            $kind,
            $reason,
            $actorRef,
            $cancelledAt
        ): array {
            $existingEvent = $this->eventRow($db, $tournamentId, true);
            if ($existingEvent !== null) {
                if ((string)$existingEvent['cancellation_kind'] !== $kind
                    || (string)$existingEvent['reason'] !== $reason) {
                    throw new RuntimeException('Tournament is already cancelled with a different canonical reason.');
                }
                return $this->publicEvent($existingEvent, true)
                    + ['runtime_balances'=>$this->runtimeBalances($db, $tournamentId)];
            }

            $tournamentRows = $db->fetchAll(
                'SELECT * FROM mgw_tournaments
                 WHERE tournament_id=:tournament_id' . $this->forUpdate($db),
                ['tournament_id'=>$tournamentId]
            );
            if (count($tournamentRows) !== 1 || !is_array($tournamentRows[0])) {
                throw new RuntimeException('Tournament cancellation target is unavailable.');
            }
            $tournament = $tournamentRows[0];
            if ((string)($tournament['active_slot'] ?? '') !== TournamentRegistrationService::ACTIVE_SLOT) {
                throw new RuntimeException('Tournament is not the active official tournament.');
            }

            $state = (string)($tournament['tournament_state'] ?? '');
            if (in_array($state, [
                TournamentRegistrationService::STATE_CANCELLED,
                TournamentRegistrationService::STATE_EMERGENCY_STOPPED,
            ], true)) {
                throw new RuntimeException('Tournament cancellation audit is missing for an already closed tournament.');
            }

            $entryAmount = (int)($tournament['entry_fee_amount'] ?? 0);
            $entryAsset = (string)($tournament['entry_asset_code'] ?? '');
            if ($entryAmount !== TournamentRegistrationService::ENTRY_FEE
                || $entryAsset !== TournamentRegistrationService::ENTRY_ASSET) {
                throw new RuntimeException('Tournament entry snapshot is invalid for full refund.');
            }

            $resultCount = (int)$db->fetchValue(
                'SELECT COUNT(*) FROM mgw_tournament_results WHERE tournament_id=:tournament_id',
                ['tournament_id'=>$tournamentId]
            );
            if ($resultCount > 0) {
                throw new RuntimeException(
                    'Tournament settlement has already started. Automatic cancellation refuses reward clawback.'
                );
            }

            $registrations = $db->fetchAll(
                'SELECT r.registration_id,r.mgw_id,r.account_ref,r.reservation_id,
                        z.status AS reservation_status,z.amount AS reservation_amount,
                        z.asset_code AS reservation_asset,z.source_type AS reservation_source_type,
                        z.source_ref AS reservation_source_ref,z.legacy_user_id
                 FROM mgw_tournament_registrations r
                 INNER JOIN mgw_reservations z ON z.reservation_id=r.reservation_id
                 WHERE r.tournament_id=:tournament_id
                   AND r.registration_state=:registration_state
                 ORDER BY r.registered_at_utc ASC,r.registration_id ASC'
                 . $this->forUpdate($db),
                [
                    'tournament_id'=>$tournamentId,
                    'registration_state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
                ]
            );

            $released = 0;
            $consumedRefunds = 0;
            $refundedCount = 0;
            $refundAmount = 0;

            foreach ($registrations as $registration) {
                if (!is_array($registration)) continue;
                $registrationId = $this->required(
                    (string)($registration['registration_id'] ?? ''),
                    96,
                    'registration id'
                );
                $mgwId = $this->required((string)($registration['mgw_id'] ?? ''), 24, 'MGW-ID');
                $accountRef = $this->required((string)($registration['account_ref'] ?? ''), 255, 'account ref');
                $reservationId = $this->required(
                    (string)($registration['reservation_id'] ?? ''),
                    96,
                    'reservation id'
                );
                $reservationStatus = (string)($registration['reservation_status'] ?? '');
                if ((int)($registration['reservation_amount'] ?? -1) !== $entryAmount
                    || (string)($registration['reservation_asset'] ?? '') !== $entryAsset
                    || (string)($registration['reservation_source_type'] ?? '') !== 'official_tournament'
                    || (string)($registration['reservation_source_ref'] ?? '') !== $tournamentId) {
                    throw new RuntimeException('Tournament cancellation found an unproven entry reservation.');
                }

                $metadata = [
                    'tournament_id'=>$tournamentId,
                    'registration_id'=>$registrationId,
                    'mgw_id'=>$mgwId,
                    'cancellation_kind'=>$kind,
                    'reason'=>$reason,
                    'actor_ref'=>$actorRef,
                    'purpose'=>'tournament_cancellation_full_refund',
                ];

                if ($reservationStatus === 'active') {
                    $this->ledger->releaseReservation([
                        'operation_key'=>$this->operationKey($tournamentId, $registrationId, 'release'),
                        'reservation_id'=>$reservationId,
                        'metadata'=>$metadata,
                        'occurred_at_utc'=>$cancelledAt,
                    ]);
                    $released++;
                } elseif ($reservationStatus === 'consumed') {
                    $registrationResultCount = (int)$db->fetchValue(
                        'SELECT COUNT(*) FROM mgw_tournament_results
                         WHERE registration_id=:registration_id',
                        ['registration_id'=>$registrationId]
                    );
                    if ($registrationResultCount > 0) {
                        throw new RuntimeException('Consumed entry already has a settled tournament result.');
                    }

                    $this->ledger->postAvailableDelta([
                        'operation_key'=>$this->operationKey($tournamentId, $registrationId, 'consumed-refund'),
                        'account_ref'=>$accountRef,
                        'mgw_id'=>$mgwId,
                        'legacy_user_id'=>$this->nullable((string)($registration['legacy_user_id'] ?? '')),
                        'asset_code'=>$entryAsset,
                        'available_delta'=>$entryAmount,
                        'category'=>'tournament_cancellation_refund',
                        'source_type'=>'official_tournament',
                        'source_ref'=>$tournamentId,
                        'metadata'=>$metadata + ['reservation_status'=>'consumed'],
                        'occurred_at_utc'=>$cancelledAt,
                    ]);
                    $consumedRefunds++;
                } else {
                    throw new RuntimeException('Tournament cancellation requires an active or consumed entry reservation.');
                }

                $balance = $this->ledger->getBalance($accountRef, $entryAsset);
                if (!is_array($balance) || (int)($balance['reserved_amount'] ?? -1) !== 0) {
                    throw new RuntimeException('Tournament cancellation did not fully settle an entry reservation.');
                }

                $updated = $db->execute(
                    'UPDATE mgw_tournament_registrations
                     SET registration_state=:registration_state,
                         withdrawn_at_utc=COALESCE(withdrawn_at_utc,:cancelled_at_utc),
                         updated_at_utc=:updated_at_utc
                     WHERE registration_id=:registration_id
                       AND registration_state=:expected_state',
                    [
                        'registration_state'=>TournamentRegistrationService::REGISTRATION_CANCELLED,
                        'cancelled_at_utc'=>$cancelledAt,
                        'updated_at_utc'=>$cancelledAt,
                        'registration_id'=>$registrationId,
                        'expected_state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
                    ]
                );
                if ($updated !== 1) {
                    throw new RuntimeException('Tournament registration changed during cancellation.');
                }

                $refundedCount++;
                $refundAmount += $entryAmount;
            }

            $annulmentReason = $kind === self::KIND_EMERGENCY
                ? 'tournament_emergency_stop'
                : 'tournament_cancelled';

            $annulledMatches = $db->execute(
                'UPDATE mgw_tournament_round_matches
                 SET annulled_at_utc=:annulled_at_utc,
                     annulment_reason=:annulment_reason,
                     updated_at_utc=:updated_at_utc
                 WHERE tournament_id=:tournament_id
                   AND annulled_at_utc IS NULL',
                [
                    'annulled_at_utc'=>$cancelledAt,
                    'annulment_reason'=>$annulmentReason,
                    'updated_at_utc'=>$cancelledAt,
                    'tournament_id'=>$tournamentId,
                ]
            );
            $annulledAttempts = $db->execute(
                'UPDATE mgw_tournament_match_attempts
                 SET annulled_at_utc=:annulled_at_utc,
                     annulment_reason=:annulment_reason
                 WHERE tournament_id=:tournament_id
                   AND annulled_at_utc IS NULL',
                [
                    'annulled_at_utc'=>$cancelledAt,
                    'annulment_reason'=>$annulmentReason,
                    'tournament_id'=>$tournamentId,
                ]
            );
            $annulledTechnical = $db->execute(
                'UPDATE mgw_tournament_technical_outcomes
                 SET annulled_at_utc=:annulled_at_utc,
                     annulment_reason=:annulment_reason
                 WHERE tournament_id=:tournament_id
                   AND annulled_at_utc IS NULL',
                [
                    'annulled_at_utc'=>$cancelledAt,
                    'annulment_reason'=>$annulmentReason,
                    'tournament_id'=>$tournamentId,
                ]
            );

            $targetState = $kind === self::KIND_EMERGENCY
                ? TournamentRegistrationService::STATE_EMERGENCY_STOPPED
                : TournamentRegistrationService::STATE_CANCELLED;

            $updatedTournament = $db->execute(
                'UPDATE mgw_tournaments
                 SET active_slot=NULL,
                     tournament_state=:tournament_state,
                     cancellation_kind=:cancellation_kind,
                     cancellation_reason=:cancellation_reason,
                     cancelled_by_ref=:cancelled_by_ref,
                     cancelled_at_utc=:cancelled_at_utc,
                     registration_closed_at_utc=COALESCE(registration_closed_at_utc,:registration_closed_at_utc),
                     registration_closed_reason=:registration_closed_reason,
                     updated_at_utc=:updated_at_utc
                 WHERE tournament_id=:tournament_id
                   AND active_slot=:active_slot',
                [
                    'tournament_state'=>$targetState,
                    'cancellation_kind'=>$kind,
                    'cancellation_reason'=>$reason,
                    'cancelled_by_ref'=>$actorRef,
                    'cancelled_at_utc'=>$cancelledAt,
                    'registration_closed_at_utc'=>$cancelledAt,
                    'registration_closed_reason'=>$annulmentReason,
                    'updated_at_utc'=>$cancelledAt,
                    'tournament_id'=>$tournamentId,
                    'active_slot'=>TournamentRegistrationService::ACTIVE_SLOT,
                ]
            );
            if ($updatedTournament !== 1) {
                throw new RuntimeException('Tournament cancellation could not release the active slot.');
            }

            $participantCount = count($registrations);
            if ($refundedCount !== $participantCount
                || $refundAmount !== ($participantCount * $entryAmount)) {
                throw new RuntimeException('Tournament cancellation full-refund invariant failed.');
            }

            $eventKey = hash('sha256', $tournamentId . '|mvp21.8|cancellation');
            $db->execute(
                'INSERT INTO mgw_tournament_cancellation_events (
                    event_key,tournament_id,cancellation_kind,pre_cancel_state,actor_ref,reason,
                    confirmation_mode,participant_count,refunded_count,refund_amount,
                    released_reservation_count,consumed_refund_count,
                    annulled_match_count,annulled_attempt_count,annulled_technical_count,
                    created_at_utc
                 ) VALUES (
                    :event_key,:tournament_id,:cancellation_kind,:pre_cancel_state,:actor_ref,:reason,
                    :confirmation_mode,:participant_count,:refunded_count,:refund_amount,
                    :released_reservation_count,:consumed_refund_count,
                    :annulled_match_count,:annulled_attempt_count,:annulled_technical_count,
                    :created_at_utc
                 )',
                [
                    'event_key'=>$eventKey,
                    'tournament_id'=>$tournamentId,
                    'cancellation_kind'=>$kind,
                    'pre_cancel_state'=>$state,
                    'actor_ref'=>$actorRef,
                    'reason'=>$reason,
                    'confirmation_mode'=>self::CONFIRMATION_MODE,
                    'participant_count'=>$participantCount,
                    'refunded_count'=>$refundedCount,
                    'refund_amount'=>$refundAmount,
                    'released_reservation_count'=>$released,
                    'consumed_refund_count'=>$consumedRefunds,
                    'annulled_match_count'=>$annulledMatches,
                    'annulled_attempt_count'=>$annulledAttempts,
                    'annulled_technical_count'=>$annulledTechnical,
                    'created_at_utc'=>$cancelledAt,
                ]
            );

            $event = $this->eventRow($db, $tournamentId, false);
            if ($event === null) {
                throw new RuntimeException('Tournament cancellation audit was not persisted.');
            }

            return $this->publicEvent($event, false)
                + ['runtime_balances'=>$this->runtimeBalances($db, $tournamentId)];
        });
    }

    public function lastCancellationForParticipant(string $mgwId): ?array
    {
        $mgwId = $this->required($mgwId, 24, 'MGW-ID');
        $rows = $this->database->fetchAll(
            'SELECT e.*,t.title,t.game_type,t.entry_fee_amount,r.registration_state
             FROM mgw_tournament_cancellation_events e
             INNER JOIN mgw_tournaments t ON t.tournament_id=e.tournament_id
             INNER JOIN mgw_tournament_registrations r
               ON r.tournament_id=e.tournament_id AND r.mgw_id=:mgw_id
             WHERE r.registration_state=:registration_state
             ORDER BY e.created_at_utc DESC
             LIMIT 1',
            [
                'mgw_id'=>$mgwId,
                'registration_state'=>TournamentRegistrationService::REGISTRATION_CANCELLED,
            ]
        );
        if ($rows === [] || !is_array($rows[0])) return null;
        $row = $rows[0];

        return [
            'tournament_id'=>(string)$row['tournament_id'],
            'title'=>(string)($row['title'] ?? 'Официальный турнир'),
            'game_type'=>(string)($row['game_type'] ?? ''),
            'kind'=>(string)$row['cancellation_kind'],
            'reason'=>(string)$row['reason'],
            'refund_amount'=>(int)($row['entry_fee_amount'] ?? TournamentRegistrationService::ENTRY_FEE),
            'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
            'results_annulled'=>true,
            'cancelled_at_utc'=>(string)$row['created_at_utc'],
        ];
    }

    public function eventForTournament(string $tournamentId): ?array
    {
        $tournamentId = $this->required($tournamentId, 64, 'tournament id');
        $row = $this->eventRow($this->database, $tournamentId, false);
        return $row === null ? null : $this->publicEvent($row, true);
    }

    private function assertDoubleConfirmation(string $tournamentId, string $kind, array $confirmation): void
    {
        if (($confirmation['confirmed'] ?? false) !== true
            || (string)($confirmation['mode'] ?? '') !== self::CONFIRMATION_MODE
            || (string)($confirmation['tournament_id'] ?? '') !== $tournamentId
            || (string)($confirmation['kind'] ?? '') !== $kind) {
            throw new InvalidArgumentException('Отмена турнира требует второго подтверждения.');
        }
    }

    private function eventRow(
        DatabaseConnectionInterface $db,
        string $tournamentId,
        bool $lock
    ): ?array {
        $rows = $db->fetchAll(
            'SELECT * FROM mgw_tournament_cancellation_events
             WHERE tournament_id=:tournament_id'
             . ($lock ? $this->forUpdate($db) : ''),
            ['tournament_id'=>$tournamentId]
        );
        if ($rows === []) return null;
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Tournament cancellation audit is invalid.');
        }
        return $rows[0];
    }

    private function publicEvent(array $row, bool $replayed): array
    {
        return [
            'status'=>'cancelled',
            'tournament_id'=>(string)$row['tournament_id'],
            'kind'=>(string)$row['cancellation_kind'],
            'pre_cancel_state'=>(string)$row['pre_cancel_state'],
            'reason'=>(string)$row['reason'],
            'participant_count'=>(int)$row['participant_count'],
            'refunded_count'=>(int)$row['refunded_count'],
            'refund_amount'=>(int)$row['refund_amount'],
            'released_reservation_count'=>(int)$row['released_reservation_count'],
            'consumed_refund_count'=>(int)$row['consumed_refund_count'],
            'annulled_match_count'=>(int)$row['annulled_match_count'],
            'annulled_attempt_count'=>(int)$row['annulled_attempt_count'],
            'annulled_technical_count'=>(int)$row['annulled_technical_count'],
            'results_annulled'=>true,
            'cancelled_at_utc'=>(string)$row['created_at_utc'],
            'replayed'=>$replayed,
        ];
    }

    private function runtimeBalances(DatabaseConnectionInterface $db, string $tournamentId): array
    {
        $rows = $db->fetchAll(
            'SELECT z.legacy_user_id,b.available_amount,b.reserved_amount
             FROM mgw_tournament_registrations r
             INNER JOIN mgw_reservations z ON z.reservation_id=r.reservation_id
             INNER JOIN mgw_balances b
               ON b.account_ref=r.account_ref AND b.asset_code=:asset_code
             WHERE r.tournament_id=:tournament_id',
            [
                'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
                'tournament_id'=>$tournamentId,
            ]
        );
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $legacyUserId = trim((string)($row['legacy_user_id'] ?? ''));
            if ($legacyUserId === '') continue;
            $result[$legacyUserId] = [
                'available_amount'=>(int)$row['available_amount'],
                'reserved_amount'=>(int)$row['reserved_amount'],
            ];
        }
        return $result;
    }

    private function operationKey(string $tournamentId, string $registrationId, string $suffix): string
    {
        return 'tournament:cancel:'
            . substr(hash('sha256', $tournamentId . '|' . $registrationId), 0, 40)
            . ':'
            . $suffix;
    }

    private function required(string $value, int $max, string $label): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $max) {
            throw new InvalidArgumentException('Invalid ' . $label . '.');
        }
        return $value;
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function forUpdate(DatabaseConnectionInterface $database): string
    {
        return $database->driver() === 'sqlite' ? '' : ' FOR UPDATE';
    }
}
