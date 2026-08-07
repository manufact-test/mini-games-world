<?php
declare(strict_types=1);

final class StagingInviteMismatchDiagnosticService
{
    private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';
    private const TEST_PLAYER_IDS = ['stg_test_player_a', 'stg_test_player_b'];
    private const TERMINAL_STATUSES = ['declined', 'cancelled', 'expired', 'timed_out'];

    private RuntimeStorageRouter $router;
    private ?DatabaseConnectionInterface $database = null;

    public function __construct(private array $config, ?RuntimeStorageRouter $router = null)
    {
        $this->router = $router ?? new RuntimeStorageRouter($config);
    }

    public function diagnose(array $server): array
    {
        $this->assertAvailable($server);
        $snapshot = $this->snapshot();
        $sourceIds = [];
        $sourceTokens = [];
        foreach (is_array($snapshot['invites'] ?? null) ? $snapshot['invites'] : [] as $invite) {
            if (!is_array($invite)) continue;
            $id = trim((string)($invite['id'] ?? ''));
            $token = trim((string)($invite['token'] ?? ''));
            if ($id !== '') $sourceIds[$id] = true;
            if ($token !== '') $sourceTokens[$token] = true;
        }
        $sourceNotificationIds = [];
        $sourceNotificationEvents = [];
        foreach (is_array($snapshot['notifications'] ?? null) ? $snapshot['notifications'] : [] as $notification) {
            if (!is_array($notification)) continue;
            $id = trim((string)($notification['id'] ?? ''));
            $event = trim((string)($notification['event_key'] ?? ''));
            $user = trim((string)($notification['user_id'] ?? ''));
            if ($id !== '') $sourceNotificationIds[$id] = true;
            if ($event !== '' && $user !== '') $sourceNotificationEvents[$user . '|' . $event] = true;
        }

        $db = $this->database();
        $report = [
            'db_only_non_test_nonterminal_count' => 0,
            'status_counts' => [],
            'source_counts' => [],
            'expired_count' => 0,
            'older_than_20m_count' => 0,
            'with_match_field_count' => 0,
            'referenced_by_match_count' => 0,
            'linked_notification_row_count' => 0,
            'linked_notification_json_overlap_count' => 0,
            'one_party_count' => 0,
            'two_party_count' => 0,
        ];
        $now = time();

        foreach ($db->fetchAll('SELECT * FROM mgw_invites ORDER BY invite_id') as $invite) {
            $inviteId = trim((string)($invite['invite_id'] ?? ''));
            $token = trim((string)($invite['token'] ?? ''));
            if ($inviteId === '' || $token === '') continue;
            if (isset($sourceIds[$inviteId]) || isset($sourceTokens[$token])) continue;

            $inviter = trim((string)($invite['inviter_legacy_user_id'] ?? ''));
            $invitee = trim((string)($invite['invitee_legacy_user_id'] ?? ''));
            $participants = array_values(array_unique(array_filter([$inviter, $invitee], static fn(string $v): bool => $v !== '')));
            if ($participants === [] || count(array_intersect($participants, self::TEST_PLAYER_IDS)) > 0) continue;

            $status = trim((string)($invite['status'] ?? ''));
            if (in_array($status, self::TERMINAL_STATUSES, true)) continue;

            $report['db_only_non_test_nonterminal_count']++;
            $report['status_counts'][$status !== '' ? $status : 'empty'] = ($report['status_counts'][$status !== '' ? $status : 'empty'] ?? 0) + 1;
            $source = trim((string)($invite['source'] ?? ''));
            $report['source_counts'][$source !== '' ? $source : 'empty'] = ($report['source_counts'][$source !== '' ? $source : 'empty'] ?? 0) + 1;
            count($participants) >= 2 ? $report['two_party_count']++ : $report['one_party_count']++;

            $expires = strtotime((string)($invite['expires_at_utc'] ?? '')) ?: 0;
            if ($expires > 0 && $expires < $now) $report['expired_count']++;
            $updated = strtotime((string)($invite['updated_at_utc'] ?? '')) ?: 0;
            if ($updated > 0 && ($now - $updated) >= 1200) $report['older_than_20m_count']++;

            if (trim((string)($invite['match_id'] ?? '')) !== '' || trim((string)($invite['source_match_id'] ?? '')) !== '') {
                $report['with_match_field_count']++;
            }
            $matchRefs = (int)$db->fetchValue(
                'SELECT COUNT(*) FROM mgw_matches WHERE invite_id = :invite_id OR source_match_id = :source_match_id',
                ['invite_id' => $inviteId, 'source_match_id' => $inviteId]
            );
            if ($matchRefs > 0) $report['referenced_by_match_count']++;

            foreach ($db->fetchAll(
                'SELECT notification_id, event_key, legacy_user_id FROM mgw_notifications WHERE invite_token = :invite_token',
                ['invite_token' => $token]
            ) as $notification) {
                $report['linked_notification_row_count']++;
                $notificationId = trim((string)($notification['notification_id'] ?? ''));
                $eventKey = trim((string)($notification['event_key'] ?? ''));
                $legacyUserId = trim((string)($notification['legacy_user_id'] ?? ''));
                if (($notificationId !== '' && isset($sourceNotificationIds[$notificationId]))
                    || ($eventKey !== '' && $legacyUserId !== '' && isset($sourceNotificationEvents[$legacyUserId . '|' . $eventKey]))) {
                    $report['linked_notification_json_overlap_count']++;
                }
            }
        }

        ksort($report['status_counts'], SORT_STRING);
        ksort($report['source_counts'], SORT_STRING);
        return [
            'ok' => true,
            'service' => 'mini-games-world-staging-invite-mismatch-diagnostic',
            'read_only' => true,
            'report' => $report,
            'production_changed' => false,
            'live_payments_used' => false,
        ];
    }

    private function snapshot(): array
    {
        $dataDir = rtrim(trim((string)($this->config['data_dir'] ?? '')), '/\\');
        if ($dataDir === '') throw new RuntimeException('Staging invite diagnostic data directory is unavailable.');
        $storage = StorageFactory::createJson($dataDir);
        $snapshot = $storage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) throw new RuntimeException('Staging invite diagnostic snapshot is unavailable.');
        return $snapshot;
    }

    private function database(): DatabaseConnectionInterface
    {
        if ($this->database !== null) return $this->database;
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) throw new RuntimeException('Staging invite diagnostic requires database.');
        return $this->database = PdoConnectionFactory::create($databaseConfig);
    }

    private function assertAvailable(array $server): void
    {
        $environment = strtolower(trim((string)($this->config['environment'] ?? '')));
        $baseHost = strtolower((string)(parse_url((string)($this->config['base_url'] ?? ''), PHP_URL_HOST) ?: ''));
        $requestHost = strtolower(trim((string)($server['HTTP_HOST'] ?? '')));
        if (str_contains($requestHost, ':')) $requestHost = explode(':', $requestHost, 2)[0];
        if ($environment !== 'staging' || $baseHost !== self::STAGING_HOST || $requestHost !== self::STAGING_HOST) {
            throw new RuntimeException('Staging invite diagnostic is unavailable.');
        }
        if (!$this->router->enabled() || $this->router->routeFor('invites') !== RuntimeStorageRouter::DRIVER_DATABASE) {
            throw new RuntimeException('Staging invite diagnostic requires DB invite routing.');
        }
    }
}
