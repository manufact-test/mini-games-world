<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/localization/ServerLocalization.php';

final class TournamentRegistrationService
{
    public const ACTIVE_SLOT = 'official';
    public const ENTRY_ASSET = 'mgw_coin';
    public const ENTRY_FEE = 50000;
    public const STATE_DRAFT = 'draft';
    public const STATE_REGISTRATION_OPEN = 'registration_open';
    public const STATE_WAITING_FOR_DATE = 'waiting_for_date';
    public const STATE_SCHEDULED = 'scheduled';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_EMERGENCY_STOPPED = 'emergency_stopped';
    public const RULES_VERSION = 'official-tournament-rules-v2';
    public const RULES_LANGUAGE = 'ru';
    public const REGISTRATION_REGISTERED = 'registered';
    public const REGISTRATION_WITHDRAWN = 'withdrawn';
    public const REGISTRATION_CANCELLED = 'cancelled';
    public const ALLOWED_CAPACITIES = [8, 16, 32, 64, 128];

    public function __construct(
        private DatabaseConnectionInterface $database,
        private LedgerWriteService $ledger
    ) {}

    public function createDraft(
        string $gameType,
        int $capacity,
        string $title,
        string $actorRef,
        ?DateTimeImmutable $now = null
    ): array {
        $gameType = $this->token($gameType, 32, 'game type');
        if (!in_array($capacity, self::ALLOWED_CAPACITIES, true)) {
            throw new InvalidArgumentException('Tournament capacity must be 8, 16, 32, 64 or 128.');
        }
        $title = $this->text($title, 160);
        if ($title === '') $title = ServerLocalization::copy('arena.official_title', 'Official tournament');
        $actorRef = $this->requiredText($actorRef, 191, 'actor');
        $createdAt = $this->utc($now);

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $gameType,
            $capacity,
            $title,
            $actorRef,
            $createdAt
        ): array {
            $existing = $db->fetchAll(
                'SELECT tournament_id FROM mgw_tournaments
                 WHERE active_slot=:active_slot' . $this->forUpdate($db),
                ['active_slot'=>self::ACTIVE_SLOT]
            );
            if ($existing !== []) {
                throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.scheduling.single_official_only', 'Only one official tournament may exist at a time.'));
            }

            $tournamentId = $this->newTournamentId($actorRef, $createdAt);
            $snapshot = self::canonicalRewardSnapshot();
            $rulesSnapshot = self::canonicalRulesSnapshot($gameType, $capacity);
            $rulesJson = $this->encodeJson($rulesSnapshot);
            $rulesSha256 = self::rulesSha256FromJson($rulesJson);

            $db->execute(
                'INSERT INTO mgw_tournaments (
                    tournament_id,active_slot,title,game_type,capacity,
                    entry_fee_amount,entry_asset_code,reward_snapshot_json,
                    rules_version,rules_language,rules_snapshot_json,rules_sha256,
                    tournament_state,created_by_ref,opened_by_ref,
                    created_at_utc,registration_opened_at_utc,
                    registration_closed_at_utc,registration_closed_reason,updated_at_utc
                 ) VALUES (
                    :tournament_id,:active_slot,:title,:game_type,:capacity,
                    :entry_fee_amount,:entry_asset_code,:reward_snapshot_json,
                    :rules_version,:rules_language,:rules_snapshot_json,:rules_sha256,
                    :tournament_state,:created_by_ref,NULL,
                    :created_at_utc,NULL,NULL,NULL,:updated_at_utc
                 )',
                [
                    'tournament_id'=>$tournamentId,
                    'active_slot'=>self::ACTIVE_SLOT,
                    'title'=>$title,
                    'game_type'=>$gameType,
                    'capacity'=>$capacity,
                    'entry_fee_amount'=>self::ENTRY_FEE,
                    'entry_asset_code'=>self::ENTRY_ASSET,
                    'reward_snapshot_json'=>$this->encodeJson($snapshot),
                    'rules_version'=>self::RULES_VERSION,
                    'rules_language'=>self::RULES_LANGUAGE,
                    'rules_snapshot_json'=>$rulesJson,
                    'rules_sha256'=>$rulesSha256,
                    'tournament_state'=>self::STATE_DRAFT,
                    'created_by_ref'=>$actorRef,
                    'created_at_utc'=>$createdAt,
                    'updated_at_utc'=>$createdAt,
                ]
            );

            return $this->snapshotForRow($db, $this->tournamentRow($db, $tournamentId, false), null, null);
        });
    }

    public function openRegistration(
        string $tournamentId,
        string $actorRef,
        ?DateTimeImmutable $now = null
    ): array {
        $tournamentId = $this->requiredText($tournamentId, 64, 'tournament id');
        $actorRef = $this->requiredText($actorRef, 191, 'actor');
        $openedAt = $this->utc($now);

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $tournamentId,
            $actorRef,
            $openedAt
        ): array {
            $row = $this->tournamentRow($db, $tournamentId, true);
            if ((string)$row['active_slot'] !== self::ACTIVE_SLOT) {
                throw new RuntimeException('Tournament is not the active official tournament.');
            }
            $this->assertTournamentRules($row);
            if ((string)$row['tournament_state'] === self::STATE_REGISTRATION_OPEN) {
                return $this->snapshotForRow($db, $row, null, null);
            }
            if ((string)$row['tournament_state'] !== self::STATE_DRAFT) {
                throw new RuntimeException('Tournament registration cannot be opened from the current state.');
            }

            $updated = $db->execute(
                'UPDATE mgw_tournaments
                 SET tournament_state=:state,
                     opened_by_ref=:opened_by_ref,
                     registration_opened_at_utc=:opened_at,
                     updated_at_utc=:updated_at
                 WHERE tournament_id=:tournament_id
                   AND tournament_state=:expected_state',
                [
                    'state'=>self::STATE_REGISTRATION_OPEN,
                    'opened_by_ref'=>$actorRef,
                    'opened_at'=>$openedAt,
                    'updated_at'=>$openedAt,
                    'tournament_id'=>$tournamentId,
                    'expected_state'=>self::STATE_DRAFT,
                ]
            );
            if ($updated !== 1) throw new RuntimeException('Tournament registration state changed concurrently.');

            return $this->snapshotForRow($db, $this->tournamentRow($db, $tournamentId, false), null, null);
        });
    }

    public function assignFinalDate(
        string $tournamentId,
        string $startAtUtc,
        string $actorRef,
        ?DateTimeImmutable $now = null
    ): array {
        $tournamentId = $this->requiredText($tournamentId, 64, 'tournament id');
        $actorRef = $this->requiredText($actorRef, 191, 'actor');
        $startAtUtc = trim($startAtUtc);
        if ($startAtUtc === '') {
            throw new InvalidArgumentException(ServerLocalization::copy('server.tournament_runtime.scheduling.start_required', 'Specify the tournament start date and time.'));
        }

        $utc = new DateTimeZone('UTC');
        $assignedMoment = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        try {
            $startMoment = (new DateTimeImmutable($startAtUtc, $utc))->setTimezone($utc);
        } catch (Throwable) {
            throw new InvalidArgumentException(ServerLocalization::copy('server.tournament_runtime.scheduling.start_invalid', 'Invalid tournament start date or time.'));
        }
        if ($startMoment <= $assignedMoment) {
            throw new InvalidArgumentException(ServerLocalization::copy('server.tournament_runtime.scheduling.start_future', 'The tournament start date must be in the future.'));
        }

        $assignedAt = $assignedMoment->format('Y-m-d H:i:s.u');
        $scheduledStartAt = $startMoment->format('Y-m-d H:i:s.u');

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $tournamentId,
            $actorRef,
            $assignedAt,
            $scheduledStartAt
        ): array {
            $row = $this->tournamentRow($db, $tournamentId, true);
            if ((string)$row['active_slot'] !== self::ACTIVE_SLOT) {
                throw new RuntimeException('Tournament is not the active official tournament.');
            }

            $state = (string)$row['tournament_state'];
            $existingStart = $this->nullableText($row['scheduled_start_at_utc'] ?? null, 32);
            if ($state === self::STATE_SCHEDULED || $existingStart !== null) {
                if ($state === self::STATE_SCHEDULED
                    && $existingStart !== null
                    && hash_equals($existingStart, $scheduledStartAt)) {
                    return $this->snapshotForRow($db, $row, null, null);
                }
                throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.scheduling.already_scheduled', 'The tournament date has already been assigned. Rescheduling is outside MVP-21.3.'));
            }
            if ($state !== self::STATE_WAITING_FOR_DATE) {
                throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.scheduling.roster_required', 'A date can be assigned only after the roster is full.'));
            }

            $registeredCount = $this->registeredCount($db, $tournamentId);
            if ($registeredCount !== (int)$row['capacity']) {
                throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.scheduling.capacity_required', 'The tournament date can be assigned only for a full roster.'));
            }
            $acceptedCount = (int)$db->fetchValue(
                'SELECT COUNT(*) FROM mgw_tournament_registrations
                 WHERE tournament_id=:tournament_id
                   AND registration_state=:state
                   AND rules_accepted_at_utc IS NOT NULL',
                ['tournament_id'=>$tournamentId,'state'=>self::REGISTRATION_REGISTERED]
            );
            if ($acceptedCount !== $registeredCount) {
                throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.scheduling.consent_required', 'Not all participants have accepted the tournament rules.'));
            }

            $updated = $db->execute(
                'UPDATE mgw_tournaments
                 SET tournament_state=:state,
                     scheduled_start_at_utc=:scheduled_start_at_utc,
                     scheduled_by_ref=:scheduled_by_ref,
                     scheduled_at_utc=:scheduled_at_utc,
                     updated_at_utc=:updated_at_utc
                 WHERE tournament_id=:tournament_id
                   AND tournament_state=:expected_state
                   AND scheduled_start_at_utc IS NULL',
                [
                    'state'=>self::STATE_SCHEDULED,
                    'scheduled_start_at_utc'=>$scheduledStartAt,
                    'scheduled_by_ref'=>$actorRef,
                    'scheduled_at_utc'=>$assignedAt,
                    'updated_at_utc'=>$assignedAt,
                    'tournament_id'=>$tournamentId,
                    'expected_state'=>self::STATE_WAITING_FOR_DATE,
                ]
            );
            if ($updated !== 1) {
                throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.scheduling.schedule_race', 'The tournament date changed concurrently with this request.'));
            }

            return $this->snapshotForRow(
                $db,
                $this->tournamentRow($db, $tournamentId, false),
                null,
                null
            );
        });
    }

    public function registeredParticipantMgwIds(string $tournamentId): array
    {
        $tournamentId = $this->requiredText($tournamentId, 64, 'tournament id');
        $this->tournamentRow($this->database, $tournamentId, false);
        $rows = $this->database->fetchAll(
            'SELECT mgw_id FROM mgw_tournament_registrations
             WHERE tournament_id=:tournament_id AND registration_state=:state
             ORDER BY registered_at_utc ASC, registration_id ASC',
            ['tournament_id'=>$tournamentId,'state'=>self::REGISTRATION_REGISTERED]
        );
        $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            if ($mgwId !== '') $ids[$mgwId] = $mgwId;
        }
        return array_values($ids);
    }

    public function snapshot(?string $mgwId = null, ?string $accountRef = null): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_tournaments
             WHERE active_slot=:active_slot
             LIMIT 1',
            ['active_slot'=>self::ACTIVE_SLOT]
        );
        if ($rows === []) {
            return [
                'tournament'=>null,
                'registration'=>null,
                'balance'=>$accountRef === null ? null : $this->publicBalance($accountRef),
            ];
        }
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Official tournament state is invalid.');
        }
        return $this->snapshotForRow($this->database, $rows[0], $mgwId, $accountRef);
    }

    public function register(
        string $mgwId,
        string $accountRef,
        ?DateTimeImmutable $now = null,
        ?array $rulesConsent = null
    ): array {
        $mgwId = $this->requiredText($mgwId, 24, 'MGW-ID');
        $accountRef = $this->requiredText($accountRef, 255, 'account ref');
        $registeredAt = $this->utc($now);

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $mgwId,
            $accountRef,
            $registeredAt,
            $rulesConsent
        ): array {
            $tournament = $this->activeTournamentRow($db, true);
            $this->assertTournamentRules($tournament);

            $registrationRows = $db->fetchAll(
                'SELECT * FROM mgw_tournament_registrations
                 WHERE tournament_id=:tournament_id AND mgw_id=:mgw_id
                 ORDER BY attempt_no DESC LIMIT 1' . $this->forUpdate($db),
                ['tournament_id'=>$tournament['tournament_id'],'mgw_id'=>$mgwId]
            );
            $existing = $registrationRows[0] ?? null;

            if ((string)$tournament['tournament_state'] === self::STATE_WAITING_FOR_DATE) {
                if (is_array($existing)
                    && (string)$existing['registration_state'] === self::REGISTRATION_REGISTERED) {
                    return $this->snapshotForRow($db, $tournament, $mgwId, $accountRef);
                }
                throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.registration.closed_full', 'Tournament request could not be completed.'));
            }
            if ((string)$tournament['tournament_state'] !== self::STATE_REGISTRATION_OPEN) {
                throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.registration.closed', 'Tournament request could not be completed.'));
            }

            if (is_array($existing) && (string)$existing['registration_state'] === self::REGISTRATION_REGISTERED) {
                $acceptedAt = trim((string)($existing['rules_accepted_at_utc'] ?? ''));
                $acceptedSha256 = trim((string)($existing['rules_sha256'] ?? ''));
                $currentSha256 = trim((string)($tournament['rules_sha256'] ?? ''));
                $needsConsentRefresh = $acceptedAt === ''
                    || $acceptedSha256 === ''
                    || $currentSha256 === ''
                    || !hash_equals($currentSha256, $acceptedSha256);

                if ($needsConsentRefresh) {
                    $consent = $this->validatedRulesConsent($tournament, $rulesConsent);
                    $updated = $db->execute(
                        'UPDATE mgw_tournament_registrations
                         SET rules_version=:rules_version,
                             rules_language=:rules_language,
                             rules_sha256=:rules_sha256,
                             rules_accepted_at_utc=:rules_accepted_at_utc,
                             updated_at_utc=:updated_at_utc
                         WHERE registration_id=:registration_id
                           AND registration_state=:registration_state',
                        [
                            'rules_version'=>$consent['version'],
                            'rules_language'=>$consent['language'],
                            'rules_sha256'=>$consent['sha256'],
                            'rules_accepted_at_utc'=>$registeredAt,
                            'updated_at_utc'=>$registeredAt,
                            'registration_id'=>(string)$existing['registration_id'],
                            'registration_state'=>self::REGISTRATION_REGISTERED,
                        ]
                    );
                    if ($updated !== 1) {
                        throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.registration.consent_race', 'Tournament request could not be completed.'));
                    }
                    $existing['rules_version'] = $consent['version'];
                    $existing['rules_language'] = $consent['language'];
                    $existing['rules_sha256'] = $consent['sha256'];
                    $existing['rules_accepted_at_utc'] = $registeredAt;
                }

                [$tournament, $closedNow] = $this->closeRegistrationIfReady($db, $tournament, $registeredAt);
                $snapshot = $this->snapshotForRow($db, $tournament, $mgwId, $accountRef);
                if ($closedNow) {
                    $snapshot['transition'] = ['registration_closed_now'=>true,'reason'=>'full'];
                }
                return $snapshot;
            }

            $registeredCount = $this->registeredCount($db, (string)$tournament['tournament_id']);
            $capacity = (int)$tournament['capacity'];
            if ($registeredCount >= $capacity) {
                throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.registration.full', 'Tournament request could not be completed.'));
            }

            $consent = $this->validatedRulesConsent($tournament, $rulesConsent);
            $attempt = is_array($existing) ? ((int)$existing['attempt_no'] + 1) : 1;
            $registrationId = $this->registrationId((string)$tournament['tournament_id'], $mgwId, $attempt);
            $operationKey = $this->operationKey('register', (string)$tournament['tournament_id'], $mgwId, $attempt);
            $identity = $this->ledgerIdentity($accountRef, $mgwId);

            $reservation = $this->ledger->createReservation([
                'operation_key'=>$operationKey,
                'account_ref'=>$identity['account_ref'],
                'mgw_id'=>$identity['mgw_id'],
                'legacy_user_id'=>$identity['legacy_user_id'],
                'asset_code'=>(string)$tournament['entry_asset_code'],
                'amount'=>(int)$tournament['entry_fee_amount'],
                'source_type'=>'official_tournament',
                'source_ref'=>(string)$tournament['tournament_id'],
                'metadata'=>[
                    'tournament_id'=>(string)$tournament['tournament_id'],
                    'registration_id'=>$registrationId,
                    'attempt_no'=>$attempt,
                    'purpose'=>'registration_entry_reservation',
                    'rules_version'=>$consent['version'],
                    'rules_language'=>$consent['language'],
                    'rules_sha256'=>$consent['sha256'],
                ],
            ]);

            $db->execute(
                'INSERT INTO mgw_tournament_registrations (
                    registration_id,tournament_id,mgw_id,account_ref,attempt_no,
                    registration_state,reservation_id,
                    rules_version,rules_language,rules_sha256,rules_accepted_at_utc,
                    registered_at_utc,withdrawn_at_utc,updated_at_utc,published_at_utc
                 ) VALUES (
                    :registration_id,:tournament_id,:mgw_id,:account_ref,:attempt_no,
                    :registration_state,:reservation_id,
                    :rules_version,:rules_language,:rules_sha256,:rules_accepted_at_utc,
                    :registered_at_utc,NULL,:updated_at_utc,NULL
                 )',
                [
                    'registration_id'=>$registrationId,
                    'tournament_id'=>$tournament['tournament_id'],
                    'mgw_id'=>$mgwId,
                    'account_ref'=>$accountRef,
                    'attempt_no'=>$attempt,
                    'registration_state'=>self::REGISTRATION_REGISTERED,
                    'reservation_id'=>$reservation['reservation_id'],
                    'rules_version'=>$consent['version'],
                    'rules_language'=>$consent['language'],
                    'rules_sha256'=>$consent['sha256'],
                    'rules_accepted_at_utc'=>$registeredAt,
                    'registered_at_utc'=>$registeredAt,
                    'updated_at_utc'=>$registeredAt,
                ]
            );

            [$tournament, $closedNow] = $this->closeRegistrationIfReady($db, $tournament, $registeredAt);
            $snapshot = $this->snapshotForRow($db, $tournament, $mgwId, $accountRef);
            if ($closedNow) {
                $snapshot['transition'] = ['registration_closed_now'=>true,'reason'=>'full'];
            }
            return $snapshot;
        });
    }

    public function publishRegistration(
        string $mgwId,
        string $accountRef,
        ?DateTimeImmutable $now = null
    ): array {
        $mgwId = $this->requiredText($mgwId, 24, 'MGW-ID');
        $accountRef = $this->requiredText($accountRef, 255, 'account ref');
        $publishedAt = $this->utc($now);

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $mgwId,
            $accountRef,
            $publishedAt
        ): array {
            $tournament = $this->activeTournamentRow($db, true);
            $rows = $db->fetchAll(
                'SELECT * FROM mgw_tournament_registrations
                 WHERE tournament_id=:tournament_id
                   AND mgw_id=:mgw_id
                   AND account_ref=:account_ref
                 ORDER BY attempt_no DESC LIMIT 1' . $this->forUpdate($db),
                [
                    'tournament_id'=>$tournament['tournament_id'],
                    'mgw_id'=>$mgwId,
                    'account_ref'=>$accountRef,
                ]
            );
            if ($rows === [] || !is_array($rows[0])
                || (string)($rows[0]['registration_state'] ?? '') !== self::REGISTRATION_REGISTERED) {
                throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.registration.publication_missing', 'Tournament request could not be completed.'));
            }

            $registration = $rows[0];
            if (trim((string)($registration['published_at_utc'] ?? '')) === '') {
                $updated = $db->execute(
                    'UPDATE mgw_tournament_registrations
                     SET published_at_utc=:published_at_utc,
                         updated_at_utc=:updated_at_utc
                     WHERE registration_id=:registration_id
                       AND registration_state=:registration_state
                       AND published_at_utc IS NULL',
                    [
                        'published_at_utc'=>$publishedAt,
                        'updated_at_utc'=>$publishedAt,
                        'registration_id'=>(string)$registration['registration_id'],
                        'registration_state'=>self::REGISTRATION_REGISTERED,
                    ]
                );
                if ($updated !== 1) {
                    throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.registration.publication_race', 'Tournament request could not be completed.'));
                }
            }

            [$tournament, $closedNow] = $this->closeRegistrationIfReady($db, $tournament, $publishedAt);
            $snapshot = $this->snapshotForRow($db, $tournament, $mgwId, $accountRef);
            if ($closedNow) {
                $snapshot['transition'] = ['registration_closed_now'=>true,'reason'=>'full'];
            }
            return $snapshot;
        });
    }

    public function leave(
        string $mgwId,
        string $accountRef,
        ?DateTimeImmutable $now = null
    ): array {
        $mgwId = $this->requiredText($mgwId, 24, 'MGW-ID');
        $accountRef = $this->requiredText($accountRef, 255, 'account ref');
        $withdrawnAt = $this->utc($now);

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $mgwId,
            $accountRef,
            $withdrawnAt
        ): array {
            $tournament = $this->activeTournamentRow($db, true);
            if ((string)$tournament['tournament_state'] !== self::STATE_REGISTRATION_OPEN) {
                throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.registration.leave_unavailable', 'Tournament request could not be completed.'));
            }

            $rows = $db->fetchAll(
                'SELECT * FROM mgw_tournament_registrations
                 WHERE tournament_id=:tournament_id AND mgw_id=:mgw_id
                 ORDER BY attempt_no DESC LIMIT 1' . $this->forUpdate($db),
                ['tournament_id'=>$tournament['tournament_id'],'mgw_id'=>$mgwId]
            );
            if ($rows === [] || !is_array($rows[0])
                || (string)$rows[0]['registration_state'] !== self::REGISTRATION_REGISTERED) {
                throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.registration.not_found', 'Tournament request could not be completed.'));
            }
            $registration = $rows[0];

            if ($this->registeredCount($db, (string)$tournament['tournament_id']) >= (int)$tournament['capacity']) {
                throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.registration.roster_fixed', 'Tournament request could not be completed.'));
            }

            $attempt = (int)$registration['attempt_no'];
            $this->ledger->releaseReservation([
                'operation_key'=>$this->operationKey('leave', (string)$tournament['tournament_id'], $mgwId, $attempt),
                'reservation_id'=>(string)$registration['reservation_id'],
                'metadata'=>[
                    'tournament_id'=>(string)$tournament['tournament_id'],
                    'registration_id'=>(string)$registration['registration_id'],
                    'attempt_no'=>$attempt,
                    'reason'=>'participant_left_before_full',
                ],
            ]);

            $updated = $db->execute(
                'UPDATE mgw_tournament_registrations
                 SET registration_state=:state,
                     withdrawn_at_utc=:withdrawn_at,
                     updated_at_utc=:updated_at
                 WHERE tournament_id=:tournament_id
                   AND mgw_id=:mgw_id
                   AND registration_state=:expected_state',
                [
                    'state'=>self::REGISTRATION_WITHDRAWN,
                    'withdrawn_at'=>$withdrawnAt,
                    'updated_at'=>$withdrawnAt,
                    'tournament_id'=>$tournament['tournament_id'],
                    'mgw_id'=>$mgwId,
                    'expected_state'=>self::REGISTRATION_REGISTERED,
                ]
            );
            if ($updated !== 1) throw new RuntimeException('Tournament registration changed concurrently.');

            return $this->snapshotForRow($db, $tournament, $mgwId, $accountRef);
        });
    }

    public static function canonicalRewardSnapshot(): array
    {
        return [
            'version'=>'mvp21-tournament-rewards-v1',
            'entry'=>[
                'asset_code'=>self::ENTRY_ASSET,
                'amount'=>self::ENTRY_FEE,
                'registration_semantics'=>'reserved_not_spent',
            ],
            'placements'=>[
                '1'=>[
                    'total'=>200000,
                    'entry_return'=>50000,
                    'prize'=>150000,
                    'golden_ticket'=>true,
                    'champion_crown_days'=>30,
                    'permanent_winner_badge'=>true,
                    'exclusive_champion_cosmetics'=>true,
                    'hall_of_fame'=>true,
                    'permanent_cup'=>'gold',
                ],
                '2'=>[
                    'total'=>80000,
                    'entry_return'=>50000,
                    'prize'=>30000,
                    'silver_frame_days'=>30,
                    'permanent_finalist_result'=>true,
                    'permanent_cup'=>'silver',
                ],
                '3'=>[
                    'total'=>50000,
                    'entry_return'=>50000,
                    'prize'=>0,
                    'bronze_mark_days'=>30,
                    'permanent_third_place_result'=>true,
                    'permanent_cup'=>'bronze',
                ],
                'other'=>[
                    'entry_return'=>0,
                    'refund_only_on_cancellation_or_emergency'=>true,
                ],
            ],
            'golden_ticket'=>[
                'sellable'=>false,
                'transferable'=>false,
                'valid_until_big_tournament'=>true,
                'big_tournament_fixed_capacity'=>false,
                'big_tournament_fixed_date'=>false,
                'repeat_championship_increments'=>'championship_count',
            ],
        ];
    }

    public static function canonicalRulesSnapshot(string $gameType, int $capacity): array
    {
        $gameType = trim($gameType);
        $gameTitle = match ($gameType) {
            'tictactoe' => ServerLocalization::copy('games.tictactoe.name', 'Tic Tac Toe'),
            'four_in_a_row' => ServerLocalization::copy('games.four_in_a_row.name', 'Four in a Row'),
            'battleship' => ServerLocalization::copy('games.battleship.name', 'Battleship'),
            'checkers' => ServerLocalization::copy('games.checkers.name', 'Checkers'),
            'reversi' => ServerLocalization::copy('games.reversi.name', 'Reversi'),
            'chess' => ServerLocalization::copy('games.chess.name', 'Chess'),
            'go' => ServerLocalization::copy('games.go.name', 'Go'),
            'domino' => ServerLocalization::copy('games.domino.name', 'Domino'),
            default => $gameType !== '' ? $gameType : ServerLocalization::copy('games.router.game_fallback', 'Game'),
        };

        return [
            'version'=>self::RULES_VERSION,
            'language'=>self::RULES_LANGUAGE,
            'title'=>ServerLocalization::copy('server.tournament_runtime.rules.title', 'Official tournament rules'),
            'tournament'=>[
                'game_type'=>$gameType,
                'game_title'=>$gameTitle,
                'capacity'=>$capacity,
                'entry_fee'=>self::ENTRY_FEE,
                'entry_asset_code'=>self::ENTRY_ASSET,
            ],
            'sections'=>[
                [
                    'id'=>'registration',
                    'title'=>ServerLocalization::copy('server.tournament_runtime.rules.registration_title', 'Registration and entry fee'),
                    'items'=>[
                        ServerLocalization::copy('server.tournament_runtime.rules.registration_game_capacity', 'The tournament is played in {game}. Participants: {capacity}.', ['game'=>$gameTitle, 'capacity'=>$capacity]),
                        ServerLocalization::copy('server.tournament_runtime.rules.registration_fee', 'The 50,000 coin entry fee is reserved at registration, not charged.'),
                        ServerLocalization::copy('server.tournament_runtime.rules.registration_cancel', 'Before the tournament fills, registration can be cancelled and the reserve is released in full.'),
                        ServerLocalization::copy('server.tournament_runtime.rules.registration_close', 'When all places are filled, registration closes automatically and the roster is fixed pending scheduling.'),
                    ],
                ],
                [
                    'id'=>'schedule',
                    'title'=>ServerLocalization::copy('server.tournament_runtime.rules.date_title', 'Date and participation'),
                    'items'=>[
                        ServerLocalization::copy('server.tournament_runtime.rules.date_schedule', 'The tournament date and time are scheduled after the roster is filled.'),
                        ServerLocalization::copy('server.tournament_runtime.rules.date_reminders', 'Participants receive reminders one day, one hour, and 15 minutes before the start.'),
                        ServerLocalization::copy('server.tournament_runtime.rules.date_hall', 'The Tournament Hall opens 15 minutes before the start. The bracket is randomized exactly at start time.'),
                        ServerLocalization::copy('server.tournament_runtime.rules.date_absent', 'An absent participant remains in the bracket and receives a technical loss under tournament rules.'),
                    ],
                ],
                [
                    'id'=>'start',
                    'title'=>ServerLocalization::copy('server.tournament_runtime.rules.ready_title', 'Readiness and match start'),
                    'items'=>[
                        ServerLocalization::copy('server.tournament_runtime.rules.ready_window', 'There are 2 minutes before the first match to confirm readiness.'),
                        ServerLocalization::copy('server.tournament_runtime.rules.ready_countdown', 'After both players are ready, the field remains locked through the shared 10-second visual, sound, and vibration countdown.'),
                        ServerLocalization::copy('server.tournament_runtime.rules.ready_timer', 'The game timer starts only after this countdown ends.'),
                    ],
                ],
                [
                    'id'=>'rounds',
                    'title'=>ServerLocalization::copy('server.tournament_runtime.rules.rounds_title', 'Rounds and draws'),
                    'items'=>[
                        ServerLocalization::copy('server.tournament_runtime.rules.rounds_next', 'The next round begins after all matches in the current round are complete.'),
                        ServerLocalization::copy('server.tournament_runtime.rules.rounds_break', 'There is a 3-minute break between rounds.'),
                        ServerLocalization::copy('server.tournament_runtime.rules.rounds_draw', 'After a draw, a replay starts in 1 minute, sides switch, and no additional entry fee is reserved.'),
                        ServerLocalization::copy('server.tournament_runtime.rules.rounds_final_third', 'The tournament includes a final and a separate third-place match.'),
                    ],
                ],
                [
                    'id'=>'technical',
                    'title'=>ServerLocalization::copy('server.tournament_runtime.rules.technical_title', 'Disconnects and technical outcomes'),
                    'items'=>[
                        ServerLocalization::copy('server.tournament_runtime.rules.technical_single_disconnect', 'If one player disconnects, they have 60 seconds to return.'),
                        ServerLocalization::copy('server.tournament_runtime.rules.technical_both_disconnect', 'If both players disconnect, they have up to 3 minutes to return.'),
                        ServerLocalization::copy('server.tournament_runtime.rules.technical_outcome', 'If a player leaves, both players are absent, or a technical error occurs, the result is determined by tournament rules.'),
                        ServerLocalization::copy('server.tournament_runtime.rules.technical_cancel_refund', 'If the tournament is cancelled or stopped for a technical problem, entry fees are fully refunded and results are annulled.'),
                    ],
                ],
                [
                    'id'=>'rewards',
                    'title'=>ServerLocalization::copy('server.tournament_runtime.rules.rewards_title', 'Rewards'),
                    'items'=>[
                        ServerLocalization::copy('server.tournament_runtime.rules.rewards_first', 'First place rewards'),
                        ServerLocalization::copy('server.tournament_runtime.rules.rewards_second', 'Second place rewards'),
                        ServerLocalization::copy('server.tournament_runtime.rules.rewards_third', 'Third place rewards'),
                        ServerLocalization::copy('server.tournament_runtime.rules.rewards_others', 'No cash reward is provided for other participants.'),
                        ServerLocalization::copy('server.tournament_runtime.rules.rewards_golden_ticket', 'Golden Ticket terms'),
                    ],
                ],
                [
                    'id'=>'rule_changes',
                    'title'=>ServerLocalization::copy('server.tournament_runtime.rules.change_title', 'Rule changes'),
                    'items'=>[
                        ServerLocalization::copy('server.tournament_runtime.rules.change_notice', 'After registration opens, material rule changes require cancelling this tournament and creating a new one.'),
                    ],
                ],
            ],
        ];
    }

    public static function canonicalRulesSha256(string $gameType, int $capacity): string
    {
        $json = json_encode(
            self::canonicalRulesSnapshot($gameType, $capacity),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        return self::rulesSha256FromJson($json);
    }

    private static function rulesSha256FromJson(string $json): string
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $canonical = self::canonicalizeJsonValue($decoded);
        $encoded = json_encode(
            $canonical,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        return hash('sha256', $encoded);
    }

    private static function canonicalizeJsonValue(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key=>$item) {
            $value[$key] = self::canonicalizeJsonValue($item);
        }
        return $value;
    }

    private function snapshotForRow(
        DatabaseConnectionInterface $db,
        array $row,
        ?string $mgwId,
        ?string $accountRef
    ): array {
        $registeredCount = $this->publishedRegisteredCount($db, (string)$row['tournament_id']);
        $registration = null;
        if ($mgwId !== null && trim($mgwId) !== '') {
            $rows = $db->fetchAll(
                'SELECT registration_id,tournament_id,mgw_id,account_ref,attempt_no,
                        registration_state,reservation_id,
                        rules_version,rules_language,rules_sha256,rules_accepted_at_utc,
                        registered_at_utc,withdrawn_at_utc,updated_at_utc,published_at_utc
                 FROM mgw_tournament_registrations
                 WHERE tournament_id=:tournament_id AND mgw_id=:mgw_id
                 ORDER BY attempt_no DESC LIMIT 1',
                ['tournament_id'=>$row['tournament_id'],'mgw_id'=>$mgwId]
            );
            if ($rows !== [] && is_array($rows[0])) $registration = $this->publicRegistration($rows[0]);
        }

        return [
            'tournament'=>$this->publicTournament($row, $registeredCount),
            'registration'=>$registration,
            'balance'=>$accountRef === null ? null : $this->publicBalance($accountRef),
        ];
    }

    private function publicTournament(array $row, int $registeredCount): array
    {
        $capacity = (int)$row['capacity'];
        return [
            'tournament_id'=>(string)$row['tournament_id'],
            'title'=>(string)$row['title'],
            'game_type'=>(string)$row['game_type'],
            'capacity'=>$capacity,
            'registered_count'=>$registeredCount,
            'remaining_count'=>max(0, $capacity - $registeredCount),
            'is_full'=>$registeredCount >= $capacity,
            'entry_fee'=>[
                'asset_code'=>(string)$row['entry_asset_code'],
                'amount'=>(int)$row['entry_fee_amount'],
            ],
            'reward_snapshot'=>$this->decodeJson((string)$row['reward_snapshot_json']),
            'rules'=>[
                'version'=>(string)($row['rules_version'] ?? ''),
                'language'=>(string)($row['rules_language'] ?? ''),
                'sha256'=>(string)($row['rules_sha256'] ?? ''),
                'snapshot'=>$this->decodeJson((string)($row['rules_snapshot_json'] ?? '')),
            ],
            'state'=>(string)$row['tournament_state'],
            'waiting_for_date'=>(string)$row['tournament_state'] === self::STATE_WAITING_FOR_DATE,
            'scheduled'=>(string)$row['tournament_state'] === self::STATE_SCHEDULED,
            'scheduled_start_at_utc'=>$this->nullableText($row['scheduled_start_at_utc'] ?? null, 32),
            'scheduled_by_ref'=>$this->nullableText($row['scheduled_by_ref'] ?? null, 191),
            'scheduled_at_utc'=>$this->nullableText($row['scheduled_at_utc'] ?? null, 32),
            'created_at_utc'=>(string)$row['created_at_utc'],
            'registration_opened_at_utc'=>$this->nullableText($row['registration_opened_at_utc'] ?? null, 32),
            'registration_closed_at_utc'=>$this->nullableText($row['registration_closed_at_utc'] ?? null, 32),
            'registration_closed_reason'=>$this->nullableText($row['registration_closed_reason'] ?? null, 32),
            'updated_at_utc'=>(string)$row['updated_at_utc'],
        ];
    }

    private function publicRegistration(array $row): array
    {
        return [
            'registration_id'=>(string)$row['registration_id'],
            'state'=>(string)$row['registration_state'],
            'attempt_no'=>(int)$row['attempt_no'],
            'reservation_id'=>(string)$row['reservation_id'],
            'rules_consent'=>[
                'accepted'=>trim((string)($row['rules_accepted_at_utc'] ?? '')) !== '',
                'version'=>$this->nullableText($row['rules_version'] ?? null, 64),
                'language'=>$this->nullableText($row['rules_language'] ?? null, 12),
                'sha256'=>$this->nullableText($row['rules_sha256'] ?? null, 64),
                'accepted_at_utc'=>$this->nullableText($row['rules_accepted_at_utc'] ?? null, 32),
            ],
            'registered_at_utc'=>(string)$row['registered_at_utc'],
            'withdrawn_at_utc'=>$this->nullableText($row['withdrawn_at_utc'] ?? null, 32),
            'updated_at_utc'=>(string)$row['updated_at_utc'],
            'published'=>trim((string)($row['published_at_utc'] ?? '')) !== '',
            'published_at_utc'=>$this->nullableText($row['published_at_utc'] ?? null, 32),
        ];
    }

    private function publicBalance(string $accountRef): array
    {
        $balance = $this->ledger->getBalance($accountRef, self::ENTRY_ASSET);
        if ($balance === null) {
            return [
                'asset_code'=>self::ENTRY_ASSET,
                'available_amount'=>0,
                'reserved_amount'=>0,
            ];
        }
        return [
            'asset_code'=>(string)$balance['asset_code'],
            'available_amount'=>(int)$balance['available_amount'],
            'reserved_amount'=>(int)$balance['reserved_amount'],
        ];
    }

    private function assertTournamentRules(array $tournament): void
    {
        $version = trim((string)($tournament['rules_version'] ?? ''));
        $language = trim((string)($tournament['rules_language'] ?? ''));
        $json = trim((string)($tournament['rules_snapshot_json'] ?? ''));
        $sha256 = trim((string)($tournament['rules_sha256'] ?? ''));
        if ($version === '' || $language === '' || $json === '' || $sha256 === '') {
            throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.rules.not_prepared', 'Tournament request could not be completed.'));
        }
        if (!hash_equals(self::rulesSha256FromJson($json), $sha256)) {
            throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.rules.snapshot_damaged', 'Tournament request could not be completed.'));
        }
        $snapshot = $this->decodeJson($json);
        if ((string)($snapshot['version'] ?? '') !== $version
            || (string)($snapshot['language'] ?? '') !== $language) {
            throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.rules.version_mismatch', 'Tournament request could not be completed.'));
        }
    }

    private function validatedRulesConsent(array $tournament, ?array $consent): array
    {
        $this->assertTournamentRules($tournament);
        if (!is_array($consent) || empty($consent['accepted'])) {
            throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.rules.consent_required', 'Tournament request could not be completed.'));
        }

        $expected = [
            'version'=>(string)$tournament['rules_version'],
            'language'=>(string)$tournament['rules_language'],
            'sha256'=>(string)$tournament['rules_sha256'],
        ];
        $actual = [
            'version'=>trim((string)($consent['version'] ?? '')),
            'language'=>trim((string)($consent['language'] ?? '')),
            'sha256'=>trim((string)($consent['sha256'] ?? '')),
        ];
        foreach ($expected as $key=>$value) {
            if ($actual[$key] === '' || !hash_equals($value, $actual[$key])) {
                throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.rules.updated', 'Tournament request could not be completed.'));
            }
        }
        return $expected;
    }

    private function closeRegistrationIfReady(
        DatabaseConnectionInterface $db,
        array $tournament,
        string $closedAt
    ): array {
        if ((string)$tournament['tournament_state'] !== self::STATE_REGISTRATION_OPEN) {
            return [$tournament, false];
        }

        $tournamentId = (string)$tournament['tournament_id'];
        $registeredCount = $this->publishedRegisteredCount($db, $tournamentId);
        if ($registeredCount < (int)$tournament['capacity']) {
            return [$tournament, false];
        }

        $acceptedCount = (int)$db->fetchValue(
            'SELECT COUNT(*) FROM mgw_tournament_registrations
             WHERE tournament_id=:tournament_id
               AND registration_state=:state
               AND rules_accepted_at_utc IS NOT NULL',
            ['tournament_id'=>$tournamentId,'state'=>self::REGISTRATION_REGISTERED]
        );
        if ($acceptedCount !== $registeredCount) {
            return [$tournament, false];
        }

        $updated = $db->execute(
            'UPDATE mgw_tournaments
             SET tournament_state=:state,
                 registration_closed_at_utc=:closed_at,
                 registration_closed_reason=:reason,
                 updated_at_utc=:updated_at
             WHERE tournament_id=:tournament_id
               AND tournament_state=:expected_state',
            [
                'state'=>self::STATE_WAITING_FOR_DATE,
                'closed_at'=>$closedAt,
                'reason'=>'full',
                'updated_at'=>$closedAt,
                'tournament_id'=>$tournamentId,
                'expected_state'=>self::STATE_REGISTRATION_OPEN,
            ]
        );
        if ($updated !== 1) {
            throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.registration.state_race', 'Tournament request could not be completed.'));
        }
        return [$this->tournamentRow($db, $tournamentId, false), true];
    }

    private function activeTournamentRow(DatabaseConnectionInterface $db, bool $lock): array
    {
        $rows = $db->fetchAll(
            'SELECT * FROM mgw_tournaments
             WHERE active_slot=:active_slot' . ($lock ? $this->forUpdate($db) : ''),
            ['active_slot'=>self::ACTIVE_SLOT]
        );
        if ($rows === []) throw new RuntimeException(ServerLocalization::copy('server.tournament_runtime.registration.tournament_missing', 'Tournament request could not be completed.'));
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Official tournament state is invalid.');
        }
        return $rows[0];
    }

    private function tournamentRow(DatabaseConnectionInterface $db, string $tournamentId, bool $lock): array
    {
        $rows = $db->fetchAll(
            'SELECT * FROM mgw_tournaments
             WHERE tournament_id=:tournament_id' . ($lock ? $this->forUpdate($db) : ''),
            ['tournament_id'=>$tournamentId]
        );
        if ($rows === []) throw new RuntimeException('Tournament was not found.');
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Tournament state is invalid.');
        }
        return $rows[0];
    }

    private function registeredCount(DatabaseConnectionInterface $db, string $tournamentId): int
    {
        return (int)$db->fetchValue(
            'SELECT COUNT(*) FROM mgw_tournament_registrations
             WHERE tournament_id=:tournament_id AND registration_state=:state',
            ['tournament_id'=>$tournamentId,'state'=>self::REGISTRATION_REGISTERED]
        );
    }

    private function publishedRegisteredCount(DatabaseConnectionInterface $db, string $tournamentId): int
    {
        return (int)$db->fetchValue(
            'SELECT COUNT(*) FROM mgw_tournament_registrations
             WHERE tournament_id=:tournament_id
               AND registration_state=:state
               AND published_at_utc IS NOT NULL',
            ['tournament_id'=>$tournamentId,'state'=>self::REGISTRATION_REGISTERED]
        );
    }

    private function ledgerIdentity(string $accountRef, string $mgwId): array
    {
        if (str_starts_with($accountRef, 'legacy:')) {
            return [
                'account_ref'=>$accountRef,
                'mgw_id'=>$mgwId,
                'legacy_user_id'=>substr($accountRef, strlen('legacy:')),
            ];
        }
        if (str_starts_with($accountRef, 'mgw:')) {
            return [
                'account_ref'=>$accountRef,
                'mgw_id'=>$mgwId,
                'legacy_user_id'=>null,
            ];
        }
        throw new InvalidArgumentException('Canonical tournament account_ref is required.');
    }

    private function operationKey(string $action, string $tournamentId, string $mgwId, int $attempt): string
    {
        return 'tournament:' . $tournamentId . ':' . $action . ':' . $mgwId . ':' . $attempt;
    }

    private function registrationId(string $tournamentId, string $mgwId, int $attempt): string
    {
        return 'treg_' . substr(hash('sha256', $tournamentId . '|' . $mgwId . '|' . $attempt), 0, 48);
    }

    private function newTournamentId(string $actorRef, string $createdAt): string
    {
        return 'tour_' . substr(hash('sha256', $createdAt . '|' . $actorRef . '|' . bin2hex(random_bytes(16))), 0, 40);
    }

    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decodeJson(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function utc(?DateTimeImmutable $now): string
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }

    private function forUpdate(DatabaseConnectionInterface $db): string
    {
        return $db->driver() === 'sqlite' ? '' : ' FOR UPDATE';
    }

    private function token(string $value, int $max, string $label): string
    {
        $value = strtolower($this->text($value, $max));
        if ($value === '' || preg_match('/^[a-z][a-z0-9_]{0,' . ($max - 1) . '}$/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid tournament ' . $label . '.');
        }
        return $value;
    }

    private function requiredText(string $value, int $max, string $label): string
    {
        $value = $this->text($value, $max);
        if ($value === '') throw new InvalidArgumentException('Tournament ' . $label . ' is required.');
        return $value;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $value = $this->text((string)($value ?? ''), $max);
        return $value === '' ? null : $value;
    }

    private function text(string $value, int $max): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
}
