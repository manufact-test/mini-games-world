<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/UserService.php';
require_once __DIR__ . '/../accounts/MgwIdGenerator.php';
require_once __DIR__ . '/../accounts/RuntimeAccountOwnershipService.php';
require_once __DIR__ . '/TournamentRoundProgressionService.php';

/** Staging-only fixture that stops one seat before full so the final transition stays manual. */
final class StagingTournamentManualAcceptanceService
{
    private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';

    private $runtimeUserWriter;
    private $runtimeResetWriter;

    public function __construct(
        private array $config,
        private DatabaseConnectionInterface $database,
        private LedgerWriteService $ledger,
        private TournamentRegistrationService $tournaments,
        ?callable $runtimeUserWriter = null,
        ?callable $runtimeResetWriter = null
    ) {
        $this->runtimeUserWriter = $runtimeUserWriter;
        $this->runtimeResetWriter = $runtimeResetWriter;
    }

    public function availability(array $server): array
    {
        if (!$this->isAvailableEnvironment($server)) {
            return [
                'available'=>false,
                'reason'=>'staging_only',
                'target_registered_count'=>null,
                'modes'=>[],
            ];
        }

        $snapshot = $this->tournaments->snapshot();
        $tournament = $snapshot['tournament'] ?? null;
        if (!is_array($tournament)) {
            return [
                'available'=>false,
                'reason'=>'no_active_tournament',
                'target_registered_count'=>null,
                'modes'=>[],
            ];
        }

        $capacity = (int)($tournament['capacity'] ?? 0);
        $registered = (int)($tournament['registered_count'] ?? 0);
        $state = (string)($tournament['state'] ?? '');
        $modes = [];
        foreach ([1, 2] as $liveSeats) {
            $target = max(0, $capacity - $liveSeats);
            $modes[(string)$liveSeats] = [
                'live_seats'=>$liveSeats,
                'target_registered_count'=>$target,
                'registered_count'=>$registered,
                'capacity'=>$capacity,
                'available'=>$state === TournamentRegistrationService::STATE_REGISTRATION_OPEN
                    && $capacity > $liveSeats
                    && $registered < $target,
                'ready'=>$state === TournamentRegistrationService::STATE_REGISTRATION_OPEN
                    && $registered === $target,
                'remaining_fixture_slots'=>max(0, $target - $registered),
            ];
        }

        $oneLive = $modes['1'];
        return [
            'available'=>$oneLive['available'],
            'reason'=>$state !== TournamentRegistrationService::STATE_REGISTRATION_OPEN
                ? 'registration_not_open'
                : ($registered >= (int)$oneLive['target_registered_count'] ? 'manual_last_seat_ready' : 'ready'),
            'target_registered_count'=>$oneLive['target_registered_count'],
            'registered_count'=>$registered,
            'capacity'=>$capacity,
            'remaining_fixture_slots'=>$oneLive['remaining_fixture_slots'],
            'modes'=>$modes,
        ];
    }

    public function fillToOneManualSeat(array $server): array
    {
        $requested = (int)($server['HTTP_X_MGW_MANUAL_LIVE_SEATS'] ?? 1);
        if (!in_array($requested, [1, 2], true)) $requested = 1;
        return $this->fillToManualSeats($server, $requested);
    }

    public function fillToManualSeats(array $server, int $manualSeats): array
    {
        $this->assertAvailableEnvironment($server);
        if (!in_array($manualSeats, [1, 2], true)) {
            throw new InvalidArgumentException('Для ручной проверки поддерживается одно или два живых места.');
        }

        $repair = $this->repairLegacyFixtureOwnership($server);

        $snapshot = $this->tournaments->snapshot();
        $tournament = $snapshot['tournament'] ?? null;
        if (!is_array($tournament)) {
            throw new RuntimeException('Для ручной проверки сначала нужен активный официальный турнир.');
        }
        if ((string)($tournament['state'] ?? '') !== TournamentRegistrationService::STATE_REGISTRATION_OPEN) {
            throw new RuntimeException('Тестовое заполнение доступно только пока регистрация открыта.');
        }

        $capacity = (int)($tournament['capacity'] ?? 0);
        $registered = (int)($tournament['registered_count'] ?? 0);
        if ($capacity <= $manualSeats) {
            throw new RuntimeException('Некорректная вместимость турнира для ручной проверки.');
        }

        $target = $capacity - $manualSeats;
        if ($registered > $target) {
            throw new RuntimeException(
                'Для выбранного режима уже зарегистрировано слишком много участников. '
                . 'Сбросьте staging-турнир и подготовьте его заново.'
            );
        }
        if ($registered === $target) {
            return [
                'status'=>'already_ready',
                'created_count'=>0,
                'registered_count'=>$registered,
                'capacity'=>$capacity,
                'target_registered_count'=>$target,
                'manual_seats_left'=>$manualSeats,
                'requested_live_seats'=>$manualSeats,
                'snapshot'=>$snapshot,
            ];
        }

        $rules = $tournament['rules'] ?? null;
        if (!is_array($rules)) {
            throw new RuntimeException('Снимок правил турнира недоступен.');
        }
        $consent = [
            'accepted'=>true,
            'version'=>(string)($rules['version'] ?? ''),
            'language'=>(string)($rules['language'] ?? ''),
            'sha256'=>(string)($rules['sha256'] ?? ''),
        ];
        if ($consent['version'] === '' || $consent['language'] === '' || $consent['sha256'] === '') {
            throw new RuntimeException('Снимок правил турнира неполный.');
        }

        $tournamentId = (string)($tournament['tournament_id'] ?? '');
        if ($tournamentId === '') {
            throw new RuntimeException('Идентификатор турнира недоступен.');
        }

        $registeredIds = array_fill_keys(
            $this->tournaments->registeredParticipantMgwIds($tournamentId),
            true
        );
        $needed = $target - $registered;
        $created = [];
        $runtimeBatch = [];

        for ($slot = 1; count($created) < $needed && $slot <= 256; $slot++) {
            $identity = $this->fixtureIdentity($tournamentId, $slot);
            if (isset($registeredIds[$identity['mgw_id']])) continue;

            $this->ensureCanonicalUser($identity);
            $this->ensureCanonicalOwnership($identity);
            $this->ensureEntryBalance($identity, $tournamentId, $slot);
            $registration = $this->tournaments->register(
                $identity['mgw_id'],
                $identity['account_ref'],
                null,
                $consent
            );
            $runtimeBatch[] = ['identity'=>$identity,'slot'=>$slot];

            $registeredIds[$identity['mgw_id']] = true;
            $created[] = [
                'mgw_id'=>$identity['mgw_id'],
                'legacy_user_id'=>$identity['legacy_user_id'],
                'registration_id'=>(string)($registration['registration']['registration_id'] ?? ''),
            ];
        }

        $this->ensureRuntimeUsers($runtimeBatch);
        $runtimeParity = $this->repairFixtureRuntimeParity($server);

        $final = $this->tournaments->snapshot();
        $finalTournament = $final['tournament'] ?? null;
        $finalRegistered = is_array($finalTournament)
            ? (int)($finalTournament['registered_count'] ?? -1)
            : -1;
        $finalState = is_array($finalTournament)
            ? (string)($finalTournament['state'] ?? '')
            : '';

        if ($finalRegistered !== $target
            || $finalState !== TournamentRegistrationService::STATE_REGISTRATION_OPEN) {
            throw new RuntimeException('Не удалось безопасно подготовить турнир с нужным числом живых мест.');
        }

        return [
            'status'=>'prepared',
            'created_count'=>count($created),
            'created_participants'=>$created,
            'registered_count'=>$finalRegistered,
            'capacity'=>$capacity,
            'target_registered_count'=>$target,
            'manual_seats_left'=>$manualSeats,
            'requested_live_seats'=>$manualSeats,
            'legacy_ownership_repair'=>$repair,
            'runtime_fixture_parity'=>$runtimeParity,
            'snapshot'=>$final,
        ];
    }

    public function repairLegacyFixtureOwnership(array $server): array
    {
        $this->assertAvailableEnvironment($server);

        $snapshot = $this->tournaments->snapshot();
        $tournament = $snapshot['tournament'] ?? null;
        if (!is_array($tournament)) {
            return ['repaired'=>0,'already_ok'=>0,'scanned'=>0];
        }

        $tournamentId = trim((string)($tournament['tournament_id'] ?? ''));
        if ($tournamentId === '') {
            throw new RuntimeException('Идентификатор турнира недоступен для восстановления staging fixture.');
        }

        $rows = $this->database->fetchAll(
            'SELECT r.mgw_id,r.account_ref,r.registration_state,u.status
             FROM mgw_tournament_registrations r
             INNER JOIN mgw_users u ON u.mgw_id = r.mgw_id
             WHERE r.tournament_id=:tournament_id
               AND r.registration_state=:registration_state
             ORDER BY r.registration_id ASC',
            [
                'tournament_id'=>$tournamentId,
                'registration_state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
            ]
        );

        $repaired = 0;
        $alreadyOk = 0;
        $scanned = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            $accountRef = trim((string)($row['account_ref'] ?? ''));
            $legacyUserId = '';
            $repairSourceType = '';
            $repairSourceRef = '';

            if (preg_match('/^MGW-STG-[a-f0-9]{12}$/', $mgwId) === 1
                && preg_match('/^legacy:(stg_tour_[a-f0-9]{12})$/', $accountRef, $match) === 1) {
                $legacyUserId = (string)$match[1];
                $repairSourceType = 'staging_fixture_repair';
                $repairSourceRef = 'manual-acceptance:' . $tournamentId . ':' . $legacyUserId;
            } elseif (MgwIdGenerator::isValid($mgwId)
                && preg_match('/^legacy:(stg_tour_v2_[a-f0-9]{12})$/', $accountRef, $match) === 1) {
                $legacyUserId = (string)$match[1];
                $repairSourceType = 'runtime_identity';
                $repairSourceRef = 'development:' . $legacyUserId;
            } else {
                continue;
            }

            if ((string)($row['status'] ?? '') !== 'active') {
                throw new RuntimeException('Registered staging fixture user is not active.');
            }
            $scanned++;

            $ownershipRows = $this->database->fetchAll(
                'SELECT account_ref,mgw_id,legacy_user_id,ownership_status
                 FROM mgw_account_ownership
                 WHERE account_ref=:account_ref OR mgw_id=:mgw_id OR legacy_user_id=:legacy_user_id',
                [
                    'account_ref'=>$accountRef,
                    'mgw_id'=>$mgwId,
                    'legacy_user_id'=>$legacyUserId,
                ]
            );
            if ($ownershipRows !== []) {
                if (count($ownershipRows) !== 1
                    || (string)($ownershipRows[0]['account_ref'] ?? '') !== $accountRef
                    || (string)($ownershipRows[0]['mgw_id'] ?? '') !== $mgwId
                    || (string)($ownershipRows[0]['legacy_user_id'] ?? '') !== $legacyUserId
                    || (string)($ownershipRows[0]['ownership_status'] ?? '') !== 'active') {
                    throw new RuntimeException('Legacy staging fixture ownership conflicts with existing ownership.');
                }
                $alreadyOk++;
                continue;
            }

            $balance = $this->ledger->getBalance($accountRef, TournamentRegistrationService::ENTRY_ASSET);
            if (!is_array($balance)
                || (string)($balance['mgw_id'] ?? '') !== $mgwId
                || (int)($balance['reserved_amount'] ?? -1) !== TournamentRegistrationService::ENTRY_FEE) {
                throw new RuntimeException('Legacy staging fixture balance cannot be proven safe for ownership repair.');
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->format('Y-m-d H:i:s.u');
            $this->database->execute(
                'INSERT INTO mgw_account_ownership (
                    account_ref,mgw_id,legacy_user_id,ownership_status,
                    source_type,source_ref,source_sha256,created_at_utc,verified_at_utc
                 ) VALUES (
                    :account_ref,:mgw_id,:legacy_user_id,:ownership_status,
                    :source_type,:source_ref,:source_sha256,:created_at_utc,:verified_at_utc
                 )',
                [
                    'account_ref'=>$accountRef,
                    'mgw_id'=>$mgwId,
                    'legacy_user_id'=>$legacyUserId,
                    'ownership_status'=>'active',
                    'source_type'=>$repairSourceType,
                    'source_ref'=>$repairSourceRef,
                    'source_sha256'=>hash('sha256', implode('|', [
                        $repairSourceType,
                        $tournamentId,
                        $legacyUserId,
                        $mgwId,
                        $accountRef,
                    ])),
                    'created_at_utc'=>$now,
                    'verified_at_utc'=>$now,
                ]
            );
            $repaired++;
        }

        return [
            'repaired'=>$repaired,
            'already_ok'=>$alreadyOk,
            'scanned'=>$scanned,
        ];
    }

    public function repairFixtureRuntimeParity(array $server): array
    {
        $this->assertAvailableEnvironment($server);

        $expected = [];
        $snapshot = $this->tournaments->snapshot();
        $tournament = $snapshot['tournament'] ?? null;
        if (is_array($tournament)) {
            $tournamentId = trim((string)($tournament['tournament_id'] ?? ''));
            if ($tournamentId !== '') {
                $rows = $this->database->fetchAll(
                    'SELECT r.mgw_id,r.account_ref,
                            o.legacy_user_id,o.ownership_status,o.source_type,o.source_ref,
                            u.status,u.display_name
                     FROM mgw_tournament_registrations r
                     INNER JOIN mgw_users u ON u.mgw_id=r.mgw_id
                     INNER JOIN mgw_account_ownership o
                       ON o.mgw_id=r.mgw_id AND o.account_ref=r.account_ref
                     WHERE r.tournament_id=:tournament_id
                       AND r.registration_state=:registration_state',
                    [
                        'tournament_id'=>$tournamentId,
                        'registration_state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
                    ]
                );
                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    $mgwId = trim((string)($row['mgw_id'] ?? ''));
                    $accountRef = trim((string)($row['account_ref'] ?? ''));
                    $legacyUserId = trim((string)($row['legacy_user_id'] ?? ''));
                    if ((string)($row['ownership_status'] ?? '') !== 'active'
                        || (string)($row['status'] ?? '') !== 'active'
                        || !$this->isSyntheticFixtureIdentity(
                            $mgwId,
                            $accountRef,
                            $legacyUserId,
                            (string)($row['source_type'] ?? ''),
                            (string)($row['source_ref'] ?? '')
                        )) {
                        continue;
                    }
                    $expected[$legacyUserId] = [
                        'mgw_id'=>$mgwId,
                        'legacy_user_id'=>$legacyUserId,
                        'account_ref'=>$accountRef,
                        'display_name'=>trim((string)($row['display_name'] ?? '')) ?: 'Тестовый участник',
                    ];
                }
            }
        }

        if ($this->runtimeUserWriter !== null) {
            return [
                'expected_active_fixture_users'=>count($expected),
                'runtime_fixture_users_removed'=>0,
                'runtime_fixture_users_ensured'=>count($expected),
                'external_runtime_writer'=>true,
            ];
        }

        $fixturePattern = static fn(string $legacyUserId): bool =>
            preg_match('/^stg_tour_(?:v2_)?[a-f0-9]{12}$/', $legacyUserId) === 1;

        $storage = StorageFactory::createJson((string)($this->config['data_dir'] ?? (__DIR__ . '/../data')));
        $config = $this->config;
        $database = $this->database;
        return $storage->transaction(function (array &$data) use (
            $expected,
            $fixturePattern,
            $config,
            $database
        ): array {
            if (!isset($data['users']) || !is_array($data['users'])) {
                $data['users'] = [];
            }

            $removed = 0;
            foreach (array_keys($data['users']) as $key) {
                $user = $data['users'][$key] ?? null;
                if (!is_array($user)) continue;
                $legacyUserId = trim((string)($user['id'] ?? $key));
                if (!$fixturePattern($legacyUserId) || isset($expected[$legacyUserId])) continue;
                unset($data['users'][$key]);
                $removed++;
            }

            $ensured = 0;
            if ($expected !== []) {
                $users = new UserService($config, $database);
                $slot = 1;
                foreach ($expected as $identity) {
                    $users->ensureUser($data, [
                        'id'=>$identity['legacy_user_id'],
                        'first_name'=>$identity['display_name'],
                        'username'=>'',
                        'language_code'=>'ru',
                        'is_dev_user'=>true,
                        'is_staging_test_user'=>true,
                        'staging_test_slot'=>'TOURNAMENT-' . $slot,
                        'mgw_id'=>$identity['mgw_id'],
                        'mgw_account_ref'=>$identity['account_ref'],
                        'mgw_identity_provider'=>'staging_fixture',
                        'mgw_nickname'=>'Тест ' . $slot,
                    ]);
                    $slot++;
                    $ensured++;
                }
            }

            return [
                'expected_active_fixture_users'=>count($expected),
                'runtime_fixture_users_removed'=>$removed,
                'runtime_fixture_users_ensured'=>$ensured,
            ];
        });
    }


    public function progressionAcceptanceAvailability(array $server): array
    {
        if (!$this->isAvailableEnvironment($server)) {
            return ['available'=>false,'reason'=>'staging_only','fixture_pair_count'=>0];
        }

        $snapshot = $this->tournaments->snapshot();
        $tournament = $snapshot['tournament'] ?? null;
        if (!is_array($tournament)) {
            return ['available'=>false,'reason'=>'no_active_tournament','fixture_pair_count'=>0];
        }

        $tournamentId = trim((string)($tournament['tournament_id'] ?? ''));
        if ($tournamentId === '') {
            return ['available'=>false,'reason'=>'missing_tournament_id','fixture_pair_count'=>0];
        }

        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_tournament_round_matches
             WHERE tournament_id=:tournament_id AND completed_at_utc IS NULL
             ORDER BY round_no ASC,pair_no ASC',
            ['tournament_id'=>$tournamentId]
        );
        if ($rows === []) {
            return [
                'available'=>false,
                'reason'=>'no_unresolved_pairs',
                'tournament_id'=>$tournamentId,
                'fixture_pair_count'=>0,
            ];
        }

        $roundNo = max(1, (int)($rows[0]['round_no'] ?? 1));
        $fixturePairs = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || (int)($row['round_no'] ?? 0) !== $roundNo) continue;
            if ($this->fixtureRuntimeIdentityForMgw((string)($row['player_a_mgw_id'] ?? '')) !== null
                && $this->fixtureRuntimeIdentityForMgw((string)($row['player_b_mgw_id'] ?? '')) !== null) {
                $fixturePairs++;
            }
        }

        return [
            'available'=>$fixturePairs > 0,
            'reason'=>$fixturePairs > 0 ? 'ready' : 'no_fixture_only_pairs',
            'tournament_id'=>$tournamentId,
            'round_no'=>$roundNo,
            'fixture_pair_count'=>$fixturePairs,
        ];
    }

    public function completeFixtureOnlyPairs(
        array $server,
        string $actorRef,
        ?DateTimeImmutable $now = null
    ): array {
        $this->assertAvailableEnvironment($server);
        $actorRef = trim($actorRef);
        if ($actorRef === '' || strlen($actorRef) > 191) {
            throw new InvalidArgumentException('Staging progression actor is invalid.');
        }

        $availability = $this->progressionAcceptanceAvailability($server);
        if (($availability['reason'] ?? '') === 'no_unresolved_pairs') {
            return ['status'=>'nothing_to_complete','completed_pairs'=>0] + $availability;
        }
        if (empty($availability['available'])) {
            throw new RuntimeException('Сейчас нет fixture-only пар, которые можно канонически завершить.');
        }

        $tournamentId = (string)$availability['tournament_id'];
        $roundNo = (int)$availability['round_no'];
        $moment = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_tournament_round_matches
             WHERE tournament_id=:tournament_id
               AND round_no=:round_no
               AND completed_at_utc IS NULL
             ORDER BY pair_no ASC',
            ['tournament_id'=>$tournamentId,'round_no'=>$roundNo]
        );

        $progression = new TournamentRoundProgressionService($this->database);
        $completed = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $a = $this->fixtureRuntimeIdentityForMgw((string)($row['player_a_mgw_id'] ?? ''));
            $b = $this->fixtureRuntimeIdentityForMgw((string)($row['player_b_mgw_id'] ?? ''));
            if ($a === null || $b === null) continue;

            $attemptNo = max(1, (int)($row['attempt_no'] ?? 1));
            $pairNo = max(1, (int)($row['pair_no'] ?? 1));
            $gameId = trim((string)($row['game_id'] ?? ''));
            if ($gameId === '') {
                $gameId = 'stg_tour_fixture_' . substr(
                    hash('sha256', $tournamentId . '|' . $roundNo . '|' . $pairNo . '|' . $attemptNo),
                    0,
                    48
                );
            }

            // Explicit staging acceptance result. It reuses the production progression
            // result owner, creates the durable attempt row and never creates a game,
            // charges another entry fee or changes production auto-advance semantics.
            $progression->observeFinishedGame([
                'id'=>$gameId,
                'match_source'=>'tournament',
                'status'=>'finished',
                'tournament_id'=>$tournamentId,
                'tournament_round_no'=>$roundNo,
                'tournament_pair_no'=>$pairNo,
                'tournament_attempt_no'=>$attemptNo,
                'player_ids'=>[(string)$a['legacy_user_id'], (string)$b['legacy_user_id']],
                'winner_id'=>(string)$a['legacy_user_id'],
                'finish_reason'=>'staging_fixture_acceptance',
                'finished_at'=>$moment->format(DATE_ATOM),
                'staging_acceptance_actor'=>$actorRef,
            ], $moment);
            $completed++;
        }

        return [
            'status'=>'completed',
            'tournament_id'=>$tournamentId,
            'round_no'=>$roundNo,
            'completed_pairs'=>$completed,
            'availability'=>$this->progressionAcceptanceAvailability($server),
        ];
    }

    public function resetAvailability(array $server): array
    {
        if (!$this->isAvailableEnvironment($server)) {
            return ['available'=>false,'reason'=>'staging_only'];
        }

        $snapshot = $this->tournaments->snapshot();
        $tournament = $snapshot['tournament'] ?? null;
        if (!is_array($tournament)) {
            return ['available'=>false,'reason'=>'no_active_tournament'];
        }

        $state = (string)($tournament['state'] ?? '');
        $allowed = in_array($state, [
            TournamentRegistrationService::STATE_DRAFT,
            TournamentRegistrationService::STATE_REGISTRATION_OPEN,
            TournamentRegistrationService::STATE_WAITING_FOR_DATE,
            TournamentRegistrationService::STATE_SCHEDULED,
        ], true);

        return [
            'available'=>$allowed,
            'reason'=>$allowed ? 'ready' : 'state_not_resettable',
            'tournament_id'=>(string)($tournament['tournament_id'] ?? ''),
            'state'=>$state,
            'registered_count'=>(int)($tournament['registered_count'] ?? 0),
            'capacity'=>(int)($tournament['capacity'] ?? 0),
        ];
    }

    public function resetForFreshManualAcceptance(
        array $server,
        string $actorRef,
        ?DateTimeImmutable $now = null
    ): array {
        $this->assertAvailableEnvironment($server);
        $actorRef = trim($actorRef);
        if ($actorRef === '' || strlen($actorRef) > 191) {
            throw new InvalidArgumentException('Staging tournament reset actor is invalid.');
        }

        $snapshot = $this->tournaments->snapshot();
        $tournament = $snapshot['tournament'] ?? null;
        if (!is_array($tournament)) {
            return [
                'status'=>'already_reset',
                'released_reservations'=>0,
                'fixture_accounts_retired'=>0,
                'real_accounts_released'=>0,
                'runtime_balances_updated'=>0,
            ];
        }

        $tournamentId = trim((string)($tournament['tournament_id'] ?? ''));
        $state = (string)($tournament['state'] ?? '');
        if ($tournamentId === '' || !in_array($state, [
            TournamentRegistrationService::STATE_DRAFT,
            TournamentRegistrationService::STATE_REGISTRATION_OPEN,
            TournamentRegistrationService::STATE_WAITING_FOR_DATE,
            TournamentRegistrationService::STATE_SCHEDULED,
        ], true)) {
            throw new RuntimeException('Текущий staging-турнир нельзя безопасно сбросить из этого состояния.');
        }

        $resetAt = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $tournamentId,
            $actorRef,
            $resetAt
        ): array {
            $lock = $db->driver() === 'sqlite' ? '' : ' FOR UPDATE';
            $tournamentRows = $db->fetchAll(
                'SELECT tournament_id,active_slot,tournament_state
                 FROM mgw_tournaments
                 WHERE tournament_id=:tournament_id' . $lock,
                ['tournament_id'=>$tournamentId]
            );
            if (count($tournamentRows) !== 1 || !is_array($tournamentRows[0])) {
                throw new RuntimeException('Staging tournament reset could not lock the active tournament.');
            }
            $lockedTournament = $tournamentRows[0];
            if ((string)($lockedTournament['active_slot'] ?? '') !== TournamentRegistrationService::ACTIVE_SLOT) {
                throw new RuntimeException('Staging tournament reset refuses a non-active tournament.');
            }
            if (!in_array((string)($lockedTournament['tournament_state'] ?? ''), [
                TournamentRegistrationService::STATE_DRAFT,
                TournamentRegistrationService::STATE_REGISTRATION_OPEN,
                TournamentRegistrationService::STATE_WAITING_FOR_DATE,
                TournamentRegistrationService::STATE_SCHEDULED,
            ], true)) {
                throw new RuntimeException('Staging tournament reset refuses the current tournament state.');
            }

            $registrations = $db->fetchAll(
                'SELECT r.registration_id,r.mgw_id,r.account_ref,r.reservation_id,
                        z.status AS reservation_status,z.amount AS reservation_amount,
                        z.asset_code AS reservation_asset,z.source_type AS reservation_source_type,
                        z.source_ref AS reservation_source_ref
                 FROM mgw_tournament_registrations r
                 INNER JOIN mgw_reservations z ON z.reservation_id=r.reservation_id
                 WHERE r.tournament_id=:tournament_id
                   AND r.registration_state=:registration_state
                 ORDER BY r.registration_id ASC' . $lock,
                [
                    'tournament_id'=>$tournamentId,
                    'registration_state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
                ]
            );

            $fixtureLegacyIds = [];
            $runtimeBalances = [];
            $released = 0;
            $fixtureRetired = 0;
            $realReleased = 0;

            foreach ($registrations as $registration) {
                if (!is_array($registration)) continue;
                $registrationId = trim((string)($registration['registration_id'] ?? ''));
                $mgwId = trim((string)($registration['mgw_id'] ?? ''));
                $accountRef = trim((string)($registration['account_ref'] ?? ''));
                $reservationId = trim((string)($registration['reservation_id'] ?? ''));
                if ($registrationId === '' || $mgwId === '' || $accountRef === '' || $reservationId === '') {
                    throw new RuntimeException('Staging tournament reset found an incomplete registration.');
                }
                if ((string)($registration['reservation_status'] ?? '') !== 'active'
                    || (int)($registration['reservation_amount'] ?? -1) !== TournamentRegistrationService::ENTRY_FEE
                    || (string)($registration['reservation_asset'] ?? '') !== TournamentRegistrationService::ENTRY_ASSET
                    || (string)($registration['reservation_source_type'] ?? '') !== 'official_tournament'
                    || (string)($registration['reservation_source_ref'] ?? '') !== $tournamentId) {
                    throw new RuntimeException('Staging tournament reset refuses an unproven tournament reservation.');
                }

                $ownershipRows = $db->fetchAll(
                    'SELECT account_ref,mgw_id,legacy_user_id,ownership_status,source_type,source_ref
                     FROM mgw_account_ownership
                     WHERE account_ref=:account_ref AND mgw_id=:mgw_id' . $lock,
                    ['account_ref'=>$accountRef,'mgw_id'=>$mgwId]
                );
                if (count($ownershipRows) !== 1
                    || (string)($ownershipRows[0]['ownership_status'] ?? '') !== 'active') {
                    throw new RuntimeException('Staging tournament reset requires one active account ownership row.');
                }
                $ownership = $ownershipRows[0];
                $legacyUserId = trim((string)($ownership['legacy_user_id'] ?? ''));

                $isFixture = $this->isSyntheticFixtureIdentity(
                    $mgwId,
                    $accountRef,
                    $legacyUserId,
                    (string)($ownership['source_type'] ?? ''),
                    (string)($ownership['source_ref'] ?? '')
                );

                $this->ledger->releaseReservation([
                    'operation_key'=>'staging:tournament-reset:release:'
                        . substr(hash('sha256', $tournamentId . '|' . $registrationId), 0, 48),
                    'reservation_id'=>$reservationId,
                    'metadata'=>[
                        'tournament_id'=>$tournamentId,
                        'registration_id'=>$registrationId,
                        'reason'=>'staging_manual_acceptance_reset',
                        'actor_ref'=>$actorRef,
                    ],
                    'occurred_at_utc'=>$resetAt,
                ]);
                $released++;

                $balance = $this->ledger->getBalance(
                    $accountRef,
                    TournamentRegistrationService::ENTRY_ASSET
                );
                if (!is_array($balance) || (int)($balance['reserved_amount'] ?? -1) !== 0) {
                    throw new RuntimeException('Staging tournament reset did not release the canonical reservation.');
                }

                if ($isFixture) {
                    if ((int)($balance['available_amount'] ?? -1) !== TournamentRegistrationService::ENTRY_FEE) {
                        throw new RuntimeException('Synthetic staging fixture balance is not the exact disposable test grant.');
                    }
                    $this->ledger->postAvailableDelta([
                        'operation_key'=>'staging:tournament-reset:fixture-revoke:'
                            . substr(hash('sha256', $tournamentId . '|' . $registrationId), 0, 48),
                        'account_ref'=>$accountRef,
                        'mgw_id'=>$mgwId,
                        'legacy_user_id'=>$legacyUserId,
                        'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
                        'available_delta'=>-TournamentRegistrationService::ENTRY_FEE,
                        'category'=>'staging_test_cleanup',
                        'source_type'=>'staging_test',
                        'source_ref'=>$tournamentId,
                        'metadata'=>[
                            'purpose'=>'retire_staging_tournament_fixture',
                            'registration_id'=>$registrationId,
                            'actor_ref'=>$actorRef,
                        ],
                        'occurred_at_utc'=>$resetAt,
                    ]);
                    $db->execute(
                        'UPDATE mgw_users
                         SET status=:status,updated_at_utc=:updated_at_utc
                         WHERE mgw_id=:mgw_id AND status=:expected_status',
                        [
                            'status'=>'staging_fixture_retired',
                            'updated_at_utc'=>$resetAt,
                            'mgw_id'=>$mgwId,
                            'expected_status'=>'active',
                        ]
                    );
                    if ($legacyUserId !== '') $fixtureLegacyIds[$legacyUserId] = true;
                    $fixtureRetired++;
                } else {
                    if ($legacyUserId !== '') {
                        $runtimeBalances[$legacyUserId] = (int)$balance['available_amount'];
                    }
                    $realReleased++;
                }

                $updated = $db->execute(
                    'UPDATE mgw_tournament_registrations
                     SET registration_state=:state,
                         withdrawn_at_utc=:withdrawn_at_utc,
                         updated_at_utc=:updated_at_utc
                     WHERE registration_id=:registration_id
                       AND registration_state=:expected_state',
                    [
                        'state'=>TournamentRegistrationService::REGISTRATION_WITHDRAWN,
                        'withdrawn_at_utc'=>$resetAt,
                        'updated_at_utc'=>$resetAt,
                        'registration_id'=>$registrationId,
                        'expected_state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
                    ]
                );
                if ($updated !== 1) {
                    throw new RuntimeException('Staging tournament registration changed during reset.');
                }
            }

            $remaining = (int)$db->fetchValue(
                'SELECT COUNT(*) FROM mgw_tournament_registrations
                 WHERE tournament_id=:tournament_id AND registration_state=:state',
                [
                    'tournament_id'=>$tournamentId,
                    'state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
                ]
            );
            if ($remaining !== 0) {
                throw new RuntimeException('Staging tournament reset left active registrations behind.');
            }

            $activeReservations = (int)$db->fetchValue(
                "SELECT COUNT(*) FROM mgw_reservations
                 WHERE source_type='official_tournament'
                   AND source_ref=:tournament_id
                   AND status='active'",
                ['tournament_id'=>$tournamentId]
            );
            if ($activeReservations !== 0) {
                throw new RuntimeException('Staging tournament reset left active entry reservations behind.');
            }

            $updatedTournament = $db->execute(
                'UPDATE mgw_tournaments
                 SET active_slot=NULL,
                     tournament_state=:state,
                     registration_closed_at_utc=COALESCE(registration_closed_at_utc,:closed_at_utc),
                     registration_closed_reason=:closed_reason,
                     updated_at_utc=:updated_at_utc
                 WHERE tournament_id=:tournament_id
                   AND active_slot=:active_slot',
                [
                    'state'=>'staging_reset',
                    'closed_at_utc'=>$resetAt,
                    'closed_reason'=>'staging_reset',
                    'updated_at_utc'=>$resetAt,
                    'tournament_id'=>$tournamentId,
                    'active_slot'=>TournamentRegistrationService::ACTIVE_SLOT,
                ]
            );
            if ($updatedTournament !== 1) {
                throw new RuntimeException('Staging tournament reset could not release the official active slot.');
            }

            $runtimeResult = $this->applyRuntimeReset(
                $runtimeBalances,
                array_keys($fixtureLegacyIds),
                $tournamentId,
                $resetAt
            );

            return [
                'status'=>'reset',
                'tournament_id'=>$tournamentId,
                'released_reservations'=>$released,
                'fixture_accounts_retired'=>$fixtureRetired,
                'real_accounts_released'=>$realReleased,
                'runtime_balances_updated'=>(int)($runtimeResult['updated_balances'] ?? 0),
                'runtime_fixture_users_removed'=>(int)($runtimeResult['removed_fixture_users'] ?? 0),
                'tournament_notifications_hidden'=>(int)($runtimeResult['hidden_tournament_notifications'] ?? 0),
            ];
        });
    }


    private function fixtureRuntimeIdentityForMgw(string $mgwId): ?array
    {
        $mgwId = trim($mgwId);
        if ($mgwId === '') return null;

        $rows = $this->database->fetchAll(
            'SELECT account_ref,mgw_id,legacy_user_id,ownership_status,source_type,source_ref
             FROM mgw_account_ownership
             WHERE mgw_id=:mgw_id AND ownership_status=:ownership_status
             LIMIT 2',
            ['mgw_id'=>$mgwId,'ownership_status'=>'active']
        );
        if (count($rows) !== 1 || !is_array($rows[0])) return null;
        $row = $rows[0];
        $accountRef = trim((string)($row['account_ref'] ?? ''));
        $legacyUserId = trim((string)($row['legacy_user_id'] ?? ''));
        if (!$this->isSyntheticFixtureIdentity(
            $mgwId,
            $accountRef,
            $legacyUserId,
            (string)($row['source_type'] ?? ''),
            (string)($row['source_ref'] ?? '')
        )) return null;

        return [
            'mgw_id'=>$mgwId,
            'account_ref'=>$accountRef,
            'legacy_user_id'=>$legacyUserId,
        ];
    }

    private function isSyntheticFixtureIdentity(
        string $mgwId,
        string $accountRef,
        string $legacyUserId,
        string $sourceType,
        string $sourceRef
    ): bool {
        $legacyFixture = preg_match('/^MGW-STG-[a-f0-9]{12}$/', $mgwId) === 1
            && preg_match('/^legacy:stg_tour_[a-f0-9]{12}$/', $accountRef) === 1
            && preg_match('/^stg_tour_[a-f0-9]{12}$/', $legacyUserId) === 1
            && $sourceType === 'staging_fixture_repair';

        $v2Fixture = MgwIdGenerator::isValid($mgwId)
            && preg_match('/^legacy:stg_tour_v2_[a-f0-9]{12}$/', $accountRef) === 1
            && preg_match('/^stg_tour_v2_[a-f0-9]{12}$/', $legacyUserId) === 1
            && $sourceType === 'runtime_identity'
            && $sourceRef === 'development:' . $legacyUserId;

        return $legacyFixture || $v2Fixture;
    }

    private function applyRuntimeReset(
        array $runtimeBalances,
        array $fixtureLegacyIds,
        string $tournamentId,
        string $resetAt
    ): array {
        if ($this->runtimeResetWriter !== null) {
            $result = ($this->runtimeResetWriter)(
                $runtimeBalances,
                $fixtureLegacyIds,
                $tournamentId,
                $resetAt
            );
            return is_array($result) ? $result : [];
        }

        $storage = StorageFactory::createJson((string)($this->config['data_dir'] ?? (__DIR__ . '/../data')));
        return $storage->transaction(static function (array &$data) use (
            $runtimeBalances,
            $fixtureLegacyIds,
            $tournamentId,
            $resetAt
        ): array {
            if (!isset($data['users']) || !is_array($data['users'])) {
                $data['users'] = [];
            }
            $updated = 0;
            foreach ($runtimeBalances as $legacyUserId=>$available) {
                if (!isset($data['users'][$legacyUserId]) || !is_array($data['users'][$legacyUserId])) {
                    continue;
                }
                $data['users'][$legacyUserId][UnifiedBalanceRuntimeState::FIELD] = (int)$available;
                $updated++;
            }

            $removed = 0;
            foreach ($fixtureLegacyIds as $legacyUserId) {
                if (isset($data['users'][$legacyUserId])) {
                    unset($data['users'][$legacyUserId]);
                    $removed++;
                }
            }

            $hiddenNotifications = 0;
            $audienceRef = 'official-tournament:' . $tournamentId;
            if (isset($data['notifications']) && is_array($data['notifications'])) {
                foreach ($data['notifications'] as &$notification) {
                    if (!is_array($notification)) continue;
                    if ((string)($notification['audience_type'] ?? '') !== 'tournament') continue;
                    if ((string)($notification['audience_ref'] ?? '') !== $audienceRef) continue;
                    if ((string)($notification['source_type'] ?? '') !== 'system') continue;
                    if (!empty($notification['hidden_at'])) continue;
                    if (empty($notification['read_at'])) $notification['read_at'] = $resetAt;
                    $notification['hidden_at'] = $resetAt;
                    $hiddenNotifications++;
                }
                unset($notification);
            }

            return [
                'updated_balances'=>$updated,
                'removed_fixture_users'=>$removed,
                'hidden_tournament_notifications'=>$hiddenNotifications,
            ];
        });
    }

    private function ensureCanonicalOwnership(array $identity): void
    {
        if (!MgwIdGenerator::isValid((string)$identity['mgw_id'])) {
            throw new RuntimeException('Тестовый MGW-ID не соответствует каноническому формату.');
        }
        $ownership = (new RuntimeAccountOwnershipService($this->database))->ensure(
            'development',
            (string)$identity['legacy_user_id'],
            (string)$identity['mgw_id']
        );
        if ((string)($ownership['account_ref'] ?? '') !== (string)$identity['account_ref']) {
            throw new RuntimeException('Тестовый account ownership не совпадает с fixture identity.');
        }
    }

    private function ensureCanonicalUser(array $identity): void
    {
        $rows = $this->database->fetchAll(
            'SELECT mgw_id,status FROM mgw_users WHERE mgw_id=:mgw_id LIMIT 1',
            ['mgw_id'=>$identity['mgw_id']]
        );
        if ($rows !== []) {
            if (count($rows) !== 1 || !is_array($rows[0])) {
                throw new RuntimeException('Тестовый MGW-пользователь имеет неоднозначное состояние.');
            }
            if ((string)($rows[0]['status'] ?? '') !== 'active') {
                throw new RuntimeException('Тестовый MGW-пользователь неактивен.');
            }
            return;
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s.u');
        $this->database->execute(
            'INSERT INTO mgw_users (
                mgw_id,status,display_name,username,
                avatar_provider,avatar_external_ref,avatar_storage_key,avatar_mime_type,
                avatar_width,avatar_height,
                created_at_utc,updated_at_utc,last_seen_at_utc
             ) VALUES (
                :mgw_id,:status,:display_name,NULL,
                NULL,NULL,NULL,NULL,
                NULL,NULL,
                :created_at_utc,:updated_at_utc,:last_seen_at_utc
             )',
            [
                'mgw_id'=>$identity['mgw_id'],
                'status'=>'active',
                'display_name'=>$identity['display_name'],
                'created_at_utc'=>$now,
                'updated_at_utc'=>$now,
                'last_seen_at_utc'=>$now,
            ]
        );
    }

    private function ensureEntryBalance(array $identity, string $tournamentId, int $slot): void
    {
        $balance = $this->ledger->getBalance(
            $identity['account_ref'],
            TournamentRegistrationService::ENTRY_ASSET
        );
        if ($balance === null) {
            $this->ledger->postAvailableDelta([
                'operation_key'=>'staging:tournament-manual:grant:'
                    . substr(hash('sha256', $tournamentId), 0, 16)
                    . ':' . $slot,
                'account_ref'=>$identity['account_ref'],
                'mgw_id'=>$identity['mgw_id'],
                'legacy_user_id'=>$identity['legacy_user_id'],
                'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
                'available_delta'=>TournamentRegistrationService::ENTRY_FEE,
                'category'=>'test_grant',
                'source_type'=>'test',
                'source_ref'=>$tournamentId,
                'metadata'=>[
                    'purpose'=>'staging_tournament_manual_acceptance_fixture',
                    'fixture_slot'=>$slot,
                ],
            ]);
            return;
        }

        if ((int)($balance['available_amount'] ?? -1) < TournamentRegistrationService::ENTRY_FEE) {
            throw new RuntimeException('Баланс тестового участника уже существует в несовместимом состоянии.');
        }
        if ((int)($balance['reserved_amount'] ?? -1) !== 0) {
            throw new RuntimeException('У тестового участника уже есть чужой активный резерв.');
        }
    }

    private function ensureRuntimeUsers(array $batch): void
    {
        if ($batch === []) return;

        if ($this->runtimeUserWriter !== null) {
            foreach ($batch as $entry) {
                if (!is_array($entry) || !is_array($entry['identity'] ?? null)) continue;
                ($this->runtimeUserWriter)($entry['identity'], (int)($entry['slot'] ?? 0));
            }
            return;
        }

        $storage = StorageFactory::createJson((string)($this->config['data_dir'] ?? (__DIR__ . '/../data')));
        $config = $this->config;
        $database = $this->database;
        $storage->transaction(static function (array &$data) use (
            $batch,
            $config,
            $database
        ): array {
            if (!isset($data['users']) || !is_array($data['users'])) {
                $data['users'] = [];
            }
            $users = new UserService($config, $database);
            foreach ($batch as $entry) {
                if (!is_array($entry) || !is_array($entry['identity'] ?? null)) continue;
                $identity = $entry['identity'];
                $slot = (int)($entry['slot'] ?? 0);
                $users->ensureUser($data, [
                    'id'=>$identity['legacy_user_id'],
                    'first_name'=>$identity['display_name'],
                    'username'=>'',
                    'language_code'=>'ru',
                    'is_dev_user'=>true,
                    'is_staging_test_user'=>true,
                    'staging_test_slot'=>'TOURNAMENT-' . $slot,
                    'mgw_id'=>$identity['mgw_id'],
                    'mgw_account_ref'=>$identity['account_ref'],
                    'mgw_identity_provider'=>'staging_fixture',
                    'mgw_nickname'=>'Тест ' . $slot,
                ]);
            }
            return $data;
        });
    }

    private function fixtureIdentity(string $tournamentId, int $slot): array
    {
        $token = substr(hash('sha256', $tournamentId . '|manual-acceptance-v2|' . $slot), 0, 12);
        $legacyUserId = 'stg_tour_v2_' . $token;
        $mgwToken = strtoupper(substr(hash('sha256', $tournamentId . '|manual-acceptance-mgw-v2|' . $slot), 0, 16));
        return [
            'mgw_id'=>'MGW-' . $mgwToken,
            'legacy_user_id'=>$legacyUserId,
            'account_ref'=>'legacy:' . $legacyUserId,
            'display_name'=>'Тестовый участник ' . $slot,
        ];
    }

    private function assertAvailableEnvironment(array $server): void
    {
        if (!$this->isAvailableEnvironment($server)) {
            throw new RuntimeException('Заполнение тестовыми участниками доступно только в staging.');
        }
    }

    private function isAvailableEnvironment(array $server): bool
    {
        if (strtolower(trim((string)($this->config['environment'] ?? ''))) !== 'staging') {
            return false;
        }

        $baseUrl = rtrim(trim((string)($this->config['base_url'] ?? '')), '/');
        $baseScheme = strtolower((string)(parse_url($baseUrl, PHP_URL_SCHEME) ?: ''));
        $baseHost = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        $requestHost = strtolower(trim((string)($server['HTTP_HOST'] ?? '')));
        if (str_contains($requestHost, ':')) {
            $requestHost = explode(':', $requestHost, 2)[0];
        }
        if ($baseScheme !== 'https'
            || $baseHost !== self::STAGING_HOST
            || $requestHost !== self::STAGING_HOST) {
            return false;
        }

        if (!empty($this->config['external_payments_enabled'])) return false;
        foreach (['payment_mode', 'telegram_stars_mode', 'google_play_billing_mode'] as $key) {
            if (strtolower(trim((string)($this->config[$key] ?? ''))) === 'live') {
                return false;
            }
        }
        return true;
    }
}
