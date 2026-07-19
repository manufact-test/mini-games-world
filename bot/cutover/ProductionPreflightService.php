<?php
declare(strict_types=1);

final class ProductionPreflightService
{
    private const OPEN_INVITE_STATUSES = ['draft', 'pending', 'awaiting_start', 'starting'];
    private const TERMINAL_PAYMENT_STATUSES = [
        'paid', 'applied', 'completed', 'success', 'succeeded',
        'rejected', 'declined', 'failed', 'cancelled', 'canceled',
    ];
    private const PENDING_PAYMENT_STATUSES = ['draft', 'pending', 'waiting', 'created'];
    private const TERMINAL_ORDER_STATUSES = [
        'done', 'fulfilled', 'completed', 'delivered', 'issued',
        'rejected', 'declined', 'failed', 'cancelled', 'canceled', 'refunded',
    ];
    private const PENDING_ORDER_STATUSES = ['draft', 'pending', 'processing', 'created'];

    public function inspectSnapshot(array $snapshot): array
    {
        $activeGames = 0;
        foreach ($this->rows($snapshot['games'] ?? []) as $game) {
            if ($this->normalized($game['status'] ?? '') === 'active') $activeGames++;
        }

        $openInvites = 0;
        foreach ($this->rows($snapshot['invites'] ?? []) as $invite) {
            if (in_array($this->normalized($invite['status'] ?? ''), self::OPEN_INVITE_STATUSES, true)) {
                $openInvites++;
            }
        }

        $searchingUsers = 0;
        $playingUsers = 0;
        foreach ($this->rows($snapshot['users'] ?? []) as $user) {
            $status = $this->normalized($user['status'] ?? 'idle');
            if ($status === 'searching') $searchingUsers++;
            if ($status === 'playing') $playingUsers++;
        }

        [$pendingPayments, $unknownPayments] = $this->financialCounts(
            $this->rows($snapshot['payments'] ?? []),
            self::PENDING_PAYMENT_STATUSES,
            self::TERMINAL_PAYMENT_STATUSES
        );
        [$pendingOrders, $unknownOrders] = $this->financialCounts(
            $this->rows($snapshot['shop_orders'] ?? []),
            self::PENDING_ORDER_STATUSES,
            self::TERMINAL_ORDER_STATUSES
        );

        $inventory = [
            'source_user_count' => count($this->rows($snapshot['users'] ?? [])),
            'source_game_count' => count($this->rows($snapshot['games'] ?? [])),
            'source_invite_count' => count($this->rows($snapshot['invites'] ?? [])),
            'source_notification_count' => count($this->rows($snapshot['notifications'] ?? [])),
            'source_transaction_count' => count($this->rows($snapshot['transactions'] ?? [])),
            'source_payment_count' => count($this->rows($snapshot['payments'] ?? [])),
            'source_shop_order_count' => count($this->rows($snapshot['shop_orders'] ?? [])),
            'active_games' => $activeGames,
            'queue_entries' => count($this->rows($snapshot['queue'] ?? [])),
            'open_invites' => $openInvites,
            'searching_users' => $searchingUsers,
            'playing_users' => $playingUsers,
            'pending_payments' => $pendingPayments,
            'unknown_payment_statuses' => $unknownPayments,
            'pending_shop_orders' => $pendingOrders,
            'unknown_shop_order_statuses' => $unknownOrders,
        ];
        $inventory['source_fingerprint'] = hash('sha256', $this->canonicalJson($snapshot));
        return $inventory;
    }

    public function evaluate(
        array $runtime,
        array $backups,
        array $inventory,
        array $rollback
    ): array {
        $blockers = [];
        $environment = $this->normalized($runtime['environment'] ?? '');

        if ($environment !== 'production') $blockers[] = 'environment is not production';
        if ($this->normalized($runtime['storage_driver'] ?? '') !== 'json') {
            $blockers[] = 'global JSON storage is not active';
        }
        if (($runtime['database_enabled'] ?? false) !== true) $blockers[] = 'production database is not enabled';
        if (($runtime['database_connected'] ?? false) !== true) $blockers[] = 'production database connection is not healthy';
        if (($runtime['schema_current'] ?? false) !== true || (int)($runtime['pending_migrations'] ?? -1) !== 0) {
            $blockers[] = 'production database schema is not current';
        }
        if (($runtime['database_runtime_requested'] ?? false) === true) {
            $blockers[] = 'production DB runtime routing is already requested';
        }
        if (($runtime['data_directory_readable'] ?? false) !== true) $blockers[] = 'production JSON data directory is not readable';
        if (($runtime['data_directory_writable'] ?? false) !== true) $blockers[] = 'production JSON data directory is not writable';
        if (($runtime['private_config_loaded'] ?? false) !== true) $blockers[] = 'production private config is not loaded';
        if (($runtime['runtime_file_readable'] ?? false) !== true) $blockers[] = 'production private runtime file is not readable';
        if (($runtime['cutover_control_active'] ?? false) === true) $blockers[] = 'a cutover control state is already active';
        if (($runtime['json_write_block_active'] ?? false) === true) $blockers[] = 'JSON write block is already active';

        $primary = is_array($backups['primary'] ?? null) ? $backups['primary'] : [];
        $external = is_array($backups['external'] ?? null) ? $backups['external'] : [];
        $primaryOk = ($primary['ok'] ?? false) === true;
        $externalOk = ($external['ok'] ?? false) === true;
        $primaryProduction = $primaryOk && $this->normalized($primary['environment'] ?? '') === 'production';
        $externalProduction = $externalOk && $this->normalized($external['environment'] ?? '') === 'production';
        $sameBackupId = $primaryOk && $externalOk
            && trim((string)($primary['backup_id'] ?? '')) !== ''
            && hash_equals((string)$primary['backup_id'], (string)($external['backup_id'] ?? ''));
        $sameSnapshot = $primaryOk && $externalOk
            && trim((string)($primary['snapshot_sha256'] ?? '')) !== ''
            && hash_equals((string)$primary['snapshot_sha256'], (string)($external['snapshot_sha256'] ?? ''));

        if (!$primaryOk) $blockers[] = 'latest primary production backup did not verify';
        elseif (!$primaryProduction) $blockers[] = 'primary backup environment is not production';
        elseif (($primary['fresh'] ?? false) !== true) $blockers[] = 'latest primary production backup is stale';

        if (!$externalOk) $blockers[] = 'latest external production backup did not verify';
        elseif (!$externalProduction) $blockers[] = 'external backup environment is not production';
        elseif (($external['fresh'] ?? false) !== true) $blockers[] = 'latest external production backup is stale';

        if ($primaryOk && $externalOk && !($sameBackupId && $sameSnapshot)) {
            $blockers[] = 'primary and external backups are not the same verified snapshot';
        }

        foreach ([
            'active_games' => 'active games must drain to zero',
            'queue_entries' => 'matchmaking queue must be empty',
            'open_invites' => 'open invitations must drain or expire',
            'searching_users' => 'searching users must return to idle',
            'playing_users' => 'playing users must drain to zero',
            'pending_payments' => 'pending payments must be resolved before the window',
            'unknown_payment_statuses' => 'unknown payment statuses must be resolved before the window',
            'pending_shop_orders' => 'pending shop orders must be resolved before the window',
            'unknown_shop_order_statuses' => 'unknown shop order statuses must be resolved before the window',
        ] as $key => $message) {
            if ((int)($inventory[$key] ?? 0) > 0) $blockers[] = $message;
        }

        $rollbackChecks = [
            'json_is_active_source' => $this->normalized($runtime['storage_driver'] ?? '') === 'json',
            'production_db_route_is_disabled' => ($runtime['database_runtime_requested'] ?? false) !== true,
            'primary_backup_verified' => $primaryOk && $primaryProduction && ($primary['fresh'] ?? false) === true,
            'external_backup_verified' => $externalOk && $externalProduction && ($external['fresh'] ?? false) === true,
            'backup_pair_matches' => $sameBackupId && $sameSnapshot,
            'restore_utility_present' => ($rollback['restore_utility_present'] ?? false) === true,
            'verify_utility_present' => ($rollback['verify_utility_present'] ?? false) === true,
            'runtime_file_restorable' => ($rollback['runtime_file_restorable'] ?? false) === true,
            'cutover_control_released' => ($runtime['cutover_control_active'] ?? false) !== true,
            'json_write_block_absent' => ($runtime['json_write_block_active'] ?? false) !== true,
        ];
        foreach ($rollbackChecks as $name => $passed) {
            if (!$passed) $blockers[] = 'rollback checklist failed: ' . $name;
        }

        $blockers = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $blockers
        ), static fn(string $value): bool => $value !== '')));

        $plan = [
            'build' => (string)($runtime['build'] ?? ''),
            'environment' => $environment,
            'storage_driver' => (string)($runtime['storage_driver'] ?? ''),
            'database_schema_fingerprint' => (string)($runtime['migration_plan_fingerprint'] ?? ''),
            'source_fingerprint' => (string)($inventory['source_fingerprint'] ?? ''),
            'backup_id' => $sameBackupId ? (string)($primary['backup_id'] ?? '') : '',
            'backup_snapshot_sha256' => $sameSnapshot ? (string)($primary['snapshot_sha256'] ?? '') : '',
            'traffic' => [
                'active_games' => (int)($inventory['active_games'] ?? 0),
                'queue_entries' => (int)($inventory['queue_entries'] ?? 0),
                'open_invites' => (int)($inventory['open_invites'] ?? 0),
                'searching_users' => (int)($inventory['searching_users'] ?? 0),
                'playing_users' => (int)($inventory['playing_users'] ?? 0),
            ],
            'financial_in_flight' => [
                'pending_payments' => (int)($inventory['pending_payments'] ?? 0),
                'unknown_payment_statuses' => (int)($inventory['unknown_payment_statuses'] ?? 0),
                'pending_shop_orders' => (int)($inventory['pending_shop_orders'] ?? 0),
                'unknown_shop_order_statuses' => (int)($inventory['unknown_shop_order_statuses'] ?? 0),
            ],
            'rollback_checks' => $rollbackChecks,
        ];

        $ready = $blockers === [];
        return [
            'ok' => $ready,
            'report_type' => 'mvp-14.8.5-production-preflight',
            'technical_ready_for_window' => $ready,
            'production_switch_allowed' => false,
            'production_switch_performed' => false,
            'manual_cutover_approval_required' => true,
            'runtime' => $runtime,
            'backups' => $backups,
            'backup_pair' => [
                'same_backup_id' => $sameBackupId,
                'same_snapshot_sha256' => $sameSnapshot,
                'same_verified_production_snapshot' => $sameBackupId
                    && $sameSnapshot
                    && $primaryProduction
                    && $externalProduction,
            ],
            'source_inventory' => $inventory,
            'rollback_checklist' => [
                'ok' => !in_array(false, $rollbackChecks, true),
                'checks' => $rollbackChecks,
            ],
            'blockers' => $blockers,
            'cutover_plan_fingerprint' => hash('sha256', $this->canonicalJson($plan)),
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function financialCounts(array $rows, array $pending, array $terminal): array
    {
        $pendingCount = 0;
        $unknownCount = 0;
        foreach ($rows as $row) {
            $status = $this->normalized($row['status'] ?? '');
            if (in_array($status, $pending, true)) {
                $pendingCount++;
                continue;
            }
            if ($status === '' || !in_array($status, $terminal, true)) $unknownCount++;
        }
        return [$pendingCount, $unknownCount];
    }

    private function rows(mixed $value): array
    {
        if (!is_array($value)) return [];
        return array_values(array_filter($value, static fn(mixed $row): bool => is_array($row)));
    }

    private function normalized(mixed $value): string
    {
        return strtolower(trim((string)$value));
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
