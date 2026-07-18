<?php
declare(strict_types=1);

require dirname(__DIR__) . '/cutover/CutoverReadinessService.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};

$root = sys_get_temp_dir() . '/mgw-cutover-readiness-' . bin2hex(random_bytes(5));
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
};
$write = static function (string $relative, string $content) use ($root): void {
    $path = $root . '/' . $relative;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    file_put_contents($path, $content);
};

try {
    foreach ([
        'bot/storage/contracts/StorageTransactionInterface.php',
        'bot/storage/contracts/StorageAdapterInterface.php',
        'bot/storage/JsonStorageAdapter.php',
        'bot/database/PdoConnectionFactory.php',
        'bot/database/MigrationRunner.php',
        'bot/accounts/AccountIdentityService.php',
        'bot/realtime/RealtimeDatabaseStore.php',
        'bot/ledger/LedgerWriteService.php',
        'bot/migration/StagingJsonDbFinalReconciliationService.php',
        'ops/backup/verify.php',
        'ops/backup/restore.php',
        'ops/migration/staging-json-db-reconciliation.php',
    ] as $required) {
        $write($required, "<?php\n");
    }
    $write('bot/storage/StorageFactory.php', <<<'PHPFILE'
<?php
return match ($driver) {
    'json' => self::createJson($dataDir),
    default => throw new RuntimeException('unsupported'),
};
PHPFILE);
    $write('bot/api.php', <<<'PHPFILE'
<?php
$storage = StorageFactory::createJson($dataDir);
$storage->transaction(function (array &$data): void { $data['x'] = true; });
PHPFILE);

    $service = new CutoverReadinessService($root);
    $inventory = $service->inspectSource();

    $assertSame([], $inventory['missing_required_paths'], 'All required fixture paths must exist');
    $assertSame(false, $inventory['storage_factory_has_database_driver'], 'JSON-only factory must be detected');
    $assertSame(1, count($inventory['explicit_json_factory_calls']), 'Explicit JSON factory call must be inventoried');
    $assertSame(1, count($inventory['legacy_array_snapshot_callbacks']), 'Legacy array callback must be inventoried');
    $assertSame([], $inventory['direct_json_database_instantiations'], 'Adapter-only fixture must not report direct JsonDatabase use');
    $assertTrue(count($inventory['runtime_adapter_work_items']) === 3, 'All three runtime adapter work items must be listed');
    $assertTrue(strlen((string)$inventory['inventory_fingerprint']) === 64, 'Inventory fingerprint must be SHA-256');

    $runtime = [
        'environment' => 'staging',
        'storage_driver' => 'json',
        'database_enabled' => true,
        'database_connected' => true,
        'schema_current' => true,
        'applied_migrations' => 7,
        'pending_migrations' => 0,
    ];
    $reconciliation = [
        'ok' => true,
        'count_parity_complete' => true,
        'report_fingerprint' => str_repeat('a', 64),
        'blocking_reasons' => [],
        'migration_gaps' => [],
    ];
    $snapshotHash = str_repeat('b', 64);
    $backups = [
        'primary' => [
            'ok' => true,
            'backup_id' => '20260718T000000Z-snapshot',
            'snapshot_sha256' => $snapshotHash,
            'environment' => 'staging',
        ],
        'external' => [
            'ok' => true,
            'backup_id' => '20260718T000000Z-snapshot',
            'snapshot_sha256' => $snapshotHash,
            'environment' => 'staging',
        ],
    ];

    $ready = $service->evaluate($runtime, $reconciliation, $backups, $inventory);
    $assertSame(true, $ready['ok'], 'Clean staging prerequisites must pass readiness');
    $assertSame(true, $ready['ready_for_mvp_14_8_2'], 'Clean report must allow adapter work');
    $assertSame(false, $ready['production_cutover_allowed'], 'MVP-14.8.1 must never allow production cutover');
    $assertSame(false, $ready['production_switch_performed'], 'Readiness must not claim a switch');
    $assertSame([], $ready['blockers'], 'Expected adapter work items are not operational blockers');
    $assertSame(true, $ready['backup_pair']['primary_environment_matches_runtime'], 'Primary backup must match the runtime environment');
    $assertSame(true, $ready['backup_pair']['external_environment_matches_runtime'], 'External backup must match the runtime environment');
    $assertSame(true, $ready['backup_pair']['same_verified_snapshot'], 'Primary and external backups must be the same snapshot');
    $assertTrue(strlen((string)$ready['readiness_fingerprint']) === 64, 'Readiness fingerprint must be SHA-256');

    $blockedBackups = $backups;
    $blockedBackups['external'] = ['ok' => false];
    $blocked = $service->evaluate($runtime, ['ok' => false, 'count_parity_complete' => false], $blockedBackups, $inventory);
    $assertSame(false, $blocked['ready_for_mvp_14_8_2'], 'Failed reconciliation and external backup must block readiness');
    $assertTrue(in_array('final JSON to DB reconciliation is not clean', $blocked['blockers'], true), 'Reconciliation blocker must be explicit');
    $assertTrue(in_array('latest external JSON backup did not verify', $blocked['blockers'], true), 'External backup blocker must be explicit');

    $mismatchedBackups = $backups;
    $mismatchedBackups['external']['backup_id'] = 'older-external-snapshot';
    $mismatched = $service->evaluate($runtime, $reconciliation, $mismatchedBackups, $inventory);
    $assertSame(false, $mismatched['ready_for_mvp_14_8_2'], 'A stale or unrelated external snapshot must block readiness');
    $assertTrue(in_array('primary and external backups are not the same verified snapshot', $mismatched['blockers'], true), 'Backup pair mismatch must be explicit');

    $wrongEnvironmentBackups = $backups;
    $wrongEnvironmentBackups['primary']['environment'] = 'production';
    $wrongEnvironment = $service->evaluate($runtime, $reconciliation, $wrongEnvironmentBackups, $inventory);
    $assertTrue(in_array('primary backup environment does not match runtime environment', $wrongEnvironment['blockers'], true), 'Cross-environment backup must block readiness');

    $unsafeConstructor = 'new ' . 'JsonDatabase' . "('/tmp/data')";
    $write('bot/unsafe.php', "<?php\n\$db = {$unsafeConstructor};\n");
    $unsafeInventory = $service->inspectSource();
    $unsafe = $service->evaluate($runtime, $reconciliation, $backups, $unsafeInventory);
    $assertSame(1, count($unsafeInventory['direct_json_database_instantiations']), 'Unsafe direct construction must be located');
    $assertTrue(in_array('direct JsonDatabase construction exists outside JsonStorageAdapter', $unsafe['blockers'], true), 'Unsafe direct construction must block readiness');

    fwrite(STDOUT, "CutoverReadinessServiceTest: {$assertions} assertions passed\n");
} finally {
    $remove($root);
}
