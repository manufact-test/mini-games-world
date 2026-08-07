<?php
declare(strict_types=1);

final class StagingStaleInviteOrphanRecoveryService
{
    private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';
    private const TEST_PLAYER_IDS = ['stg_test_player_a', 'stg_test_player_b'];
    private const RECOVERABLE_STATUSES = ['draft', 'pending', 'awaiting_start'];
    private const RECOVERABLE_SOURCES = ['direct', 'link'];
    private const STALE_AFTER_SEC = 1200;

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
        $snapshot = $this->snapshot();
        $database = $this->database();
        [$sourceInviteIds, $sourceInviteTokens] = $this->sourceInviteIdentity($snapshot);
        [$sourceNotificationIds, $sourceNotificationEvents] = $this->sourceNotificationIdentity($snapshot);

        return $database->transaction(function (DatabaseConnectionInterface $db) use (
            $snapshot,
            $sourceInviteIds,
            $sourceInviteTokens,
            $sourceNotificationIds,
            $sourceNotificationEvents
        ): array {
            $lock = $db->driver() === 'mysql' ? ' FOR UPDATE' : '';
            $candidates = [];

            foreach ($db->fetchAll('SELECT * FROM mgw_invites ORDER BY invite_id' . $lock) as $invite) {
                $inviteId = trim((string)($invite['invite_id'] ?? ''));
                $token = trim((string)($invite['token'] ?? ''));
                if ($inviteId === '' || $token === '') continue;

                $idPresent = isset($sourceInviteIds[$inviteId]);
                $tokenPresent = isset($sourceInviteTokens[$token]);
                if ($idPresent || $tokenPresent) continue;

                $status = trim((string)($invite['status'] ?? ''));
                $source = trim((string)($invite['source'] ?? ''));
                if (!in_array($status, self::RECOVERABLE_STATUSES, true)
                    || !in_array($source, self::RECOVERABLE_SOURCES, true)) {
                    continue;
                }

                $inviterId = trim((string)($invite['inviter_legacy_user_id'] ?? ''));
                $inviteeId = trim((string)($invite['invitee_legacy_user_id'] ?? ''));
                $participantIds = array_values(array_unique(array_filter(
                    [$inviterId, $inviteeId],
                    static fn(string $value): bool => $value !== ''
                )));
                if ($inviterId === '' || $participantIds === []) continue;
                foreach ($participantIds as $participantId) {
                    if (in_array($participantId, self::TEST_PLAYER_IDS, true)) {
                        continue 2;
                    }
                }

                if (!$this->isStale($invite)) continue;
                if (trim((string)($invite['match_id'] ?? '')) !== '') continue;

                $matchCount = (int)$db->fetchValue(
                    'SELECT COUNT(*) FROM mgw_matches
                     WHERE invite_id = :invite_id OR source_match_id = :source_match_id',
                    ['invite_id' => $inviteId, 'source_match_id' => $inviteId]
                );
                if ($matchCount !== 0) continue;

                if (!$this->ownershipMatches($db, $invite, $participantIds, $lock)) continue;

                $notifications = $db->fetchAll(
                    'SELECT * FROM mgw_notifications
                     WHERE invite_token = :invite_token ORDER BY notification_id' . $lock,
                    ['invite_token' => $token]
                );
                foreach ($notifications as $notification) {
                    $notificationId = trim((string)($notification['notification_id'] ?? ''));
                    $eventKey = trim((string)($notification['event_key'] ?? ''));
                    $legacyUserId = trim((string)($notification['legacy_user_id'] ?? ''));
                    if ($notificationId === ''
                        || $eventKey === ''
                        || !in_array($legacyUserId, $participantIds, true)
                        || isset($sourceNotificationIds[$notificationId])
                        || isset($sourceNotificationEvents[$legacyUserId . '|' . $eventKey])) {
                        continue 2;
                    }
                }

                $events = $db->fetchAll(
                    'SELECT * FROM mgw_invite_events WHERE invite_id = :invite_id ORDER BY event_id' . $lock,
                    ['invite_id' => $inviteId]
                );
                $candidates[] = [
                    'invite' => $invite,
                    'participant_ids' => $participantIds,
                    'notifications' => $notifications,
                    'events' => $events,
                ];
            }

            if (count($candidates) > 1) {
                throw new RuntimeException('Staging stale invite orphan recovery refuses multiple candidates.');
            }
            if ($candidates === []) {
                return [
                    'ok' => true,
                    'service' => 'mini-games-world-staging-stale-invite-orphan-recovery',
                    'status' => 'already_clean',
                    'candidate_count' => 0,
                    'deleted' => ['invite_rows' => 0, 'notification_rows' => 0, 'invite_event_rows' => 0],
                    'parity' => null,
                    'production_changed' => false,
                    'live_payments_used' => false,
                ];
            }

            $candidate = $candidates[0];
            $invite = $candidate['invite'];
            $inviteId = (string)$invite['invite_id'];
            $token = (string)$invite['token'];
            $deleted = ['invite_rows' => 0, 'notification_rows' => 0, 'invite_event_rows' => 0];

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
                    throw new RuntimeException('Staging stale orphan notification delete count is unexpected.');
                }
                $deleted['notification_rows'] += $count;
            }

            $eventCount = $db->execute(
                'DELETE FROM mgw_invite_events WHERE invite_id = :invite_id',
                ['invite_id' => $inviteId]
            );
            if ($eventCount !== count($candidate['events'])) {
                throw new RuntimeException('Staging stale orphan invite-event delete count is unexpected.');
            }
            $deleted['invite_event_rows'] = $eventCount;

            $inviteCount = $db->execute(
                'DELETE FROM mgw_invites
                 WHERE invite_id = :invite_id AND token = :token AND status = :status',
                [
                    'invite_id' => $inviteId,
                    'token' => $token,
                    'status' => (string)$invite['status'],
                ]
            );
            if ($inviteCount !== 1) {
                throw new RuntimeException('Staging stale orphan invite delete count is unexpected.');
            }
            $deleted['invite_rows'] = $inviteCount;

            $inviteSync = (new RuntimeInviteRepository($this->config, $this->router, $db))
                ->synchronize($snapshot);
            if (($inviteSync['parity'] ?? false) !== true) {
                throw new RuntimeException('Staging stale orphan invite parity did not recover.');
            }

            foreach ($candidate['participant_ids'] as $legacyUserId) {
                $notificationSync = (new RuntimeNotificationRepository($this->config, $this->router, $db))
                    ->synchronizeAndList($snapshot, (string)$legacyUserId);
                $summary = is_array($notificationSync['summary'] ?? null)
                    ? $notificationSync['summary']
                    : [];
                if (($summary['parity'] ?? false) !== true) {
                    throw new RuntimeException('Staging stale orphan notification parity did not recover.');
                }
            }

            return [
                'ok' => true,
                'service' => 'mini-games-world-staging-stale-invite-orphan-recovery',
                'status' => 'recovered',
                'candidate_count' => 1,
                'deleted' => $deleted,
                'parity' => ['invites' => true, 'scoped_notifications' => true],
                'production_changed' => false,
                'live_payments_used' => false,
            ];
        });
    }

    private function isStale(array $invite): bool
    {
        $now = time();
        $expiresAt = strtotime((string)($invite['expires_at_utc'] ?? '')) ?: 0;
        if ($expiresAt > 0) return $expiresAt < $now;
        $updatedAt = strtotime((string)($invite['updated_at_utc'] ?? '')) ?: 0;
        return $updatedAt > 0 && ($now - $updatedAt) > self::STALE_AFTER_SEC;
    }

    private function ownershipMatches(
        DatabaseConnectionInterface $database,
        array $invite,
        array $participantIds,
        string $lock
    ): bool {
        $expected = [
            trim((string)($invite['inviter_legacy_user_id'] ?? '')) => [
                'account_ref' => trim((string)($invite['inviter_ref'] ?? '')),
                'mgw_id' => trim((string)($invite['inviter_mgw_id'] ?? '')),
            ],
        ];
        $inviteeId = trim((string)($invite['invitee_legacy_user_id'] ?? ''));
        if ($inviteeId !== '') {
            $expected[$inviteeId] = [
                'account_ref' => trim((string)($invite['invitee_ref'] ?? '')),
                'mgw_id' => trim((string)($invite['invitee_mgw_id'] ?? '')),
            ];
        }

        foreach ($participantIds as $legacyUserId) {
            $rows = $database->fetchAll(
                'SELECT account_ref, mgw_id, ownership_status
                 FROM mgw_account_ownership
                 WHERE legacy_user_id = :legacy_user_id' . $lock,
                ['legacy_user_id' => $legacyUserId]
            );
            if (count($rows) !== 1 || (string)($rows[0]['ownership_status'] ?? '') !== 'active') {
                return false;
            }
            $want = $expected[$legacyUserId] ?? null;
            if (!is_array($want)
                || !hash_equals((string)$want['account_ref'], trim((string)($rows[0]['account_ref'] ?? '')))
                || !hash_equals((string)$want['mgw_id'], trim((string)($rows[0]['mgw_id'] ?? '')))) {
                return false;
            }
        }
        return true;
    }

    private function sourceInviteIdentity(array $snapshot): array
    {
        $ids = [];
        $tokens = [];
        foreach (is_array($snapshot['invites'] ?? null) ? $snapshot['invites'] : [] as $invite) {
            if (!is_array($invite)) continue;
            $id = trim((string)($invite['id'] ?? ''));
            $token = trim((string)($invite['token'] ?? ''));
            if ($id !== '') $ids[$id] = true;
            if ($token !== '') $tokens[$token] = true;
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
            if ($id !== '') $ids[$id] = true;
            if ($eventKey !== '' && $legacyUserId !== '') $events[$legacyUserId . '|' . $eventKey] = true;
        }
        return [$ids, $events];
    }

    private function snapshot(): array
    {
        $dataDir = rtrim(trim((string)($this->config['data_dir'] ?? '')), '/\\');
        if ($dataDir === '') throw new RuntimeException('Staging stale orphan data directory is unavailable.');
        $storage = StorageFactory::createJson($dataDir);
        $snapshot = $storage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) throw new RuntimeException('Staging stale orphan JSON snapshot is unavailable.');
        return $snapshot;
    }

    private function database(): DatabaseConnectionInterface
    {
        if ($this->database !== null) return $this->database;
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Staging stale orphan recovery requires an enabled database.');
        }
        return $this->database = PdoConnectionFactory::create($databaseConfig);
    }

    private function assertAvailable(array $server): void
    {
        $environment = strtolower(trim((string)($this->config['environment'] ?? '')));
        $baseUrl = rtrim(trim((string)($this->config['base_url'] ?? '')), '/');
        $baseScheme = strtolower((string)(parse_url($baseUrl, PHP_URL_SCHEME) ?: ''));
        $baseHost = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        $requestHost = strtolower(trim((string)($server['HTTP_HOST'] ?? '')));
        if (str_contains($requestHost, ':')) $requestHost = explode(':', $requestHost, 2)[0];

        if ($environment !== 'staging'
            || $baseScheme !== 'https'
            || $baseHost !== self::STAGING_HOST
            || $requestHost !== self::STAGING_HOST) {
            throw new RuntimeException('Staging stale orphan recovery is unavailable.');
        }
        if (!$this->router->enabled()
            || $this->router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
            || $this->router->routeFor('notifications') !== RuntimeStorageRouter::DRIVER_DATABASE
            || $this->router->routeFor('invites') !== RuntimeStorageRouter::DRIVER_DATABASE) {
            throw new RuntimeException('Staging stale orphan recovery requires DB runtime routing.');
        }
        if (!empty($this->config['external_payments_enabled'])) {
            throw new RuntimeException('Staging stale orphan recovery refuses live payments.');
        }
        foreach (['payment_mode', 'telegram_stars_mode', 'google_play_billing_mode'] as $key) {
            if (strtolower(trim((string)($this->config[$key] ?? ''))) === 'live') {
                throw new RuntimeException('Staging stale orphan recovery refuses live payments.');
            }
        }
    }
}
