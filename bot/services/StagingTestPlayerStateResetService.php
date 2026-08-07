<?php
declare(strict_types=1);

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
        $this->assertAvailable($server);

        $storage = StorageFactory::createJson((string)($this->config['data_dir'] ?? (__DIR__ . '/../data')));
        $before = [];
        $queueRemoved = 0;
        $removedInvites = [];
        $notificationsRemoved = 0;
        $gamesFinished = 0;

        $snapshot = $storage->transaction(function (array &$data) use (
            &$before,
            &$queueRemoved,
            &$removedInvites,
            &$notificationsRemoved,
            &$gamesFinished
        ): array {
            if (!isset($data['users']) || !is_array($data['users'])) {
                throw new RuntimeException('Staging test users are unavailable.');
            }

            foreach (self::TEST_PLAYER_IDS as $legacyUserId) {
                if (!isset($data['users'][$legacyUserId]) || !is_array($data['users'][$legacyUserId])) {
                    throw new RuntimeException('Staging test player is not initialized.');
                }
                $before[$legacyUserId] = (int)($data['users'][$legacyUserId]['balance_match'] ?? 0);
            }

            $testIds = array_fill_keys(self::TEST_PLAYER_IDS, true);
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
                $data['users'][$legacyUserId]['balance_match'] = self::MATCH_BALANCE;
            }

            foreach ((is_array($data['invites'] ?? null) ? $data['invites'] : []) as $index => $invite) {
                if (!is_array($invite)) continue;
                $status = (string)($invite['status'] ?? '');
                if (!in_array($status, self::OPEN_INVITE_STATUSES, true)) continue;
                if (trim((string)($invite['game_id'] ?? '')) !== '') continue;

                $participants = array_values(array_unique(array_filter([
                    trim((string)($invite['inviter_id'] ?? '')),
                    trim((string)($invite['invitee_id'] ?? '')),
                ], static fn(string $id): bool => $id !== '')));
                if ($participants === []) continue;

                $containsTestPlayer = false;
                foreach ($participants as $participantId) {
                    if (isset($testIds[$participantId])) {
                        $containsTestPlayer = true;
                        continue;
                    }
                    if ($containsTestPlayer
                        || isset($testIds[(string)($invite['inviter_id'] ?? '')])
                        || isset($testIds[(string)($invite['invitee_id'] ?? '')])) {
                        throw new RuntimeException('Staging test reset refuses an invite with a non-test player.');
                    }
                }
                if (!$containsTestPlayer) continue;

                $inviteId = trim((string)($invite['id'] ?? ''));
                $token = trim((string)($invite['token'] ?? ''));
                if ($inviteId === '' || $token === '') {
                    throw new RuntimeException('Staging test reset refuses an invite without stable identity.');
                }
                sort($participants, SORT_STRING);
                $removedInvites[] = [
                    'invite_id' => $inviteId,
                    'token' => $token,
                    'status' => $status === 'accepted' ? 'awaiting_start' : $status,
                    'participant_ids' => $participants,
                ];
                unset($data['invites'][$index]);
            }
            $data['invites'] = array_values(is_array($data['invites'] ?? null) ? $data['invites'] : []);

            if ($removedInvites !== []) {
                $removedTokens = [];
                foreach ($removedInvites as $removedInvite) {
                    $removedTokens[(string)$removedInvite['token']] = true;
                }
                $notificationsBefore = count(is_array($data['notifications'] ?? null) ? $data['notifications'] : []);
                $data['notifications'] = array_values(array_filter(
                    is_array($data['notifications'] ?? null) ? $data['notifications'] : [],
                    static function ($notification) use ($testIds, $removedTokens): bool {
                        if (!is_array($notification)) return true;
                        $userId = (string)($notification['user_id'] ?? '');
                        $token = trim((string)($notification['invite_token'] ?? ''));
                        return !isset($testIds[$userId]) || $token === '' || !isset($removedTokens[$token]);
                    }
                ));
                $notificationsRemoved = $notificationsBefore - count($data['notifications']);
            }

            return $data;
        });

        $inviteCleanup = $this->cleanupRuntimeInviteRows($snapshot, $removedInvites);

        $economy = new RuntimeEconomyRepository($this->config, $this->router);
        $synchronized = $economy->synchronize($snapshot);
        $audit = $economy->auditParity($snapshot);
        if (($synchronized['ok'] ?? false) !== true || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Staging test-player economy reset did not reach parity.');
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
            'match_balance' => self::MATCH_BALANCE,
            'players' => $balances,
            'queue_removed' => $queueRemoved,
            'open_invites_removed' => count($removedInvites),
            'notifications_removed' => $notificationsRemoved,
            'active_test_games_finished' => $gamesFinished,
            'invite_db_rows_removed' => (int)($inviteCleanup['invite_rows'] ?? 0),
            'invite_event_db_rows_removed' => (int)($inviteCleanup['invite_event_rows'] ?? 0),
            'notification_db_rows_removed' => (int)($inviteCleanup['notification_rows'] ?? 0),
            'invite_parity' => ($inviteCleanup['parity'] ?? false) === true,
            'economy_parity' => true,
            'production_changed' => false,
            'live_payments_used' => false,
        ];
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

        return $database->transaction(function (DatabaseConnectionInterface $db) use (
            $snapshot,
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

                $notifications = $db->fetchAll(
                    'SELECT notification_id, legacy_user_id FROM mgw_notifications WHERE invite_token = :invite_token',
                    ['invite_token' => $token]
                );
                foreach ($notifications as $notification) {
                    $recipient = trim((string)($notification['legacy_user_id'] ?? ''));
                    if (!isset($testIds[$recipient])) {
                        throw new RuntimeException('Staging test invite cleanup refuses a non-test notification.');
                    }
                    $count = $db->execute(
                        'DELETE FROM mgw_notifications WHERE notification_id = :notification_id AND invite_token = :invite_token',
                        [
                            'notification_id' => (string)($notification['notification_id'] ?? ''),
                            'invite_token' => $token,
                        ]
                    );
                    if ($count !== 1) {
                        throw new RuntimeException('Staging test invite notification delete count is unexpected.');
                    }
                    $deleted['notification_rows'] += $count;
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

            $inviteSync = (new RuntimeInviteRepository($this->config, $this->router, $db))->synchronize($snapshot);
            if (($inviteSync['parity'] ?? false) !== true) {
                throw new RuntimeException('Staging test invite cleanup did not restore invite parity.');
            }
            foreach (self::TEST_PLAYER_IDS as $legacyUserId) {
                $notificationSync = (new RuntimeNotificationRepository($this->config, $this->router, $db))
                    ->synchronizeAndList($snapshot, $legacyUserId);
                $summary = is_array($notificationSync['summary'] ?? null) ? $notificationSync['summary'] : [];
                if (($summary['parity'] ?? false) !== true) {
                    throw new RuntimeException('Staging test invite cleanup did not restore notification parity.');
                }
            }

            return $deleted + ['parity' => true];
        });
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
