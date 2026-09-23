<?php
declare(strict_types=1);

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
        if ($title === '') $title = 'Официальный турнир';
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
                throw new RuntimeException('Одновременно может существовать только один официальный турнир.');
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
            throw new InvalidArgumentException('Укажите дату и время начала турнира.');
        }

        $utc = new DateTimeZone('UTC');
        $assignedMoment = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        try {
            $startMoment = (new DateTimeImmutable($startAtUtc, $utc))->setTimezone($utc);
        } catch (Throwable) {
            throw new InvalidArgumentException('Некорректная дата или время начала турнира.');
        }
        if ($startMoment <= $assignedMoment) {
            throw new InvalidArgumentException('Дата начала турнира должна быть в будущем.');
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
                throw new RuntimeException('Дата турнира уже назначена. Перенос или задержка не входят в MVP-21.3.');
            }
            if ($state !== self::STATE_WAITING_FOR_DATE) {
                throw new RuntimeException('Назначить дату можно только после полного набора состава.');
            }

            $registeredCount = $this->registeredCount($db, $tournamentId);
            if ($registeredCount !== (int)$row['capacity']) {
                throw new RuntimeException('Дата турнира назначается только для полностью набранного состава.');
            }
            $acceptedCount = (int)$db->fetchValue(
                'SELECT COUNT(*) FROM mgw_tournament_registrations
                 WHERE tournament_id=:tournament_id
                   AND registration_state=:state
                   AND rules_accepted_at_utc IS NOT NULL',
                ['tournament_id'=>$tournamentId,'state'=>self::REGISTRATION_REGISTERED]
            );
            if ($acceptedCount !== $registeredCount) {
                throw new RuntimeException('Не у всех участников зафиксировано согласие с правилами турнира.');
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
                throw new RuntimeException('Дата турнира изменилась одновременно с запросом.');
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
                throw new RuntimeException('Регистрация на официальный турнир уже закрыта. Состав набран.');
            }
            if ((string)$tournament['tournament_state'] !== self::STATE_REGISTRATION_OPEN) {
                throw new RuntimeException('Регистрация на официальный турнир сейчас закрыта.');
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
                        throw new RuntimeException('Согласие с правилами изменилось одновременно. Повторите попытку.');
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
                throw new RuntimeException('Все места в турнире уже заняты.');
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
                throw new RuntimeException('Активная регистрация для публикации не найдена.');
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
                    throw new RuntimeException('Публикация регистрации изменилась одновременно. Повторите попытку.');
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
                throw new RuntimeException('Из текущего состояния турнира выйти через регистрацию нельзя.');
            }

            $rows = $db->fetchAll(
                'SELECT * FROM mgw_tournament_registrations
                 WHERE tournament_id=:tournament_id AND mgw_id=:mgw_id
                 ORDER BY attempt_no DESC LIMIT 1' . $this->forUpdate($db),
                ['tournament_id'=>$tournament['tournament_id'],'mgw_id'=>$mgwId]
            );
            if ($rows === [] || !is_array($rows[0])
                || (string)$rows[0]['registration_state'] !== self::REGISTRATION_REGISTERED) {
                throw new RuntimeException('Активная регистрация не найдена.');
            }
            $registration = $rows[0];

            if ($this->registeredCount($db, (string)$tournament['tournament_id']) >= (int)$tournament['capacity']) {
                throw new RuntimeException('После заполнения турнира регистрация зафиксирована и выйти через этот этап уже нельзя.');
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
            'tictactoe' => 'Крестики-нолики',
            'four_in_a_row' => 'Четыре в ряд',
            'battleship' => 'Морской бой',
            'checkers' => 'Русские шашки',
            'reversi' => 'Реверси',
            'chess' => 'Шахматы',
            'go' => 'Го',
            'domino' => 'Домино',
            default => $gameType !== '' ? $gameType : 'Игра',
        };

        return [
            'version'=>self::RULES_VERSION,
            'language'=>self::RULES_LANGUAGE,
            'title'=>'Правила официального турнира',
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
                    'title'=>'Регистрация и взнос',
                    'items'=>[
                        "Турнир проходит по игре «{$gameTitle}». Количество участников: {$capacity}.",
                        'Взнос — 50 000 коинов. При регистрации сумма резервируется, а не списывается.',
                        'До заполнения турнира участник может отменить регистрацию: место освобождается, резерв 50 000 полностью снимается.',
                        'Когда все места заняты, регистрация закрывается автоматически, состав фиксируется и ожидает назначения даты.',
                    ],
                ],
                [
                    'id'=>'schedule',
                    'title'=>'Дата и участие',
                    'items'=>[
                        'После набора состава назначается дата и время турнира.',
                        'Участникам предусмотрены напоминания за день, за час и за 15 минут до начала.',
                        'Турнирный зал открывается за 15 минут до старта. Сетка формируется случайно точно в момент начала.',
                        'Отсутствующий участник остаётся в сетке и получает техническое поражение по турнирным правилам.',
                    ],
                ],
                [
                    'id'=>'start',
                    'title'=>'Готовность и старт матча',
                    'items'=>[
                        'Перед первым матчем даётся 2 минуты на подтверждение «Я готов».',
                        'После готовности обоих игроков поле блокируется до общего 10-секундного визуального, звукового и вибрационного отсчёта.',
                        'Игровой таймер начинается только после окончания этого отсчёта.',
                    ],
                ],
                [
                    'id'=>'rounds',
                    'title'=>'Раунды и ничьи',
                    'items'=>[
                        'Следующий раунд начинается после завершения всех матчей текущего раунда.',
                        'Между раундами предусмотрен перерыв 3 минуты.',
                        'При ничьей повторный матч начинается через 1 минуту, стороны меняются, повторный взнос не резервируется.',
                        'Турнир включает финал и отдельный матч за третье место.',
                    ],
                ],
                [
                    'id'=>'technical',
                    'title'=>'Отключения и технические исходы',
                    'items'=>[
                        'Если один игрок отключился, у него есть 60 секунд, чтобы вернуться в игру.',
                        'Если отключились оба игрока, им даётся до 3 минут, чтобы вернуться в игру.',
                        'Если игрок выходит сам, оба игрока не появляются или возникает техническая ошибка, результат определяется по правилам турнира.',
                        'Если турнир отменён или остановлен из-за технической проблемы, взнос участникам возвращается полностью, а результаты аннулируются.',
                    ],
                ],
                [
                    'id'=>'rewards',
                    'title'=>'Награды',
                    'items'=>[
                        '1 место: 200 000 коинов; Golden Ticket; корона чемпиона на 30 дней; постоянный значок победителя; эксклюзивный чемпионский набор оформления игр; Зал славы; золотой кубок.',
                        '2 место: 80 000 коинов; серебряная рамка на 30 дней; постоянная отметка финалиста; серебряный кубок.',
                        '3 место: 50 000 коинов; бронзовая отметка на 30 дней; постоянная отметка за третье место; бронзовый кубок.',
                        'Для остальных участников денежная награда не предусмотрена.',
                        'Golden Ticket нельзя продать или передать другому игроку. Он даёт право участия в будущем Большом турнире и действует до его проведения. Дата и число участников Большого турнира будут определены позже.',
                    ],
                ],
                [
                    'id'=>'rule_changes',
                    'title'=>'Изменение правил',
                    'items'=>[
                        'После открытия регистрации условия этого турнира не меняются незаметно для участников. Если правила потребуется существенно изменить, текущий турнир будет отменён и создан новый.',
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
            throw new RuntimeException('Правила турнира ещё не подготовлены.');
        }
        if (!hash_equals(self::rulesSha256FromJson($json), $sha256)) {
            throw new RuntimeException('Снимок правил турнира повреждён.');
        }
        $snapshot = $this->decodeJson($json);
        if ((string)($snapshot['version'] ?? '') !== $version
            || (string)($snapshot['language'] ?? '') !== $language) {
            throw new RuntimeException('Версия правил турнира не совпадает со снимком.');
        }
    }

    private function validatedRulesConsent(array $tournament, ?array $consent): array
    {
        $this->assertTournamentRules($tournament);
        if (!is_array($consent) || empty($consent['accepted'])) {
            throw new RuntimeException('Перед регистрацией подтвердите согласие с правилами турнира.');
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
                throw new RuntimeException('Правила турнира обновились. Откройте их заново и подтвердите актуальную версию.');
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
            throw new RuntimeException('Состояние регистрации изменилось одновременно.');
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
        if ($rows === []) throw new RuntimeException('Официальный турнир пока не создан.');
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
