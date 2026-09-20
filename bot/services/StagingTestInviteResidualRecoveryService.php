<?php
declare(strict_types=1);

final class StagingTestInviteResidualRecoveryService
{
    private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';
    private const TEST_PLAYER_IDS = ['stg_test_player_a', 'stg_test_player_b'];
    private const TEST_PLAYER_SAFE_STATUSES = [
        'draft', 'pending', 'awaiting_start', 'declined',
        'cancelled', 'expired', 'timed_out',
    ];
    private const TERMINAL_SAFE_STATUSES = [
        'declined', 'cancelled', 'expired', 'timed_out',
    ];
    private const MAX_RESIDUAL_INVITES = 20;

    private RuntimeStorageRouter $router;

    public function __construct(
        private array $config,
        ?RuntimeStorageRouter $router = null,
        private ?DatabaseConnectionInterface $database = null
    ) {
        $this->router = $router ?? new RuntimeStorageRouter($config);
    }

    public function diagnose(array $server): array
    {
        $this->assertAvailable($server);

        return $this->withLock(function (): array {
            $snapshot = $this->snapshot();
            $plan = $this->inspect($snapshot, $this->database(), false);
            $candidateCount = count($plan['private_candidates']);
            $blockers = array_values($plan['blockers']);
            $status = $blockers !== []
                ? 'blocked'
                : ($candidateCount > 0 ? 'recoverable' : 'already_clean');

            return [
                'ok' => true,
                'service' => 'mini-games-world-staging-test-invite-residual-diagnosis',
                'read_only' => true,
                'status' => $status,
                'recovery_ready' => $blockers === [],
                'candidate_count' => $candidateCount,
                'test_player_candidate_count' => (int)$plan['test_player_candidate_count'],
                'terminal_staging_candidate_count' => (int)$plan['terminal_staging_candidate_count'],
                'blocker_codes' => $blockers,
                'production_changed' => false,
                'live_payments_used' => false,
            ];
        });
    }

    public function reconcile(array $server): array
    {
        $this->assertAvailable($server);

        return $this->withLock(function (): array {
            $snapshot = $this->snapshot();
            $database = $this->database();

            return $database->transaction(function (DatabaseConnectionInterface $db) use ($snapshot): array {
                $plan = $this->inspect($snapshot, $db, true);
                if ($plan['blockers'] !== []) {
                    throw new RuntimeException('Staging test invite residual recovery is blocked.');
                }

                $deleted = ['invite_rows' => 0, 'notification_rows' => 0, 'invite_event_rows' => 0];
                $notificationUserIds = array_fill_keys(self::TEST_PLAYER_IDS, true);

                foreach ($plan['private_candidates'] as $candidate) {
                    $invite = $candidate['invite'];
                    $inviteId = (string)$invite['invite_id'];
                    $token = (string)$invite['token'];
                    foreach ($candidate['participant_ids'] as $legacyUserId) {
                        $notificationUserIds[(string)$legacyUserId] = true;
                    }

                    foreach ($candidate['notifications'] as $notification) {
                        $count = $db->execute(
                            'DELETE FROM mgw_notifications
                             WHERE notification_id = :notification_id
                               AND invite_token = :invite_token
                               AND legacy_user_id = :legacy_user_id',
                            [
                                'notification_id' => (string)$notification['notification_id'],
                                'invite_token' => $token,
                                'legacy_user_id' => (string)$notification['legacy_user_id'],
                            ]
                        );
                        if ($count !== 1) {
                            throw new RuntimeException('Staging residual notification delete count is unexpected.');
                        }
                        $deleted['notification_rows'] += $count;
                    }

                    $eventCount = $db->execute(
                        'DELETE FROM mgw_invite_events WHERE invite_id = :invite_id',
                        ['invite_id' => $inviteId]
                    );
                    if ($eventCount !== count($candidate['events'])) {
                        throw new RuntimeException('Staging residual invite-event delete count is unexpected.');
                    }
                    $deleted['invite_event_rows'] += $eventCount;

                    $inviteCount = $db->execute(
                        'DELETE FROM mgw_invites
                         WHERE invite_id = :invite_id
                           AND token = :token
                           AND status = :status',
                        [
                            'invite_id' => $inviteId,
                            'token' => $token,
                            'status' => (string)$invite['status'],
                        ]
                    );
                    if ($inviteCount !== 1) {
                        throw new RuntimeException('Staging residual invite delete count is unexpected.');
                    }
                    $deleted['invite_rows'] += $inviteCount;
                }

                $inviteSync = (new RuntimeInviteRepository($this->config, $this->router, $db))
                    ->synchronize($snapshot);
                if (($inviteSync['parity'] ?? false) !== true) {
                    throw new RuntimeException('Staging invite parity did not recover.');
                }

                $notificationCounts = [];
                $notificationUserIds = array_keys($notificationUserIds);
                sort($notificationUserIds, SORT_STRING);
                foreach ($notificationUserIds as $legacyUserId) {
                    $sync = (new RuntimeNotificationRepository($this->config, $this->router, $db))
                        ->synchronizeAndList($snapshot, $legacyUserId);
                    $summary = is_array($sync['summary'] ?? null) ? $sync['summary'] : [];
                    if (($summary['parity'] ?? false) !== true) {
                        throw new RuntimeException('Staging scoped notification parity did not recover.');
                    }
                    $notificationCounts[] = [
                        'source_count' => (int)($summary['source_count'] ?? 0),
                        'database_count' => (int)($summary['database_count'] ?? 0),
                        'created_count' => (int)($summary['created_count'] ?? 0),
                    ];
                }

                return [
                    'ok' => true,
                    'service' => 'mini-games-world-staging-test-invite-residual-recovery',
                    'status' => $deleted['invite_rows'] > 0 ? 'recovered' : 'already_clean',
                    'candidate_count' => count($plan['private_candidates']),
                    'test_player_candidate_count' => (int)$plan['test_player_candidate_count'],
                    'terminal_staging_candidate_count' => (int)$plan['terminal_staging_candidate_count'],
                    'deleted' => $deleted,
                    'parity' => [
                        'invites' => true,
                        'scoped_notifications' => true,
                        'test_player_notifications' => true,
                    ],
                    'notification_account_count' => count($notificationUserIds),
                    'notification_counts' => $notificationCounts,
                    'production_changed' => false,
                    'live_payments_used' => false,
                ];
            });
        });
    }

    private function inspect(
        array $snapshot,
        DatabaseConnectionInterface $database,
        bool $forUpdate
    ): array {
        [$sourceInviteIds, $sourceInviteTokens] = $this->sourceInviteIdentity($snapshot);
        [$sourceNotificationIds, $sourceNotificationEvents] = $this->sourceNotificationIdentity($snapshot);
        $lock = $forUpdate && $database->driver() === 'mysql' ? ' FOR UPDATE' : '';
        $blockers = [];
        $candidates = [];
        $testPlayerCandidateCount = 0;
        $terminalStagingCandidateCount = 0;
        $expectedTestParticipants = self::TEST_PLAYER_IDS;
        sort($expectedTestParticipants, SORT_STRING);

        foreach ($database->fetchAll('SELECT * FROM mgw_invites ORDER BY invite_id' . $lock) as $invite) {
            $inviteId = trim((string)($invite['invite_id'] ?? ''));
            $token = trim((string)($invite['token'] ?? ''));
            if ($inviteId === '' || $token === '') {
                $blockers[] = 'invite_identity_incomplete';
                continue;
            }

            $idPresent = isset($sourceInviteIds[$inviteId]);
            $tokenPresent = isset($sourceInviteTokens[$token]);
            if ($idPresent xor $tokenPresent) {
                $blockers[] = 'invite_identity_partial_conflict';
                continue;
            }
            if ($idPresent) continue;

            $inviterId = trim((string)($invite['inviter_legacy_user_id'] ?? ''));
            $inviteeId = trim((string)($invite['invitee_legacy_user_id'] ?? ''));
            $participantIds = array_values(array_unique(array_filter(
                [$inviterId, $inviteeId],
                static fn(string $value): bool => $value !== ''
            )));
            sort($participantIds, SORT_STRING);
            if ($inviterId === '' || $participantIds === []) {
                $blockers[] = 'invite_participant_identity_incomplete';
                continue;
            }

            $isExactTestPair = $participantIds === $expectedTestParticipants;
            $status = trim((string)($invite['status'] ?? ''));
            if ($isExactTestPair) {
                if (!in_array($status, self::TEST_PLAYER_SAFE_STATUSES, true)) {
                    $blockers[] = 'invite_unsafe_status';
                    continue;
                }
            } elseif (!in_array($status, self::TERMINAL_SAFE_STATUSES, true)) {
                $blockers[] = 'invite_non_test_nonterminal';
                continue;
            }

            $ownershipByLegacyId = $this->validateInviteOwnership(
                $database,
                $invite,
                $participantIds,
                $lock,
                $blockers
            );
            if ($ownershipByLegacyId === null) continue;

            if (trim((string)($invite['match_id'] ?? '')) !== '') {
                $blockers[] = 'invite_attached_to_match';
                continue;
            }

            $matchCount = (int)$database->fetchValue(
                'SELECT COUNT(*) FROM mgw_matches
                 WHERE invite_id = :invite_id OR source_match_id = :source_match_id',
                ['invite_id' => $inviteId, 'source_match_id' => $inviteId]
            );
            if ($matchCount !== 0) {
                $blockers[] = 'invite_referenced_by_match';
                continue;
            }

            $notifications = $database->fetchAll(
                'SELECT * FROM mgw_notifications
                 WHERE invite_token = :invite_token
                 ORDER BY notification_id' . $lock,
                ['invite_token' => $token]
            );
            $safe = true;
            foreach ($notifications as $notification) {
                $notificationId = trim((string)($notification['notification_id'] ?? ''));
                $eventKey = trim((string)($notification['event_key'] ?? ''));
                $legacyUserId = trim((string)($notification['legacy_user_id'] ?? ''));
                if ($notificationId === ''
                    || $eventKey === ''
                    || !isset($ownershipByLegacyId[$legacyUserId])) {
                    $blockers[] = 'notification_not_invite_participant';
                    $safe = false;
                    continue;
                }
                $ownership = $ownershipByLegacyId[$legacyUserId];
                if (!hash_equals(
                    (string)$ownership['account_ref'],
                    trim((string)($notification['recipient_ref'] ?? ''))
                ) || !hash_equals(
                    (string)$ownership['mgw_id'],
                    trim((string)($notification['mgw_id'] ?? ''))
                )) {
                    $blockers[] = 'notification_ownership_mismatch';
                    $safe = false;
                    continue;
                }
                if (isset($sourceNotificationIds[$notificationId])
                    || isset($sourceNotificationEvents[$legacyUserId . '|' . $eventKey])) {
                    $blockers[] = 'notification_still_in_json';
                    $safe = false;
                }
            }
            if (!$safe) continue;

            $events = $database->fetchAll(
                'SELECT * FROM mgw_invite_events
                 WHERE invite_id = :invite_id ORDER BY event_id' . $lock,
                ['invite_id' => $inviteId]
            );
            $candidates[] = [
                'scope' => $isExactTestPair ? 'test_players' : 'terminal_staging',
                'invite' => $invite,
                'participant_ids' => $participantIds,
                'notifications' => $notifications,
                'events' => $events,
            ];
            $isExactTestPair ? $testPlayerCandidateCount++ : $terminalStagingCandidateCount++;
        }

        if (count($candidates) > self::MAX_RESIDUAL_INVITES) {
            $blockers[] = 'residual_limit_exceeded';
        }
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);
        return [
            'blockers' => $blockers,
            'private_candidates' => $candidates,
            'test_player_candidate_count' => $testPlayerCandidateCount,
            'terminal_staging_candidate_count' => $terminalStagingCandidateCount,
        ];
    }

    private function validateInviteOwnership(
        DatabaseConnectionInterface $database,
        array $invite,
        array $participantIds,
        string $lock,
        array &$blockers
    ): ?array {
        $roles = [
            trim((string)($invite['inviter_legacy_user_id'] ?? '')) => [
                'account_ref' => trim((string)($invite['inviter_ref'] ?? '')),
                'mgw_id' => trim((string)($invite['inviter_mgw_id'] ?? '')),
            ],
        ];
        $inviteeId = trim((string)($invite['invitee_legacy_user_id'] ?? ''));
        if ($inviteeId !== '') {
            $roles[$inviteeId] = [
                'account_ref' => trim((string)($invite['invitee_ref'] ?? '')),
                'mgw_id' => trim((string)($invite['invitee_mgw_id'] ?? '')),
            ];
        }

        $ownershipByLegacyId = [];
        foreach ($participantIds as $legacyUserId) {
            $rows = $database->fetchAll(
                'SELECT account_ref, mgw_id, ownership_status
                 FROM mgw_account_ownership
                 WHERE legacy_user_id = :legacy_user_id' . $lock,
                ['legacy_user_id' => $legacyUserId]
            );
            if (count($rows) !== 1
                || (string)($rows[0]['ownership_status'] ?? '') !== 'active') {
                $blockers[] = 'invite_participant_ownership_invalid';
                return null;
            }
            $actual = [
                'account_ref' => trim((string)($rows[0]['account_ref'] ?? '')),
                'mgw_id' => trim((string)($rows[0]['mgw_id'] ?? '')),
            ];
            $expected = $roles[$legacyUserId] ?? null;
            if (!is_array($expected)
                || $actual['account_ref'] === ''
                || $actual['mgw_id'] === ''
                || !hash_equals($actual['account_ref'], (string)$expected['account_ref'])
                || !hash_equals($actual['mgw_id'], (string)$expected['mgw_id'])) {
                $blockers[] = 'invite_participant_ownership_mismatch';
                return null;
            }
            $ownershipByLegacyId[$legacyUserId] = $actual;
        }
        return $ownershipByLegacyId;
    }

    private function sourceInviteIdentity(array $snapshot): array
    {
        $ids = [];
        $tokens = [];
        foreach (is_array($snapshot['invites'] ?? null) ? $snapshot['invites'] : [] as $invite) {
            if (!is_array($invite)) {
                throw new RuntimeException('Staging test JSON invite row is invalid.');
            }
            $id = trim((string)($invite['id'] ?? ''));
            $token = trim((string)($invite['token'] ?? ''));
            if ($id === '' || $token === '' || isset($ids[$id]) || isset($tokens[$token])) {
                throw new RuntimeException('Staging test JSON invite identity is invalid or duplicated.');
            }
            $ids[$id] = true;
            $tokens[$token] = true;
        }
        return [$ids, $tokens];
    }

    private function sourceNotificationIdentity(array $snapshot): array
    {
        $ids = [];
        $events = [];
        foreach (is_array($snapshot['notifications'] ?? null) ? $snapshot['notifications'] : [] as $notification) {
            if (!is_array($notification)) continue;
            $id = trim((string)($notification['id'] ?? ''));
            $eventKey = trim((string)($notification['event_key'] ?? ''));
            $legacyUserId = trim((string)($notification['user_id'] ?? ''));
            if ($id === '' || $eventKey === '' || $legacyUserId === '') continue;
            if (isset($ids[$id])) {
                throw new RuntimeException('Staging test JSON notification identity is duplicated.');
            }
            $ids[$id] = true;
            $events[$legacyUserId . '|' . $eventKey] = true;
        }
        return [$ids, $events];
    }

    private function snapshot(): array
    {
        $dataDir = rtrim(trim((string)($this->config['data_dir'] ?? '')), '/\\');
        if ($dataDir === '') throw new RuntimeException('Staging test data directory is unavailable.');
        $storage = StorageFactory::createJson($dataDir);
        $snapshot = $storage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) throw new RuntimeException('Staging test JSON snapshot is unavailable.');
        return $snapshot;
    }

    private function database(): DatabaseConnectionInterface
    {
        if ($this->database !== null) return $this->database;
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Staging test invite recovery requires an enabled database.');
        }
        return $this->database = PdoConnectionFactory::create($databaseConfig);
    }

    private function assertAvailable(array $server): void
    {
        $value = $this->config['environment'] ?? '';
        $environment = $value instanceof BackedEnum
            ? strtolower(trim((string)$value->value))
            : strtolower(trim((string)$value));
        $baseUrl = rtrim(trim((string)($this->config['base_url'] ?? '')), '/');
        $baseScheme = strtolower((string)(parse_url($baseUrl, PHP_URL_SCHEME) ?: ''));
        $baseHost = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        $requestHost = strtolower(trim((string)($server['HTTP_HOST'] ?? '')));
        if (str_contains($requestHost, ':')) $requestHost = explode(':', $requestHost, 2)[0];

        if ($environment !== 'staging'
            || $baseScheme !== 'https'
            || $baseHost !== self::STAGING_HOST
            || $requestHost !== self::STAGING_HOST) {
            throw new RuntimeException('Staging test invite recovery is unavailable.');
        }
        if (!$this->router->enabled()
            || $this->router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
            || $this->router->routeFor('notifications') !== RuntimeStorageRouter::DRIVER_DATABASE
            || $this->router->routeFor('invites') !== RuntimeStorageRouter::DRIVER_DATABASE) {
            throw new RuntimeException('Staging test invite recovery requires DB runtime routing.');
        }
        if (!empty($this->config['external_payments_enabled'])) {
            throw new RuntimeException('Staging test invite recovery refuses live payments.');
        }
        foreach (['payment_mode', 'telegram_stars_mode', 'google_play_billing_mode'] as $key) {
            if (strtolower(trim((string)($this->config[$key] ?? ''))) === 'live') {
                throw new RuntimeException('Staging test invite recovery refuses live payments.');
            }
        }
    }

    private function withLock(Closure $callback): mixed
    {
        $dataDir = rtrim(trim((string)($this->config['data_dir'] ?? '')), '/\\');
        if ($dataDir === '') throw new RuntimeException('Staging test invite recovery lock directory is unavailable.');
        $directory = $dataDir . '/.runtime/staging-test-auth';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Staging test invite recovery lock directory cannot be created.');
        }
        $path = $directory . '/invite-residual-recovery.lock';
        $handle = @fopen($path, 'c+');
        if ($handle === false) throw new RuntimeException('Staging test invite recovery lock cannot be opened.');
        @chmod($path, 0600);

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Staging test invite recovery lock is unavailable.');
            }
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
