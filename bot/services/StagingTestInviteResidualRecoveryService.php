<?php
declare(strict_types=1);

final class StagingTestInviteResidualRecoveryService
{
    private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';
    private const TEST_PLAYER_IDS = ['stg_test_player_a', 'stg_test_player_b'];
    private const SAFE_RESIDUAL_STATUSES = [
        'draft', 'pending', 'awaiting_start', 'declined',
        'cancelled', 'expired', 'timed_out',
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
                foreach ($plan['private_candidates'] as $candidate) {
                    $invite = $candidate['invite'];
                    $inviteId = (string)$invite['invite_id'];
                    $token = (string)$invite['token'];

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
                            throw new RuntimeException('Staging test residual notification delete count is unexpected.');
                        }
                        $deleted['notification_rows'] += $count;
                    }

                    $eventCount = $db->execute(
                        'DELETE FROM mgw_invite_events WHERE invite_id = :invite_id',
                        ['invite_id' => $inviteId]
                    );
                    if ($eventCount !== count($candidate['events'])) {
                        throw new RuntimeException('Staging test residual invite-event delete count is unexpected.');
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
                        throw new RuntimeException('Staging test residual invite delete count is unexpected.');
                    }
                    $deleted['invite_rows'] += $inviteCount;
                }

                $inviteSync = (new RuntimeInviteRepository($this->config, $this->router, $db))
                    ->synchronize($snapshot);
                if (($inviteSync['parity'] ?? false) !== true) {
                    throw new RuntimeException('Staging test invite parity did not recover.');
                }

                $notificationCounts = [];
                foreach (self::TEST_PLAYER_IDS as $legacyUserId) {
                    $sync = (new RuntimeNotificationRepository($this->config, $this->router, $db))
                        ->synchronizeAndList($snapshot, $legacyUserId);
                    $summary = is_array($sync['summary'] ?? null) ? $sync['summary'] : [];
                    if (($summary['parity'] ?? false) !== true) {
                        throw new RuntimeException('Staging test notification parity did not recover.');
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
                    'deleted' => $deleted,
                    'parity' => [
                        'invites' => true,
                        'test_player_notifications' => true,
                    ],
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
        $expectedParticipants = self::TEST_PLAYER_IDS;
        sort($expectedParticipants, SORT_STRING);

        foreach ($database->fetchAll('SELECT * FROM mgw_invites ORDER BY invite_id' . $lock) as $invite) {
            $inviteId = trim((string)($invite['invite_id'] ?? ''));
            $token = trim((string)($invite['token'] ?? ''));
            if ($inviteId === '' || $token === '') {
                $blockers[] = 'Normalized invite identity is incomplete.';
                continue;
            }

            $idPresent = isset($sourceInviteIds[$inviteId]);
            $tokenPresent = isset($sourceInviteTokens[$token]);
            if ($idPresent xor $tokenPresent) {
                $blockers[] = 'Normalized invite identity partially conflicts with JSON.';
                continue;
            }
            if ($idPresent) continue;

            $participants = [
                trim((string)($invite['inviter_legacy_user_id'] ?? '')),
                trim((string)($invite['invitee_legacy_user_id'] ?? '')),
            ];
            sort($participants, SORT_STRING);
            if ($participants !== $expectedParticipants) {
                $blockers[] = 'A DB-only invite does not belong exclusively to TEST PLAYER A/B.';
                continue;
            }

            $status = trim((string)($invite['status'] ?? ''));
            if (!in_array($status, self::SAFE_RESIDUAL_STATUSES, true)) {
                $blockers[] = 'A DB-only staging test invite has an unsafe status.';
                continue;
            }
            if (trim((string)($invite['match_id'] ?? '')) !== '') {
                $blockers[] = 'A DB-only staging test invite is attached to a match.';
                continue;
            }

            $matchCount = (int)$database->fetchValue(
                'SELECT COUNT(*) FROM mgw_matches
                 WHERE invite_id = :invite_id OR source_match_id = :source_match_id',
                ['invite_id' => $inviteId, 'source_match_id' => $inviteId]
            );
            if ($matchCount !== 0) {
                $blockers[] = 'A DB-only staging test invite is referenced by a match.';
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
                    || !in_array($legacyUserId, self::TEST_PLAYER_IDS, true)) {
                    $blockers[] = 'A residual invite notification is not confined to TEST PLAYER A/B.';
                    $safe = false;
                    continue;
                }
                if (isset($sourceNotificationIds[$notificationId])
                    || isset($sourceNotificationEvents[$legacyUserId . '|' . $eventKey])) {
                    $blockers[] = 'A residual invite notification is still present in JSON.';
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
                'invite' => $invite,
                'notifications' => $notifications,
                'events' => $events,
            ];
        }

        if (count($candidates) > self::MAX_RESIDUAL_INVITES) {
            $blockers[] = 'Too many staging test invite residuals were found.';
        }
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);
        return ['blockers' => $blockers, 'private_candidates' => $candidates];
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
