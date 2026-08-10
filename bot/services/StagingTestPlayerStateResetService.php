<?php
declare(strict_types=1);

final class StagingTestPlayerResetStageException extends RuntimeException
{
    private const ALLOWED_STAGES = [
        'availability',
        'json_state',
        'notification_cleanup',
        'invite_cleanup',
        'invite_cleanup_parity_db_missing',
        'invite_cleanup_parity_db_extra',
        'invite_cleanup_parity_fingerprint',
        'invite_cleanup_parity_unknown',
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
        $notificationsRemoved = 0;
        $gamesFinished = 0;

        try {
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

            $testIds = array_fill_keys(self::TEST_PLAYER_IDS, true);
            foreach (self::TEST_PLAYER_IDS as $legacyUserId) {
                if (!isset($data['users'][$legacyUserId]) || !is_array($data['users'][$legacyUserId])) {
                    throw new RuntimeException('Staging test player is not initialized.');
                }
                $before[$legacyUserId] = (int)($data['users'][$legacyUserId]['balance_match'] ?? 0);
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

                $hasTestParticipant = false;
                foreach ($participants as $participantId) {
                    if (isset($testIds[$participantId])) {
                        $hasTestParticipant = true;
                        continue;
                    }
                    if (isset($testIds[(string)($invite['inviter_id'] ?? '')])
                        || isset($testIds[(string)($invite['invitee_id'] ?? '')])) {
                        throw new RuntimeException('Staging test reset refuses an invite with a non-test player.');
                    }
                }
                if (!$hasTestParticipant) continue;
                foreach ($participants as $participantId) {
                    if (!isset($testIds[$participantId])) {
                        throw new RuntimeException('Staging test reset refuses an invite with a non-test player.');
                    }
                }

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

        // Notification cleanup must commit before invite parity audits. The JSON
        // snapshot above contains no A/B notification history by contract.
        try {
            $notificationCleanup = $this->cleanupRuntimeTestNotificationRows($snapshot);
        } catch (Throwable $error) {
            throw new StagingTestPlayerResetStageException('notification_cleanup', $error);
        }
        try {
            $inviteCleanup = $this->cleanupRuntimeInviteRows($snapshot, $removedInvites);
        } catch (StagingTestPlayerResetStageException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new StagingTestPlayerResetStageException('invite_cleanup', $error);
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
            'match_balance' => self::MATCH_BALANCE,
            'players' => $balances,
            'queue_removed' => $queueRemoved,
            'open_invites_removed' => count($removedInvites),
            'notifications_removed' => $notificationsRemoved,
            'active_test_games_finished' => $gamesFinished,
            'invite_db_rows_removed' => (int)($inviteCleanup['invite_rows'] ?? 0),
            'invite_event_db_rows_removed' => (int)($inviteCleanup['invite_event_rows'] ?? 0),
            'notification_db_rows_removed' => (int)($notificationCleanup['notification_rows'] ?? 0)
                + (int)($inviteCleanup['notification_rows'] ?? 0),
            'invite_parity' => ($inviteCleanup['parity'] ?? false) === true,
            'notification_parity' => ($notificationCleanup['parity'] ?? false) === true,
            'economy_parity' => true,
            'production_changed' => false,
            'live_payments_used' => false,
        ];
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

        // Audit only after the exact technical-account delete transaction commits.
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
        if ($removedInvites === []) {
            $this->assertInviteParity($snapshot);
            return [
                'invite_rows' => 0,
                'invite_event_rows' => 0,
                'notification_rows' => 0,
                'parity' => true,
            ];
        }

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

                // All A/B notification rows were removed by the dedicated cleanup
                // before this transaction. Any remaining row for the invite is unsafe.
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

        $this->assertInviteParity($snapshot);
        return $deleted + ['parity' => true];
    }

    private function assertInviteParity(array $snapshot): void
    {
        if (!$this->router->enabled()
            || $this->router->routeFor('invites') !== RuntimeStorageRouter::DRIVER_DATABASE) {
            return;
        }
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Staging test invite parity requires an enabled database.');
        }
        $database = PdoConnectionFactory::create($databaseConfig);
        $inviteAudit = (new RuntimeInviteRepository($this->config, $this->router, $database))
            ->auditParity($snapshot);
        if (($inviteAudit['ok'] ?? false) !== true) {
            $sourceCount = (int)($inviteAudit['source_count'] ?? -1);
            $databaseCount = (int)($inviteAudit['database_count'] ?? -1);
            $sourceFingerprint = (string)($inviteAudit['source_fingerprint'] ?? '');
            $databaseFingerprint = (string)($inviteAudit['database_fingerprint'] ?? '');

            if ($sourceCount >= 0 && $databaseCount >= 0 && $sourceCount > $databaseCount) {
                $stage = 'invite_cleanup_parity_db_missing';
            } elseif ($sourceCount >= 0 && $databaseCount >= 0 && $databaseCount > $sourceCount) {
                $stage = 'invite_cleanup_parity_db_extra';
            } elseif ($sourceFingerprint !== ''
                && $databaseFingerprint !== ''
                && !hash_equals($sourceFingerprint, $databaseFingerprint)) {
                $stage = 'invite_cleanup_parity_fingerprint';
            } else {
                $stage = 'invite_cleanup_parity_unknown';
            }

            throw new StagingTestPlayerResetStageException(
                $stage,
                new RuntimeException('Staging test invite cleanup did not restore invite parity.')
            );
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
