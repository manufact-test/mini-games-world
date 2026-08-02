<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/runtime/ProductionPrimaryRollbackExportGate.php';
require_once $projectRoot . '/bot/runtime/ProductionPrimaryLiveRollbackGate.php';
require_once $projectRoot . '/bot/runtime/ProductionPrimaryLiveRollbackStateStore.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$remove = static function (string $path) use (&$remove): void {
    if (!file_exists($path) && !is_link($path)) return;
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
            $remove($path . '/' . $name);
        }
        if (!rmdir($path)) throw new RuntimeException('Test directory could not be removed.');
        return;
    }
    if (!unlink($path)) throw new RuntimeException('Test file could not be removed.');
};
$mode = static function (string $path): int {
    clearstatcache(true, $path);
    $value = fileperms($path);
    return is_int($value) ? ($value & 0777) : -1;
};
$canonicalJson = static function (array $value): string {
    $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
        if (!is_array($item)) return $item;
        if (!array_is_list($item)) ksort($item, SORT_STRING);
        foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
        return $item;
    };
    return json_encode(
        $canonicalize($value),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
};

$root = sys_get_temp_dir() . '/mgw-live-rollback-state-' . bin2hex(random_bytes(6));
try {
    if (!mkdir($root, 0700, true) || !chmod($root, 0700)) {
        throw new RuntimeException('Test private directory could not be created.');
    }
    $cutoverFile = $root . '/production-cutover.json';
    $cutover = [
        'state' => 'completed',
        'build' => ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD,
        'database_runtime_published' => true,
        'json_write_block_active' => false,
        'rollback_driver' => 'json',
    ];
    $raw = json_encode($cutover, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($cutoverFile, $raw, LOCK_EX) !== strlen($raw)
        || !chmod($cutoverFile, 0600)) {
        throw new RuntimeException('Test cutover state could not be written.');
    }

    $store = new ProductionPrimaryLiveRollbackStateStore($root, $cutoverFile);
    $initialFingerprint = $store->cutoverFingerprint();
    $requestId = str_repeat('a', 32);
    $gate = [
        'ready' => true,
        'contract_version' => ProductionPrimaryLiveRollbackGate::CONTRACT_VERSION,
        'request_id' => $requestId,
        'expected_backup_id' => 'rollback-' . $requestId,
        'expected_state_revision' => 11,
        'expected_state_sha256' => str_repeat('b', 64),
        'expected_snapshot_sha256' => str_repeat('c', 64),
        'activation_plan_fingerprint' => str_repeat('d', 64),
        'activation_source_fingerprint' => str_repeat('e', 64),
    ];

    $backup = $store->prepareCutoverBackup($requestId, $initialFingerprint);
    $assertSame(true, $backup['created'], 'Cutover backup must be created once');
    $backupAgain = $store->prepareCutoverBackup($requestId, $initialFingerprint);
    $assertSame(false, $backupAgain['created'], 'Cutover backup must be idempotent');

    $prepared = $store->writeRecovery('prepared', $gate, ['previous_json_retained' => false]);
    $assertSame('prepared', $prepared['state'], 'Recovery must enter prepared state');
    $assertSame(true, $prepared['database_route_enabled'], 'Prepared state must keep DB route');
    $assertSame(false, $prepared['json_write_block_active'], 'Prepared state must not claim a new seal');

    $installed = $store->writeRecovery(
        'live_json_installed_db_active',
        $gate,
        ['previous_json_retained' => true]
    );
    $assertSame(true, $installed['database_route_enabled'], 'Installed state must still use DB');
    $assertSame(true, $installed['json_write_block_active'], 'Installed JSON must be sealed');

    $sealedCutover = $store->writeCutoverSealed($gate);
    $assertSame('rolled_back_json_sealed', $sealedCutover['state'], 'Cutover must enter sealed rollback state');
    $assertSame(false, $sealedCutover['database_runtime_published'], 'Sealed cutover must disable DB route marker');
    $assertSame(true, $sealedCutover['json_write_block_active'], 'Sealed cutover must keep JSON blocked');
    $sealedRecovery = $store->writeRecovery(
        'json_route_sealed',
        $gate,
        ['previous_json_retained' => true]
    );
    $assertSame(false, $sealedRecovery['database_route_enabled'], 'Sealed recovery must disable DB route');
    $assertSame(true, $sealedRecovery['maintenance_enabled'], 'Sealed recovery must keep maintenance');

    $completedCutover = $store->writeCutoverCompleted($gate);
    $assertSame('rolled_back', $completedCutover['state'], 'Cutover must enter rolled-back state');
    $assertSame(false, $completedCutover['json_write_block_active'], 'Completed cutover must release JSON block');
    $assertSame(false, $completedCutover['maintenance_active'], 'Completed cutover must release maintenance');
    $completed = $store->writeRecovery(
        'completed',
        $gate,
        ['previous_json_retained' => true]
    );
    $assertSame('completed', $completed['state'], 'Recovery must complete');
    $assertSame(false, $completed['database_route_enabled'], 'Completed recovery must remain JSON-first');
    $assertSame(false, $completed['json_write_block_active'], 'Completed recovery must have no JSON block');
    $assertSame(0600, $mode($root . '/production-live-rollback.json'), 'Recovery state must be private');
    $assertSame(0600, $mode($cutoverFile), 'Cutover state must remain private');

    $restored = $store->restoreAuthorizedCutoverBackup($requestId, $initialFingerprint);
    $assertSame(true, $restored['cutover_restored'], 'Authorized cutover backup must restore');
    $assertSame($initialFingerprint, $store->cutoverFingerprint(), 'Restored cutover fingerprint must match original');
    $restoredCutover = json_decode(
        file_get_contents($cutoverFile) ?: '{}',
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $assertSame(
        $canonicalJson($cutover),
        $canonicalJson($restoredCutover),
        'Restored cutover object must match original semantically'
    );
} finally {
    $remove($root);
}

fwrite(STDOUT, "ProductionPrimaryLiveRollbackStateStoreTest passed: {$assertions} assertions.\n");
