<?php
declare(strict_types=1);

require dirname(__DIR__) . '/cutover/ProductionPreflightService.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $value, string $message) use (&$assertions): void {
    $assertions++;
    if (!$value) throw new RuntimeException($message);
};
$contains = static function (array $items, string $needle): bool {
    foreach ($items as $item) {
        if (str_contains((string)$item, $needle)) return true;
    }
    return false;
};

$service = new ProductionPreflightService();
$snapshot = [
    'users' => [101 => ['id' => 101, 'status' => 'idle']],
    'games' => [['id' => 'game_done', 'status' => 'finished']],
    'queue' => [],
    'invites' => [['status' => 'accepted']],
    'notifications' => [['id' => 'notice_1']],
    'transactions' => [['id' => 'tx_1']],
    'payments' => [['id' => 'payment_1', 'status' => 'paid']],
    'shop_orders' => [['id' => 'order_1', 'status' => 'done']],
];
$inventory = $service->inspectSnapshot($snapshot);
$assertSame(1, $inventory['source_user_count'], 'Snapshot must count numeric-key users');
$assertSame(0, $inventory['active_games'], 'Finished games must not be active');
$assertSame(0, $inventory['pending_payments'], 'Paid payment must be terminal');
$assertSame(0, $inventory['unknown_payment_statuses'], 'Known payment status must not be unknown');
$assertSame(0, $inventory['pending_shop_orders'], 'Done order must be terminal');
$assertSame(64, strlen($inventory['source_fingerprint']), 'Source fingerprint must be SHA-256');

$runtime = [
    'environment' => 'production',
    'build' => 'v102-mvp14-production-preflight',
    'storage_driver' => 'json',
    'database_enabled' => true,
    'database_connected' => true,
    'schema_current' => true,
    'pending_migrations' => 0,
    'migration_plan_fingerprint' => str_repeat('a', 64),
    'database_runtime_requested' => false,
    'data_directory_readable' => true,
    'data_directory_writable' => true,
    'private_config_loaded' => true,
    'runtime_file_readable' => true,
    'cutover_control_active' => false,
    'json_write_block_active' => false,
];
$backups = [
    'primary' => [
        'ok' => true,
        'fresh' => true,
        'environment' => 'production',
        'backup_id' => 'backup-1',
        'snapshot_sha256' => str_repeat('b', 64),
    ],
    'external' => [
        'ok' => true,
        'fresh' => true,
        'environment' => 'production',
        'backup_id' => 'backup-1',
        'snapshot_sha256' => str_repeat('b', 64),
    ],
];
$rollback = [
    'restore_utility_present' => true,
    'verify_utility_present' => true,
    'runtime_file_restorable' => true,
];

$healthy = $service->evaluate($runtime, $backups, $inventory, $rollback);
$assertSame(true, $healthy['ok'], 'Healthy production preflight must pass');
$assertSame(true, $healthy['technical_ready_for_window'], 'Healthy preflight must be technically ready');
$assertSame(false, $healthy['production_switch_allowed'], 'Preflight must never authorize a switch');
$assertSame(false, $healthy['production_switch_performed'], 'Preflight must never perform a switch');
$assertSame(true, $healthy['manual_cutover_approval_required'], 'A separate manual approval must remain required');
$assertSame(true, $healthy['rollback_checklist']['ok'], 'Healthy rollback checklist must pass');
$assertSame([], $healthy['blockers'], 'Healthy preflight must have no blockers');
$assertSame(64, strlen($healthy['cutover_plan_fingerprint']), 'Plan fingerprint must be SHA-256');
$repeat = $service->evaluate($runtime, $backups, $inventory, $rollback);
$assertSame(
    $healthy['cutover_plan_fingerprint'],
    $repeat['cutover_plan_fingerprint'],
    'Identical preflight inputs must produce a stable plan fingerprint'
);

$activeSnapshot = $snapshot;
$activeSnapshot['games'][0]['status'] = 'active';
$active = $service->evaluate($runtime, $backups, $service->inspectSnapshot($activeSnapshot), $rollback);
$assertSame(false, $active['ok'], 'Active games must block the window');
$assertTrue($contains($active['blockers'], 'active games'), 'Active-game blocker must be explicit');

$pendingSnapshot = $snapshot;
$pendingSnapshot['payments'][0]['status'] = 'pending';
$pendingSnapshot['shop_orders'][0]['status'] = 'mystery';
$pending = $service->evaluate($runtime, $backups, $service->inspectSnapshot($pendingSnapshot), $rollback);
$assertSame(false, $pending['ok'], 'Pending or unknown financial work must block the window');
$assertTrue($contains($pending['blockers'], 'pending payments'), 'Pending payment blocker must be explicit');
$assertTrue($contains($pending['blockers'], 'unknown shop order'), 'Unknown order blocker must be explicit');

$staleBackups = $backups;
$staleBackups['external']['fresh'] = false;
$stale = $service->evaluate($runtime, $staleBackups, $inventory, $rollback);
$assertSame(false, $stale['ok'], 'A stale external backup must block the window');
$assertTrue($contains($stale['blockers'], 'external production backup is stale'), 'Stale backup blocker must be explicit');

$mismatchBackups = $backups;
$mismatchBackups['external']['backup_id'] = 'backup-2';
$mismatch = $service->evaluate($runtime, $mismatchBackups, $inventory, $rollback);
$assertSame(false, $mismatch['ok'], 'Different primary/external backup IDs must block the window');
$assertSame(false, $mismatch['backup_pair']['same_backup_id'], 'Backup mismatch must be visible');

$routedRuntime = $runtime;
$routedRuntime['database_runtime_requested'] = true;
$routed = $service->evaluate($routedRuntime, $backups, $inventory, $rollback);
$assertSame(false, $routed['ok'], 'A pre-enabled production DB route must fail closed');
$assertTrue($contains($routed['blockers'], 'already requested'), 'DB route blocker must be explicit');

$badRollback = $rollback;
$badRollback['runtime_file_restorable'] = false;
$blockedRollback = $service->evaluate($runtime, $backups, $inventory, $badRollback);
$assertSame(false, $blockedRollback['ok'], 'Incomplete rollback tooling must block the window');
$assertSame(false, $blockedRollback['rollback_checklist']['ok'], 'Rollback checklist must report failure');

fwrite(STDOUT, "ProductionPreflightServiceTest passed: {$assertions} assertions.\n");
