<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/runtime/ProductionPrimaryRollbackExportGate.php';
require_once $projectRoot . '/bot/runtime/ProductionPrimaryLiveRollbackGate.php';
require_once $projectRoot . '/bot/runtime/ProductionPrimaryRuntimeOverlayWriter.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
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

$root = sys_get_temp_dir() . '/mgw-runtime-overlay-writer-' . bin2hex(random_bytes(6));
try {
    if (!mkdir($root, 0700, true) || !chmod($root, 0700)) {
        throw new RuntimeException('Test private directory could not be created.');
    }
    $runtimeFile = $root . '/runtime.php';
    $initial = [
        'maintenance_mode' => true,
        'financial_read_only' => true,
        'features' => ['matchmaking' => false],
        'database_runtime' => [
            'enabled' => true,
            'production_activated' => true,
            'activation_build' => ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD,
            'activation_plan_fingerprint' => str_repeat('a', 64),
            'activation_source_fingerprint' => str_repeat('b', 64),
            'rollback_driver' => 'json',
            'modules' => array_fill_keys([
                'accounts', 'realtime', 'invites', 'notifications', 'economy',
                'history', 'shop', 'payments', 'weekly_bonus',
            ], true),
        ],
    ];
    $raw = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($initial, true) . ";\n";
    if (file_put_contents($runtimeFile, $raw, LOCK_EX) !== strlen($raw)
        || !chmod($runtimeFile, 0600)) {
        throw new RuntimeException('Test runtime overlay could not be written.');
    }

    $writer = new ProductionPrimaryRuntimeOverlayWriter($runtimeFile);
    $initialFingerprint = $writer->fingerprint();
    $requestId = str_repeat('c', 32);
    $gate = [
        'ready' => true,
        'contract_version' => ProductionPrimaryLiveRollbackGate::CONTRACT_VERSION,
        'request_id' => $requestId,
        'expected_state_revision' => 9,
        'expected_state_sha256' => str_repeat('d', 64),
        'expected_snapshot_sha256' => str_repeat('e', 64),
    ];

    $backup = $writer->prepareBackup($requestId, $initialFingerprint);
    $assertSame(true, $backup['ok'], 'Runtime backup must pass');
    $assertSame(true, $backup['created'], 'Runtime backup must be created once');
    $assertSame(0600, $mode($writer->backupPath($requestId)), 'Runtime backup must be private');
    $backupAgain = $writer->prepareBackup($requestId, $initialFingerprint);
    $assertSame(false, $backupAgain['created'], 'Runtime backup preparation must be idempotent');

    $sealed = $writer->writeSealed($gate);
    $assertSame('json_sealed', $sealed['state'], 'Sealed runtime state must be exact');
    $assertSame(false, $sealed['database_route_enabled'], 'Sealed runtime must disable DB route');
    $assertSame(true, $sealed['maintenance_enabled'], 'Sealed runtime must keep maintenance');
    $assertSame(true, $sealed['financial_read_only'], 'Sealed runtime must keep financial read-only');
    $sealedRuntime = $writer->load();
    $assertSame(false, $sealedRuntime['database_runtime']['enabled'], 'Sealed runtime enabled flag must be false');
    $assertSame(false, $sealedRuntime['database_runtime']['production_activated'], 'Sealed activation marker must be false');
    foreach ($sealedRuntime['database_runtime']['modules'] as $module => $enabled) {
        $assertSame(false, $enabled, 'Sealed runtime module must be false: ' . $module);
    }
    $assertSame(false, $sealedRuntime['features']['matchmaking'], 'Unrelated runtime flags must be preserved');

    $released = $writer->writeReleased($gate);
    $assertSame('completed', $released['state'], 'Released runtime state must be completed');
    $assertSame(false, $released['maintenance_enabled'], 'Released runtime must disable maintenance');
    $assertSame(false, $released['financial_read_only'], 'Released runtime must disable financial read-only');
    $releasedRuntime = $writer->load();
    $assertSame(false, $releasedRuntime['database_runtime']['enabled'], 'Released runtime must remain JSON-first');
    $assertSame('completed', $releasedRuntime['production_live_rollback']['state'], 'Released marker must be completed');
    $assertSame(0600, $mode($runtimeFile), 'Published runtime overlay must remain private');

    $restored = $writer->restoreAuthorizedBackup($requestId, $initialFingerprint);
    $assertSame(true, $restored['runtime_restored'], 'Authorized runtime backup must restore');
    $assertSame($initialFingerprint, $writer->fingerprint(), 'Restored runtime fingerprint must match original');
    $assertTrue($writer->load() === $initial, 'Restored runtime array must match original exactly');
} finally {
    $remove($root);
}

fwrite(STDOUT, "ProductionPrimaryRuntimeOverlayWriterTest passed: {$assertions} assertions.\n");
