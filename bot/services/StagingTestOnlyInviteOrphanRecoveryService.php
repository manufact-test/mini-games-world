<?php
declare(strict_types=1);

final class StagingTestOnlyInviteOrphanRecoveryService
{
    private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';
    private const TEST_PLAYER_IDS = ['stg_test_player_a', 'stg_test_player_b'];
    private const SAFE_STATUSES = [
        'draft', 'pending', 'accepted', 'awaiting_start',
        'declined', 'cancelled', 'expired', 'timed_out',
    ];
    private const SAFE_SOURCES = ['direct', 'link'];
    private const MAX_CANDIDATES = 10;

    private RuntimeStorageRouter $router;
    private ?DatabaseConnectionInterface $database = null;

    public function __construct(private array $config, ?RuntimeStorageRouter $router = null)
    {
        $this->router = $router ?? new RuntimeStorageRouter($config);
    }

    public function reconcile(array $server): array
    {
        $this->assertAvailable($server);
        $snapshot = $this->snapshot();
        [$sourceInviteIds, $sourceInviteTokens] = $this->sourceInviteIdentity($snapshot);
        [$sourceNotificationIds, $sourceNotificationEvents] = $this->sourceNotificationIdentity($snapshot);
        $database = $this->database();
        $testIds = array_fill_keys(self::TEST_PLAYER_IDS, true);

        $candidates = [];
        foreach ($database->fetchAll('SELECT * FROM mgw_invites ORDER BY invite_id') as $invite) {
            $inviteId = trim((string)($invite['invite_id'] ?? ''));
            $token = trim((string)($invite['token'] ?? ''));
            if ($inviteId === '' || $token === '') continue;
            if (isset($sourceInviteIds[$inviteId]) || isset($sourceInviteTokens[$token])) continue;

            $participants = array_values(array_unique(array_filter([
                trim((string)($invite['inviter_legacy_user_id'] ?? '')),
                trim((string)($invite['invitee_legacy_user_id'] ?? '')),
            ], static fn(string $id): bool => $id !== '')));
            if ($participants === []) continue;

            $testOnly = true;
            foreach ($participants as $participantId) {
                if (!isset($testIds[$participantId])) {
                    $testOnly = false;
                    break;
                }
            }
            if (!$testOnly) continue;

            $status = trim((string)($invite['status'] ?? ''));
            $source = trim((string)($invite['source'] ?? ''));
            if (!in_array($status, self::SAFE_STATUSES, true)
                || !in_array($source, self::SAFE_SOURCES, true)) {
                throw new RuntimeException('Staging test-only orphan recovery found unsafe A/B invite state.');
            }
            if (trim((string)($invite['match_id'] ?? '')) !== '') {
                throw new RuntimeException('Staging test-only orphan recovery refuses matched A/B invite.');
            }
            $matchRefs = (int)$database->fetchValue(
                'SELECT COUNT(*) FROM mgw_matches WHERE invite_id = :invite_id OR source_match_id = :source_match_id',
                ['invite_id' => $inviteId, 'source_match_id' => $inviteId]
            );
            if ($matchRefs !== 0) {
                throw new RuntimeException('Staging test-only orphan recovery refuses match-referenced A/B invite.');
            }

            $this->assertOwnership($database, $invite, $participants);
            $notifications = $database->fetchAll(
                'SELECT * FROM mgw_notifications WHERE invite_token = :invite_token ORDER BY notification_id',
                ['invite_token' => $token]
            );
            foreach ($notifications as $notification) {
                $notificationId = trim((string)($notification['notification_id'] ?? ''));
                $eventKey = trim((string)($notification['event_key'] ?? ''));
                $legacyUserId = trim((string)($notification['legacy_user_id'] ?? ''));
                if ($notificationId === '' || $eventKey === '' || !isset($testIds[$legacyUserId])) {
                    throw new RuntimeException('Staging test-only orphan recovery refuses non-test notification state.');
                }
                if (isset($sourceNotificationIds[$notificationId])
                    || isset($sourceNotificationEvents[$legacyUserId . '|' . $eventKey])) {
                    throw new RuntimeException('Staging test-only orphan recovery refuses JSON-backed notification state.');
                }
            }

            $events = $database->fetchAll(
                'SELECT event_id FROM mgw_invite_events WHERE invite_id = :invite_id ORDER BY event_id',
                ['invite_id' => $inviteId]
            );
            $candidates[] = [
                'invite' => $invite,
                'participants' => $participants,
                'notifications' => $notifications,
                'events' => $events,
            ];
        }

        if (count($candidates) > self::MAX_CANDIDATES) {
            throw new RuntimeException('Staging test-only orphan recovery refuses excessive candidates.');
        }
        if ($candidates === []) {
            return [
                'ok' => true,
                'service' => 'mini-games-world-staging-test-only-invite-orphan-recovery',
                'status' => 'already_clean',
                'candidate_count' => 0,
                'deleted' => ['invite_rows' => 0, 'notification_rows' => 0, 'invite_event_rows' => 0],
                'parity' => ['invites' => true, 'test_notifications' => true],
                'production_changed' => false,
                'live_payments_used' => false,
            ];
        }

        $deleted = $database->transaction(function (DatabaseConnectionInterface $db) use ($candidates): array {
            $deleted = ['invite_rows' => 0, 'notification_rows' => 0, 'invite_event_rows' => 0];
            foreach ($candidates as $candidate) {
                $invite = $candidate['invite'];
                $inviteId = (string)$invite['invite_id'];
                $token = (string)$invite['token'];
                $status = (string)$invite['status'];

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
                        throw new RuntimeException('Staging test-only orphan notification delete count is unexpected.');
                    }
                    $deleted['notification_rows'] += $count;
                }

                $eventCount = $db->execute(
                    'DELETE FROM mgw_invite_events WHERE invite_id = :invite_id',
                    ['invite_id' => $inviteId]
                );
                if ($eventCount !== count($candidate['events'])) {
                    throw new RuntimeException('Staging test-only orphan event delete count is unexpected.');
                }
                $deleted['invite_event_rows'] += $eventCount;

                $inviteCount = $db->execute(
                    'DELETE FROM mgw_invites WHERE invite_id = :invite_id AND token = :token AND status = :status',
                    ['invite_id' => $inviteId, 'token' => $token, 'status' => $status]
                );
                if ($inviteCount !== 1) {
                    throw new RuntimeException('Staging test-only orphan invite delete count is unexpected.');
                }
                $deleted['invite_rows'] += $inviteCount;
            }
            return $deleted;
        });

        $inviteAudit = (new RuntimeInviteRepository($this->config, $this->router, $database))
            ->auditParity($snapshot);
        if (($inviteAudit['ok'] ?? false) !== true) {
            throw new RuntimeException('Staging test-only orphan invite parity did not recover.');
        }
        foreach (self::TEST_PLAYER_IDS as $legacyUserId) {
            $notificationAudit = (new RuntimeNotificationRepository($this->config, $this->router, $database))
                ->auditParity($snapshot, $legacyUserId);
            if (($notificationAudit['ok'] ?? false) !== true) {
                throw new RuntimeException('Staging test-only orphan notification parity did not recover.');
            }
        }

        return [
            'ok' => true,
            'service' => 'mini-games-world-staging-test-only-invite-orphan-recovery',
            'status' => 'recovered',
            'candidate_count' => count($candidates),
            'deleted' => $deleted,
            'parity' => ['invites' => true, 'test_notifications' => true],
            'production_changed' => false,
            'live_payments_used' => false,
        ];
    }

    private function assertOwnership(
        DatabaseConnectionInterface $database,
        array $invite,
        array $participants
    ): void {
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

        foreach ($participants as $legacyUserId) {
            $rows = $database->fetchAll(
                'SELECT account_ref, mgw_id, ownership_status FROM mgw_account_ownership
                 WHERE legacy_user_id = :legacy_user_id',
                ['legacy_user_id' => $legacyUserId]
            );
            if (count($rows) !== 1 || (string)($rows[0]['ownership_status'] ?? '') !== 'active') {
                throw new RuntimeException('Staging test-only orphan ownership is unavailable.');
            }
            $expected = $roles[$legacyUserId] ?? null;
            $actualRef = trim((string)($rows[0]['account_ref'] ?? ''));
            $actualMgw = trim((string)($rows[0]['mgw_id'] ?? ''));
            if (!is_array($expected)
                || $actualRef === ''
                || $actualMgw === ''
                || !hash_equals($actualRef, (string)$expected['account_ref'])
                || !hash_equals($actualMgw, (string)$expected['mgw_id'])) {
                throw new RuntimeException('Staging test-only orphan ownership mismatch.');
            }
        }
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
        if ($dataDir === '') throw new RuntimeException('Staging test-only orphan data directory is unavailable.');
        $storage = StorageFactory::createJson($dataDir);
        $snapshot = $storage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) throw new RuntimeException('Staging test-only orphan JSON snapshot is unavailable.');
        return $snapshot;
    }

    private function database(): DatabaseConnectionInterface
    {
        if ($this->database !== null) return $this->database;
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Staging test-only orphan recovery requires database.');
        }
        return $this->database = PdoConnectionFactory::create($databaseConfig);
    }

    private function assertAvailable(array $server): void
    {
        $value = $this->config['environment'] ?? '';
        $environment = $value instanceof BackedEnum
            ? strtolower(trim((string)$value->value))
            : strtolower(trim((string)$value));
        $baseHost = strtolower((string)(parse_url((string)($this->config['base_url'] ?? ''), PHP_URL_HOST) ?: ''));
        $requestHost = strtolower(trim((string)($server['HTTP_HOST'] ?? '')));
        if (str_contains($requestHost, ':')) $requestHost = explode(':', $requestHost, 2)[0];
        if ($environment !== 'staging' || $baseHost !== self::STAGING_HOST || $requestHost !== self::STAGING_HOST) {
            throw new RuntimeException('Staging test-only orphan recovery is unavailable.');
        }
        if (!$this->router->enabled()
            || $this->router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
            || $this->router->routeFor('notifications') !== RuntimeStorageRouter::DRIVER_DATABASE
            || $this->router->routeFor('invites') !== RuntimeStorageRouter::DRIVER_DATABASE) {
            throw new RuntimeException('Staging test-only orphan recovery requires DB invite routing.');
        }
        if (!empty($this->config['external_payments_enabled'])) {
            throw new RuntimeException('Staging test-only orphan recovery refuses live payments.');
        }
        foreach (['payment_mode', 'telegram_stars_mode', 'google_play_billing_mode'] as $key) {
            if (strtolower(trim((string)($this->config[$key] ?? ''))) === 'live') {
                throw new RuntimeException('Staging test-only orphan recovery refuses live payments.');
            }
        }
    }
}
