<?php
declare(strict_types=1);

require_once __DIR__ . '/../economy/UnifiedBalanceRuntimeState.php';

final class StagingTestPlayerResetStageException extends RuntimeException
{
    private const ALLOWED_STAGES = [
        'availability',
        'json_state',
        'notification_cleanup',
        'invite_cleanup',
        'tournament_cleanup',
        'economy',
    ];

    public function __construct(private string $stage, Throwable $previous)
    {
        if (!in_array($stage, self::ALLOWED_STAGES, true)) {
            $stage = 'unknown';
        }
        parent::__construct('Staging test-player reset stage failed.', 0, $previous);
    }

    public function stage(): string
    {
        return $this->stage;
    }
}

final class StagingTestPlayerStateResetService
{
    private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';
    private const TEST_PLAYER_IDS = ['stg_test_player_a', 'stg_test_player_b'];
    private const MATCH_BALANCE = 100;
    private const OPEN_INVITE_STATUSES = ['draft', 'pending', 'accepted', 'awaiting_start'];

    private RuntimeStorageRouter $router;

    public function __construct(private array $config, ?RuntimeStorageRouter $router = null)
    {
        $this->router = $router ?? new RuntimeStorageRouter($config);
    }

    public function reset(array $server): array
    {
        try {
            $this->assertAvailable($server);
        } catch (Throwable $error) {
            throw new StagingTestPlayerResetStageException('availability', $error);
        }

        $storage = StorageFactory::createJson((string)($this->config['data_dir'] ?? (__DIR__ . '/../data')));
        $before = [];
        $queueRemoved = 0;
        $removedInvites = [];
        $retiredStartedInvites = 0;
        $notificationsRemoved = 0;
        $gamesFinished = 0;

        try {
            $snapshot = $storage->transaction(function (array &$data) use (
                &$before,
                &$queueRemoved,
                &$removedInvites,
                &$retiredStartedInvites,
                &$notificationsRemoved,
                &$gamesFinished
            ): array {
                if (!isset($data['users']) || !is_array($data['users'])) {
                    throw new RuntimeException('Staging test users are unavailable.');
                }

                $testIds = array_fill_keys(self::TEST_PLAYER_IDS, true);
                foreach (self::TEST_PLAYER_IDS as $legacyUserId) {
                    if (!isset($data['users'][$legacyUserId]) || !is_array($data['users'][$legacyUserId])) {
                        throw new RuntimeException('Staging test player is not initialized.');
                    }
                    UnifiedBalanceRuntimeState::ensureUser($data['users'][$legacyUserId]);
                    $before[$legacyUserId] = (int)$data['users'][$legacyUserId][UnifiedBalanceRuntimeState::FIELD];
                }

                $games = new GameService($this->config);
                foreach (($data['games'] ?? []) as $gameId => $game) {
                    if (!is_array($game) || (string)($game['status'] ?? '') !== 'active') continue;
                    $participants = array_values(array_filter(
                        array_map('strval', is_array($game['player_ids'] ?? null) ? $game['player_ids'] : []),
                        static fn(string $id): bool => $id !== ''
                    ));
                    $testParticipants = array_values(array_filter(
                        $participants,
                        static fn(string $id): bool => isset($testIds[$id])
                    ));
                    if ($testParticipants === []) continue;

                    foreach ($participants as $participantId) {
                        if (isset($testIds[$participantId]) || str_starts_with($participantId, 'bot_')) continue;
                        throw new RuntimeException('Staging test reset refuses an active game with a non-test player.');
                    }

                    $actorId = $testParticipants[0];
                    if (!isset($data['users'][$actorId]) || !is_array($data['users'][$actorId])) {
                        throw new RuntimeException('Staging test active-game participant is unavailable.');
                    }
                    $actor =& $data['users'][$actorId];
                    $games->surrenderGame($data, $actor, (string)$gameId);
                    unset($actor);
                    $gamesFinished++;
                }

                $queueBefore = count(is_array($data['queue'] ?? null) ? $data['queue'] : []);
                $data['queue'] = array_values(array_filter(
                    is_array($data['queue'] ?? null) ? $data['queue'] : [],
                    static fn($item): bool => !is_array($item)
                        || !isset($testIds[(string)($item['user_id'] ?? '')])
                ));
                $queueRemoved = $queueBefore - count($data['queue']);

                foreach (self::TEST_PLAYER_IDS as $legacyUserId) {
                    $data['users'][$legacyUserId]['status'] = 'idle';
                    $data['users'][$legacyUserId]['current_game_id'] = null;
                    $data['users'][$legacyUserId][UnifiedBalanceRuntimeState::FIELD] = self::MATCH_BALANCE;
                }

                foreach ((is_array($data['invites'] ?? null) ? $data['invites'] : []) as $index => $invite) {
                    if (!is_array($invite)) continue;
                    $status = trim((string)($invite['status'] ?? ''));
                    $isOpen = in_array($status, self::OPEN_INVITE_STATUSES, true);
                    $isStarted = $status === 'active';
                    if (!$isOpen && !$isStarted) continue;

                    $participants = $this->testOnlyInviteParticipants($invite, $testIds);
                    if ($participants === null) continue;

                    $inviteId = trim((string)($invite['id'] ?? ''));
                    $token = trim((string)($invite['token'] ?? ''));
                    if ($inviteId === '' || $token === '') {
                        throw new RuntimeException('Staging test reset refuses an invite without stable identity.');
                    }

                    if ($isStarted) {
                        $gameId = trim((string)($invite['game_id'] ?? ''));
                        if ($gameId === '') {
                            throw new RuntimeException('Staging test reset refuses an active invite without game identity.');
                        }
                        if (!$this->startedTestInviteCanRetire($data, $gameId, $testIds)) {
                            continue;
                        }

                        // This is JSON-only test-state retirement. Started invites may
                        // already be referenced by durable match history, so their DB
                        // rows must never enter the unmatched-invite deletion path.
                        unset($data['invites'][$index]);
                        $retiredStartedInvites++;
                        continue;
                    }

                    if (trim((string)($invite['game_id'] ?? '')) !== '') continue;
                    $removedInvites[] = [
                        'invite_id' => $inviteId,
                        'token' => $token,
                        'status' => $status === 'accepted' ? 'awaiting_start' : $status,
                        'participant_ids' => $participants,
                    ];
                    unset($data['invites'][$index]);
                }
                $data['invites'] = array_values(is_array($data['invites'] ?? null) ? $data['invites'] : []);

                // A/B are dedicated technical identities. Every suite starts with no
                // historical notification state for them, while real-user rows are kept.
                $notificationsBefore = count(is_array($data['notifications'] ?? null) ? $data['notifications'] : []);
                $data['notifications'] = array_values(array_filter(
                    is_array($data['notifications'] ?? null) ? $data['notifications'] : [],
                    static function ($notification) use ($testIds): bool {
                        if (!is_array($notification)) return true;
                        return !isset($testIds[(string)($notification['user_id'] ?? '')]);
                    }
                ));
                $notificationsRemoved = $notificationsBefore - count($data['notifications']);

                return $data;
            });
        } catch (Throwable $error) {
            throw new StagingTestPlayerResetStageException('json_state', $error);
        }

        // Notification cleanup commits before the A/B-scoped invite check. The
        // JSON snapshot above contains no A/B notification history by contract.
        try {
            $notificationCleanup = $this->cleanupRuntimeTestNotificationRows($snapshot);
        } catch (Throwable $error) {
            throw new StagingTestPlayerResetStageException('notification_cleanup', $error);
        }
        try {
            $inviteCleanup = $this->cleanupRuntimeInviteRows($snapshot, $removedInvites);
        } catch (Throwable $error) {
            throw new StagingTestPlayerResetStageException('invite_cleanup', $error);
        }

        try {
            $tournamentCleanup = $this->cleanupRuntimeTournamentRegistrations();
        } catch (Throwable $error) {
            throw new StagingTestPlayerResetStageException('tournament_cleanup', $error);
        }

        try {
            $economy = new RuntimeEconomyRepository($this->config, $this->router);
            $synchronized = $economy->synchronize($snapshot);
            $audit = $economy->auditParity($snapshot);
            if (($synchronized['ok'] ?? false) !== true || ($audit['ok'] ?? false) !== true) {
                throw new RuntimeException('Staging test-player economy reset did not reach parity.');
            }
        } catch (Throwable $error) {
            throw new StagingTestPlayerResetStageException('economy', $error);
        }

        $balances = [];
        foreach (self::TEST_PLAYER_IDS as $legacyUserId) {
            $balances[] = [
                'slot' => str_ends_with($legacyUserId, '_a') ? 'A' : 'B',
                'before' => (int)($before[$legacyUserId] ?? 0),
                'after' => self::MATCH_BALANCE,
            ];
        }

        return [
            'ok' => true,
            'service' => 'mini-games-world-staging-test-player-state-reset',
            'status' => 'reset',
            'balance' => self::MATCH_BALANCE,
            'match_balance' => self::MATCH_BALANCE,
            'players' => $balances,
            'queue_removed' => $queueRemoved,
            'open_invites_removed' => count($removedInvites),
            'retired_started_invites' => $retiredStartedInvites,
            'notifications_removed' => $notificationsRemoved,
            'active_test_games_finished' => $gamesFinished,
            'invite_db_rows_removed' => (int)($inviteCleanup['invite_rows'] ?? 0),
            'invite_event_db_rows_removed' => (int)($inviteCleanup['invite_event_rows'] ?? 0),
            'notification_db_rows_removed' => (int)($notificationCleanup['notification_rows'] ?? 0)
                + (int)($inviteCleanup['notification_rows'] ?? 0),
            'tournament_registrations_withdrawn' => (int)($tournamentCleanup['withdrawn'] ?? 0),
            'tournament_parity' => ($tournamentCleanup['parity'] ?? false) === true,
            'invite_parity' => ($inviteCleanup['parity'] ?? false) === true,
            'notification_parity' => ($notificationCleanup['parity'] ?? false) === true,
            'economy_parity' => true,
            'production_changed' => false,
            'live_payments_used' => false,
        ];
    }

    private function cleanupRuntimeTournamentRegistrations(): array
    {
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            return ['withdrawn' => 0, 'reopened' => 0, 'parity' => true];
        }

        $database = PdoConnectionFactory::create($databaseConfig);
        $ledger = new LedgerWriteService($database);
        $service = new TournamentRegistrationService($database, $ledger);
        $withdrawn = 0;
        $reopened = 0;

        foreach (self::TEST_PLAYER_IDS as $legacyUserId) {
            $rows = $database->fetchAll(
                'SELECT account_ref, mgw_id, ownership_status
                 FROM mgw_account_ownership
                 WHERE legacy_user_id = :legacy_user_id',
                ['legacy_user_id' => $legacyUserId]
            );
            if ($rows === []) {
                continue;
            }
            if (count($rows) !== 1 || (string)($rows[0]['ownership_status'] ?? '') !== 'active') {
                throw new RuntimeException('Staging test tournament cleanup ownership is unavailable.');
            }

            $accountRef = trim((string)($rows[0]['account_ref'] ?? ''));
            $mgwId = trim((string)($rows[0]['mgw_id'] ?? ''));
            if ($accountRef === '' || $mgwId === '') {
                throw new RuntimeException('Staging test tournament cleanup ownership is incomplete.');
            }

            $snapshot = $service->snapshot($mgwId, $accountRef);
            $registration = is_array($snapshot['registration'] ?? null)
                ? $snapshot['registration']
                : null;
            if ($registration === null
                || (string)($registration['state'] ?? '') !== TournamentRegistrationService::REGISTRATION_REGISTERED) {
                continue;
            }

            $tournament = is_array($snapshot['tournament'] ?? null)
                ? $snapshot['tournament']
                : null;
            if ($tournament === null) {
                throw new RuntimeException('Staging test tournament cleanup active tournament is unavailable.');
            }

            $state = (string)($tournament['state'] ?? '');
            if ($state === TournamentRegistrationService::STATE_REGISTRATION_OPEN) {
                $after = $service->leave($mgwId, $accountRef);
            } elseif ($state === TournamentRegistrationService::STATE_WAITING_FOR_DATE
                && (string)($tournament['registration_closed_reason'] ?? '') === 'full'
                && empty($tournament['scheduled_start_at_utc'])) {
                $after = $this->withdrawAutoClosedTestTournamentRegistration(
                    $database,
                    $ledger,
                    $service,
                    $legacyUserId,
                    $accountRef,
                    $mgwId,
                    $tournament,
                    $registration
                );
                $reopened++;
            } else {
                throw new RuntimeException('Staging test tournament cleanup refuses a registered test player after registration close.');
            }

            if ((string)($after['registration']['state'] ?? '') !== TournamentRegistrationService::REGISTRATION_WITHDRAWN
                || (int)($after['balance']['reserved_amount'] ?? -1) !== 0) {
                throw new RuntimeException('Staging test tournament cleanup did not release the reservation.');
            }
            $withdrawn++;
        }

        foreach (self::TEST_PLAYER_IDS as $legacyUserId) {
            $rows = $database->fetchAll(
                'SELECT account_ref, mgw_id, ownership_status
                 FROM mgw_account_ownership
                 WHERE legacy_user_id = :legacy_user_id',
                ['legacy_user_id' => $legacyUserId]
            );
            if ($rows === []) continue;
            if (count($rows) !== 1 || (string)($rows[0]['ownership_status'] ?? '') !== 'active') {
                throw new RuntimeException('Staging test tournament cleanup parity ownership is unavailable.');
            }

            $snapshot = $service->snapshot(
                trim((string)($rows[0]['mgw_id'] ?? '')),
                trim((string)($rows[0]['account_ref'] ?? ''))
            );
            if ((string)($snapshot['registration']['state'] ?? '') === TournamentRegistrationService::REGISTRATION_REGISTERED
                || (int)($snapshot['balance']['reserved_amount'] ?? 0) !== 0) {
                throw new RuntimeException('Staging test tournament cleanup did not restore A/B tournament parity.');
            }
        }

        return ['withdrawn' => $withdrawn, 'reopened' => $reopened, 'parity' => true];
    }

    private function withdrawAutoClosedTestTournamentRegistration(
        DatabaseConnectionInterface $database,
        LedgerWriteService $ledger,
        TournamentRegistrationService $service,
        string $legacyUserId,
        string $accountRef,
        string $mgwId,
        array $tournament,
        array $registration
    ): array {
        $tournamentId = trim((string)($tournament['tournament_id'] ?? ''));
        $registrationId = trim((string)($registration['registration_id'] ?? ''));
        $reservationId = trim((string)($registration['reservation_id'] ?? ''));
        if ($tournamentId === '' || $registrationId === '' || $reservationId === '') {
            throw new RuntimeException('Staging test tournament cleanup auto-close identity is incomplete.');
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s.u');

        $database->transaction(function (DatabaseConnectionInterface $db) use (
            $ledger,
            $legacyUserId,
            $accountRef,
            $mgwId,
            $tournamentId,
            $registrationId,
            $reservationId,
            $now
        ): void {
            $lock = $db->driver() === 'mysql' ? ' FOR UPDATE' : '';
            $tournamentRows = $db->fetchAll(
                'SELECT tournament_id,tournament_state,capacity,registration_closed_reason,
                        scheduled_start_at_utc,bracket_generated_at_utc
                 FROM mgw_tournaments
                 WHERE tournament_id=:tournament_id' . $lock,
                ['tournament_id'=>$tournamentId]
            );
            if (count($tournamentRows) !== 1 || !is_array($tournamentRows[0])) {
                throw new RuntimeException('Staging test tournament cleanup cannot lock the auto-closed tournament.');
            }
            $lockedTournament = $tournamentRows[0];
            if ((string)($lockedTournament['tournament_state'] ?? '') !== TournamentRegistrationService::STATE_WAITING_FOR_DATE
                || (string)($lockedTournament['registration_closed_reason'] ?? '') !== 'full'
                || trim((string)($lockedTournament['scheduled_start_at_utc'] ?? '')) !== ''
                || trim((string)($lockedTournament['bracket_generated_at_utc'] ?? '')) !== '') {
                throw new RuntimeException('Staging test tournament cleanup refuses to reopen a progressed tournament.');
            }

            $registrationRows = $db->fetchAll(
                'SELECT r.registration_id,r.tournament_id,r.mgw_id,r.account_ref,r.registration_state,r.reservation_id,
                        z.status AS reservation_status,z.amount AS reservation_amount,
                        z.asset_code AS reservation_asset,z.source_type AS reservation_source_type,
                        z.source_ref AS reservation_source_ref
                 FROM mgw_tournament_registrations r
                 INNER JOIN mgw_reservations z ON z.reservation_id=r.reservation_id
                 WHERE r.registration_id=:registration_id' . $lock,
                ['registration_id'=>$registrationId]
            );
            if (count($registrationRows) !== 1 || !is_array($registrationRows[0])) {
                throw new RuntimeException('Staging test tournament cleanup registration is unavailable.');
            }
            $row = $registrationRows[0];
            if ((string)($row['tournament_id'] ?? '') !== $tournamentId
                || (string)($row['mgw_id'] ?? '') !== $mgwId
                || (string)($row['account_ref'] ?? '') !== $accountRef
                || (string)($row['registration_state'] ?? '') !== TournamentRegistrationService::REGISTRATION_REGISTERED
                || (string)($row['reservation_id'] ?? '') !== $reservationId
                || (string)($row['reservation_status'] ?? '') !== 'active'
                || (int)($row['reservation_amount'] ?? -1) !== TournamentRegistrationService::ENTRY_FEE
                || (string)($row['reservation_asset'] ?? '') !== TournamentRegistrationService::ENTRY_ASSET
                || (string)($row['reservation_source_type'] ?? '') !== 'official_tournament'
                || (string)($row['reservation_source_ref'] ?? '') !== $tournamentId) {
                throw new RuntimeException('Staging test tournament cleanup refuses an unproven auto-close registration.');
            }

            $ownershipCount = (int)$db->fetchValue(
                'SELECT COUNT(*) FROM mgw_account_ownership
                 WHERE legacy_user_id=:legacy_user_id
                   AND account_ref=:account_ref
                   AND mgw_id=:mgw_id
                   AND ownership_status=:ownership_status',
                [
                    'legacy_user_id'=>$legacyUserId,
                    'account_ref'=>$accountRef,
                    'mgw_id'=>$mgwId,
                    'ownership_status'=>'active',
                ]
            );
            if ($ownershipCount !== 1) {
                throw new RuntimeException('Staging test tournament cleanup cannot prove A/B ownership.');
            }

            $ledger->releaseReservation([
                'operation_key'=>'staging:test-player-reset:tournament-release:'
                    . substr(hash('sha256', $tournamentId . '|' . $registrationId), 0, 48),
                'reservation_id'=>$reservationId,
                'metadata'=>[
                    'tournament_id'=>$tournamentId,
                    'registration_id'=>$registrationId,
                    'legacy_user_id'=>$legacyUserId,
                    'reason'=>'staging_test_player_cleanup_after_auto_close',
                ],
                'occurred_at_utc'=>$now,
            ]);

            $updated = $db->execute(
                'UPDATE mgw_tournament_registrations
                 SET registration_state=:state,
                     withdrawn_at_utc=:withdrawn_at_utc,
                     updated_at_utc=:updated_at_utc
                 WHERE registration_id=:registration_id
                   AND registration_state=:expected_state',
                [
                    'state'=>TournamentRegistrationService::REGISTRATION_WITHDRAWN,
                    'withdrawn_at_utc'=>$now,
                    'updated_at_utc'=>$now,
                    'registration_id'=>$registrationId,
                    'expected_state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
                ]
            );
            if ($updated !== 1) {
                throw new RuntimeException('Staging test tournament cleanup registration changed concurrently.');
            }

            $remaining = (int)$db->fetchValue(
                'SELECT COUNT(*) FROM mgw_tournament_registrations
                 WHERE tournament_id=:tournament_id
                   AND registration_state=:registration_state',
                [
                    'tournament_id'=>$tournamentId,
                    'registration_state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
                ]
            );
            if ($remaining >= (int)($lockedTournament['capacity'] ?? 0)) {
                throw new RuntimeException('Staging test tournament cleanup did not free the technical seat.');
            }

            $reopened = $db->execute(
                'UPDATE mgw_tournaments
                 SET tournament_state=:state,
                     registration_closed_at_utc=NULL,
                     registration_closed_reason=NULL,
                     updated_at_utc=:updated_at_utc
                 WHERE tournament_id=:tournament_id
                   AND tournament_state=:expected_state
                   AND registration_closed_reason=:expected_reason
                   AND scheduled_start_at_utc IS NULL',
                [
                    'state'=>TournamentRegistrationService::STATE_REGISTRATION_OPEN,
                    'updated_at_utc'=>$now,
                    'tournament_id'=>$tournamentId,
                    'expected_state'=>TournamentRegistrationService::STATE_WAITING_FOR_DATE,
                    'expected_reason'=>'full',
                ]
            );
            if ($reopened !== 1) {
                throw new RuntimeException('Staging test tournament cleanup could not reopen the technical auto-close.');
            }
        });

        $after = $service->snapshot($mgwId, $accountRef);
        if ((string)($after['tournament']['state'] ?? '') !== TournamentRegistrationService::STATE_REGISTRATION_OPEN
            || (int)($after['tournament']['remaining_count'] ?? 0) < 1) {
            throw new RuntimeException('Staging test tournament cleanup did not restore the manual seat.');
        }
        return $after;
    }

    private function testOnlyInviteParticipants(array $invite, array $testIds): ?array
    {
        $participants = array_values(array_unique(array_filter([
            trim((string)($invite['inviter_id'] ?? '')),
            trim((string)($invite['invitee_id'] ?? '')),
        ], static fn(string $id): bool => $id !== '')));
        if ($participants === []) return null;

        $hasTestParticipant = false;
        foreach ($participants as $participantId) {
            if (isset($testIds[$participantId])) {
                $hasTestParticipant = true;
                break;
            }
        }
        if (!$hasTestParticipant) return null;

        foreach ($participants as $participantId) {
            if (!isset($testIds[$participantId])) {
                throw new RuntimeException('Staging test reset refuses an invite with a non-test player.');
            }
        }
        sort($participants, SORT_STRING);
        return $participants;
    }

    private function startedTestInviteCanRetire(array $data, string $gameId, array $testIds): bool
    {
        $linkedGame = $data['games'][$gameId] ?? null;
        if ($linkedGame === null) {
            return true;
        }
        if (!is_array($linkedGame)) {
            throw new RuntimeException('Staging test reset refuses an active invite with malformed game state.');
        }

        $gameStatus = trim((string)($linkedGame['status'] ?? ''));
        if (in_array($gameStatus, ['active', 'waiting'], true)) {
            return false;
        }
        if ($gameStatus !== 'finished') {
            throw new RuntimeException('Staging test reset refuses an active invite with unknown linked game state.');
        }

        $gameParticipants = array_values(array_unique(array_filter(
            array_map('strval', is_array($linkedGame['player_ids'] ?? null) ? $linkedGame['player_ids'] : []),
            static fn(string $id): bool => $id !== ''
        )));
        if ($gameParticipants === []) {
            throw new RuntimeException('Staging test reset cannot prove linked game ownership.');
        }
        foreach ($gameParticipants as $participantId) {
            if (!isset($testIds[$participantId])) {
                throw new RuntimeException('Staging test reset refuses an active invite linked to a non-test game.');
            }
        }
        return true;
    }

    private function cleanupRuntimeTestNotificationRows(array $snapshot): array
    {
        if (!$this->router->enabled()
            || $this->router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
            || $this->router->routeFor('notifications') !== RuntimeStorageRouter::DRIVER_DATABASE) {
            return ['notification_rows' => 0, 'parity' => true];
        }

        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Staging test notification cleanup requires an enabled database.');
        }
        $database = PdoConnectionFactory::create($databaseConfig);
        $ownership = [];

        foreach (self::TEST_PLAYER_IDS as $legacyUserId) {
            $rows = $database->fetchAll(
                'SELECT account_ref, mgw_id, ownership_status
                 FROM mgw_account_ownership
                 WHERE legacy_user_id = :legacy_user_id',
                ['legacy_user_id' => $legacyUserId]
            );
            if (count($rows) !== 1 || (string)($rows[0]['ownership_status'] ?? '') !== 'active') {
                throw new RuntimeException('Staging test notification cleanup ownership is unavailable.');
            }
            $accountRef = trim((string)($rows[0]['account_ref'] ?? ''));
            $mgwId = trim((string)($rows[0]['mgw_id'] ?? ''));
            if ($accountRef === '' || $mgwId === '') {
                throw new RuntimeException('Staging test notification cleanup ownership is incomplete.');
            }
            $ownership[$legacyUserId] = ['account_ref' => $accountRef, 'mgw_id' => $mgwId];
        }

        $deleted = $database->transaction(function (DatabaseConnectionInterface $db) use ($ownership): int {
            $count = 0;
            foreach ($ownership as $legacyUserId => $identity) {
                $rows = $db->fetchAll(
                    'SELECT notification_id, recipient_ref, mgw_id, legacy_user_id
                     FROM mgw_notifications
                     WHERE legacy_user_id = :legacy_user_id
                     ORDER BY notification_id',
                    ['legacy_user_id' => $legacyUserId]
                );
                foreach ($rows as $row) {
                    if (!hash_equals((string)$identity['account_ref'], trim((string)($row['recipient_ref'] ?? '')))
                        || !hash_equals((string)$identity['mgw_id'], trim((string)($row['mgw_id'] ?? '')))
                        || !hash_equals((string)$legacyUserId, trim((string)($row['legacy_user_id'] ?? '')))) {
                        throw new RuntimeException('Staging test notification cleanup ownership mismatch.');
                    }
                    $affected = $db->execute(
                        'DELETE FROM mgw_notifications
                         WHERE notification_id = :notification_id
                           AND legacy_user_id = :legacy_user_id
                           AND recipient_ref = :recipient_ref
                           AND mgw_id = :mgw_id',
                        [
                            'notification_id' => (string)($row['notification_id'] ?? ''),
                            'legacy_user_id' => $legacyUserId,
                            'recipient_ref' => (string)$identity['account_ref'],
                            'mgw_id' => (string)$identity['mgw_id'],
                        ]
                    );
                    if ($affected !== 1) {
                        throw new RuntimeException('Staging test notification cleanup delete count is unexpected.');
                    }
                    $count += $affected;
                }
            }
            return $count;
        });

        foreach (self::TEST_PLAYER_IDS as $legacyUserId) {
            $notificationAudit = (new RuntimeNotificationRepository($this->config, $this->router, $database))
                ->auditParity($snapshot, $legacyUserId);
            if (($notificationAudit['ok'] ?? false) !== true) {
                throw new RuntimeException('Staging test notification cleanup did not restore parity.');
            }
        }

        return ['notification_rows' => $deleted, 'parity' => true];
    }

    private function cleanupRuntimeInviteRows(array $snapshot, array $removedInvites): array
    {
        if (!$this->router->enabled()
            || $this->router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
            || $this->router->routeFor('notifications') !== RuntimeStorageRouter::DRIVER_DATABASE
            || $this->router->routeFor('invites') !== RuntimeStorageRouter::DRIVER_DATABASE) {
            return [
                'invite_rows' => 0,
                'invite_event_rows' => 0,
                'notification_rows' => 0,
                'parity' => true,
            ];
        }

        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Staging test invite cleanup requires an enabled database.');
        }
        $database = PdoConnectionFactory::create($databaseConfig);
        $testIds = array_fill_keys(self::TEST_PLAYER_IDS, true);
        $deleted = ['invite_rows' => 0, 'invite_event_rows' => 0, 'notification_rows' => 0];

        if ($removedInvites !== []) {
            $deleted = $database->transaction(function (DatabaseConnectionInterface $db) use (
                $removedInvites,
                $testIds
            ): array {
                $deleted = ['invite_rows' => 0, 'invite_event_rows' => 0, 'notification_rows' => 0];

                foreach ($removedInvites as $removedInvite) {
                    if (!is_array($removedInvite)) continue;
                    $inviteId = (string)($removedInvite['invite_id'] ?? '');
                    $token = (string)($removedInvite['token'] ?? '');
                    $status = (string)($removedInvite['status'] ?? '');
                    $participants = array_values(array_map('strval', $removedInvite['participant_ids'] ?? []));
                    if ($inviteId === '' || $token === '' || $participants === []) {
                        throw new RuntimeException('Staging test invite cleanup identity is incomplete.');
                    }
                    foreach ($participants as $participantId) {
                        if (!isset($testIds[$participantId])) {
                            throw new RuntimeException('Staging test invite cleanup refuses a non-test participant.');
                        }
                    }

                    $rows = $db->fetchAll(
                        'SELECT * FROM mgw_invites WHERE invite_id = :invite_id AND token = :token',
                        ['invite_id' => $inviteId, 'token' => $token]
                    );
                    if (count($rows) > 1) {
                        throw new RuntimeException('Staging test invite cleanup found duplicate DB identity.');
                    }
                    if ($rows === []) continue;

                    $row = $rows[0];
                    $dbParticipants = array_values(array_unique(array_filter([
                        trim((string)($row['inviter_legacy_user_id'] ?? '')),
                        trim((string)($row['invitee_legacy_user_id'] ?? '')),
                    ], static fn(string $id): bool => $id !== '')));
                    sort($dbParticipants, SORT_STRING);
                    sort($participants, SORT_STRING);
                    if ($dbParticipants !== $participants) {
                        throw new RuntimeException('Staging test invite cleanup participant identity mismatch.');
                    }
                    if ((string)($row['status'] ?? '') !== $status
                        || trim((string)($row['match_id'] ?? '')) !== '') {
                        throw new RuntimeException('Staging test invite cleanup refuses changed or matched DB state.');
                    }
                    $matchRefs = (int)$db->fetchValue(
                        'SELECT COUNT(*) FROM mgw_matches WHERE invite_id = :invite_id OR source_match_id = :source_match_id',
                        ['invite_id' => $inviteId, 'source_match_id' => $inviteId]
                    );
                    if ($matchRefs !== 0) {
                        throw new RuntimeException('Staging test invite cleanup refuses a match-referenced invite.');
                    }

                    $remainingNotifications = $db->fetchAll(
                        'SELECT notification_id, legacy_user_id FROM mgw_notifications WHERE invite_token = :invite_token',
                        ['invite_token' => $token]
                    );
                    foreach ($remainingNotifications as $notification) {
                        $recipient = trim((string)($notification['legacy_user_id'] ?? ''));
                        if (!isset($testIds[$recipient])) {
                            throw new RuntimeException('Staging test invite cleanup refuses a non-test notification.');
                        }
                        throw new RuntimeException('Staging test invite cleanup found unexpected test notification residue.');
                    }

                    $eventCount = $db->execute(
                        'DELETE FROM mgw_invite_events WHERE invite_id = :invite_id',
                        ['invite_id' => $inviteId]
                    );
                    $deleted['invite_event_rows'] += $eventCount;

                    $inviteCount = $db->execute(
                        'DELETE FROM mgw_invites WHERE invite_id = :invite_id AND token = :token AND status = :status',
                        ['invite_id' => $inviteId, 'token' => $token, 'status' => $status]
                    );
                    if ($inviteCount !== 1) {
                        throw new RuntimeException('Staging test invite delete count is unexpected.');
                    }
                    $deleted['invite_rows'] += $inviteCount;
                }

                return $deleted;
            });
        }

        $this->assertTestInviteParity($snapshot, $database);
        return $deleted + ['parity' => true];
    }

    private function assertTestInviteParity(
        array $snapshot,
        DatabaseConnectionInterface $database
    ): void {
        $testIds = array_fill_keys(self::TEST_PLAYER_IDS, true);
        $jsonOpen = [];

        foreach (is_array($snapshot['invites'] ?? null) ? $snapshot['invites'] : [] as $invite) {
            if (!is_array($invite)) continue;
            $status = trim((string)($invite['status'] ?? ''));
            if (!in_array($status, self::OPEN_INVITE_STATUSES, true)) continue;
            if (trim((string)($invite['game_id'] ?? '')) !== '') continue;

            $participants = array_values(array_unique(array_filter([
                trim((string)($invite['inviter_id'] ?? '')),
                trim((string)($invite['invitee_id'] ?? '')),
            ], static fn(string $id): bool => $id !== '')));
            $hasTest = false;
            foreach ($participants as $participantId) {
                if (isset($testIds[$participantId])) {
                    $hasTest = true;
                    continue;
                }
                if ($hasTest || isset($testIds[(string)($invite['inviter_id'] ?? '')])
                    || isset($testIds[(string)($invite['invitee_id'] ?? '')])) {
                    throw new RuntimeException('Staging test invite parity refuses a mixed A/B invite.');
                }
            }
            if (!$hasTest) continue;
            foreach ($participants as $participantId) {
                if (!isset($testIds[$participantId])) {
                    throw new RuntimeException('Staging test invite parity refuses a non-test participant.');
                }
            }

            $inviteId = trim((string)($invite['id'] ?? ''));
            $token = trim((string)($invite['token'] ?? ''));
            if ($inviteId === '' || $token === '') {
                throw new RuntimeException('Staging test invite parity requires stable JSON identity.');
            }
            $jsonOpen[$inviteId . '|' . $token] = true;
        }

        $dbOpen = [];
        foreach ($database->fetchAll('SELECT * FROM mgw_invites ORDER BY invite_id') as $row) {
            $participants = array_values(array_unique(array_filter([
                trim((string)($row['inviter_legacy_user_id'] ?? '')),
                trim((string)($row['invitee_legacy_user_id'] ?? '')),
            ], static fn(string $id): bool => $id !== '')));
            $hasTest = false;
            foreach ($participants as $participantId) {
                if (isset($testIds[$participantId])) {
                    $hasTest = true;
                    break;
                }
            }
            if (!$hasTest) continue;

            $status = trim((string)($row['status'] ?? ''));
            if (!in_array($status, self::OPEN_INVITE_STATUSES, true)) continue;
            $inviteId = trim((string)($row['invite_id'] ?? ''));
            $token = trim((string)($row['token'] ?? ''));
            if ($inviteId === '' || $token === '') {
                throw new RuntimeException('Staging test invite parity requires stable DB identity.');
            }
            if (trim((string)($row['match_id'] ?? '')) !== '') continue;
            $matchRefs = (int)$database->fetchValue(
                'SELECT COUNT(*) FROM mgw_matches WHERE invite_id = :invite_id OR source_match_id = :source_match_id',
                ['invite_id' => $inviteId, 'source_match_id' => $inviteId]
            );
            if ($matchRefs !== 0) continue;

            foreach ($participants as $participantId) {
                if (!isset($testIds[$participantId])) {
                    throw new RuntimeException('Staging test invite parity refuses an unmatched mixed A/B DB invite.');
                }
            }
            $dbOpen[$inviteId . '|' . $token] = true;
        }

        $jsonKeys = array_keys($jsonOpen);
        $dbKeys = array_keys($dbOpen);
        sort($jsonKeys, SORT_STRING);
        sort($dbKeys, SORT_STRING);
        if ($jsonKeys !== $dbKeys) {
            throw new RuntimeException('Staging test invite cleanup did not restore A/B invite parity.');
        }
    }

    private function assertAvailable(array $server): void
    {
        if (strtolower(trim((string)($this->config['environment'] ?? ''))) !== 'staging') {
            throw new RuntimeException('Staging test-player reset is unavailable.');
        }

        $baseUrl = rtrim(trim((string)($this->config['base_url'] ?? '')), '/');
        $baseScheme = strtolower((string)(parse_url($baseUrl, PHP_URL_SCHEME) ?: ''));
        $baseHost = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        $requestHost = strtolower(trim((string)($server['HTTP_HOST'] ?? '')));
        if (str_contains($requestHost, ':')) $requestHost = explode(':', $requestHost, 2)[0];

        if ($baseScheme !== 'https'
            || $baseHost !== self::STAGING_HOST
            || $requestHost !== self::STAGING_HOST) {
            throw new RuntimeException('Staging test-player reset host mismatch.');
        }

        if (!empty($this->config['external_payments_enabled'])) {
            throw new RuntimeException('Staging test-player reset refuses live payments.');
        }
        foreach (['payment_mode', 'telegram_stars_mode', 'google_play_billing_mode'] as $key) {
            if (strtolower(trim((string)($this->config[$key] ?? ''))) === 'live') {
                throw new RuntimeException('Staging test-player reset refuses live payments.');
            }
        }
    }
}
