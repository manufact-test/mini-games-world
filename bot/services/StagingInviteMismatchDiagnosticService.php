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
        $jsonOpenTestReport = [
            'json_open_test_mixed_count' => 0,
            'json_open_test_only_count' => 0,
            'json_open_test_inviter_count' => 0,
            'json_open_test_invitee_count' => 0,
            'json_open_test_status_counts' => [],
            'json_open_test_source_counts' => [],
        ];
        $testGameReport = [
            'active_test_game_count' => 0,
            'active_test_game_non_test_participant_count' => 0,
            'active_test_game_launch_phase_counts' => [],
            'active_test_game_type_counts' => [],
        ];
        $testIds = array_fill_keys(self::TEST_PLAYER_IDS, true);
        foreach (is_array($snapshot['invites'] ?? null) ? $snapshot['invites'] : [] as $invite) {
            if (!is_array($invite)) continue;
            $id = trim((string)($invite['id'] ?? ''));
            $token = trim((string)($invite['token'] ?? ''));
            if ($id !== '') $sourceIds[$id] = true;
            if ($token !== '') $sourceTokens[$token] = true;

            $status = trim((string)($invite['status'] ?? ''));
            if (in_array($status, self::TERMINAL_STATUSES, true)) continue;
            $inviter = trim((string)($invite['inviter_id'] ?? ''));
            $invitee = trim((string)($invite['invitee_id'] ?? ''));
            $inviterIsTest = isset($testIds[$inviter]);
            $inviteeIsTest = isset($testIds[$invitee]);
            if (!$inviterIsTest && !$inviteeIsTest) continue;

            if ($inviterIsTest) $jsonOpenTestReport['json_open_test_inviter_count']++;
            if ($inviteeIsTest) $jsonOpenTestReport['json_open_test_invitee_count']++;
            if ($inviterIsTest && ($invitee === '' || $inviteeIsTest)) {
                $jsonOpenTestReport['json_open_test_only_count']++;
            } elseif ($inviteeIsTest && ($inviter === '' || $inviterIsTest)) {
                $jsonOpenTestReport['json_open_test_only_count']++;
            } else {
                $jsonOpenTestReport['json_open_test_mixed_count']++;
            }
            $jsonOpenTestReport['json_open_test_status_counts'][$status !== '' ? $status : 'empty'] =
                ($jsonOpenTestReport['json_open_test_status_counts'][$status !== '' ? $status : 'empty'] ?? 0) + 1;
            $source = trim((string)($invite['source'] ?? ''));
            $jsonOpenTestReport['json_open_test_source_counts'][$source !== '' ? $source : 'empty'] =
                ($jsonOpenTestReport['json_open_test_source_counts'][$source !== '' ? $source : 'empty'] ?? 0) + 1;
        }

        foreach (is_array($snapshot['games'] ?? null) ? $snapshot['games'] : [] as $game) {
            if (!is_array($game) || trim((string)($game['status'] ?? '')) !== 'active') continue;
            $participants = array_values(array_filter(
                array_map('strval', is_array($game['player_ids'] ?? null) ? $game['player_ids'] : []),
                static fn(string $value): bool => $value !== ''
            ));
            if (count(array_intersect($participants, self::TEST_PLAYER_IDS)) === 0) continue;

            $testGameReport['active_test_game_count']++;
            foreach ($participants as $participantId) {
                if (!isset($testIds[$participantId]) && !str_starts_with($participantId, 'bot_')) {
                    $testGameReport['active_test_game_non_test_participant_count']++;
                    break;
                }
            }
            $phase = trim((string)($game['launch_phase'] ?? 'active'));
            $testGameReport['active_test_game_launch_phase_counts'][$phase !== '' ? $phase : 'empty'] =
                ($testGameReport['active_test_game_launch_phase_counts'][$phase !== '' ? $phase : 'empty'] ?? 0) + 1;
            $gameType = trim((string)($game['game_type'] ?? ''));
            $testGameReport['active_test_game_type_counts'][$gameType !== '' ? $gameType : 'empty'] =
                ($testGameReport['active_test_game_type_counts'][$gameType !== '' ? $gameType : 'empty'] ?? 0) + 1;
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
        $inviteAuditReport = [
            'invite_audit_available' => true,
            'invite_audit_ok' => false,
            'invite_audit_source_count' => null,
            'invite_audit_database_count' => null,
            'invite_audit_preserved_historical_count' => null,
            'invite_audit_count_mismatch' => false,
            'invite_audit_fingerprint_mismatch' => false,
        ];
        try {
            $inviteAudit = (new RuntimeInviteRepository($this->config, $this->router, $db))
                ->auditParity($snapshot);
            $blockers = is_array($inviteAudit['blockers'] ?? null) ? $inviteAudit['blockers'] : [];
            $inviteAuditReport = [
                'invite_audit_available' => true,
                'invite_audit_ok' => ($inviteAudit['ok'] ?? false) === true,
                'invite_audit_source_count' => (int)($inviteAudit['source_count'] ?? 0),
                'invite_audit_database_count' => (int)($inviteAudit['database_count'] ?? 0),
                'invite_audit_preserved_historical_count' => (int)($inviteAudit['preserved_historical_invite_rows'] ?? 0),
                'invite_audit_count_mismatch' => in_array('Invite JSON and DB counts differ.', $blockers, true),
                'invite_audit_fingerprint_mismatch' => in_array('Invite JSON and DB fingerprints differ.', $blockers, true),
            ];
        } catch (Throwable) {
            $inviteAuditReport['invite_audit_available'] = false;
        }

        $report = $jsonOpenTestReport + $testGameReport + $inviteAuditReport + [
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

        ksort($report['json_open_test_status_counts'], SORT_STRING);
        ksort($report['json_open_test_source_counts'], SORT_STRING);
        ksort($report['active_test_game_launch_phase_counts'], SORT_STRING);
        ksort($report['active_test_game_type_counts'], SORT_STRING);
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
