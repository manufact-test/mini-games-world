<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/storage/contracts/StorageTransactionInterface.php';
require_once dirname(__DIR__) . '/storage/contracts/StorageAdapterInterface.php';
require_once dirname(__DIR__) . '/storage/JsonDatabase.php';
require_once dirname(__DIR__) . '/storage/JsonStorageAdapter.php';
require_once dirname(__DIR__) . '/database/DatabaseConfig.php';
require_once dirname(__DIR__) . '/storage/RuntimeStorageRouter.php';
require_once dirname(__DIR__) . '/cutover/seal/SealedSnapshotControlService.php';
require_once dirname(__DIR__) . '/cutover/frozen/FrozenSnapshotRehearsalService.php';
require_once dirname(__DIR__, 2) . '/ops/backup/BackupManager.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$root = sys_get_temp_dir() . '/mgw-frozen-snapshot-' . bin2hex(random_bytes(6));
$projectRoot = $root . '/project';
$dataDir = $root . '/live-data';
$privateDir = $root . '/private';
$primaryRoot = $root . '/primary';
$externalRoot = $root . '/external';
$restoreRoot = $privateDir . '/restore-rehearsals';
foreach ([$projectRoot, $dataDir, $privateDir] as $directory) mkdir($directory, 0700, true);

$deleteTree = static function (string $path) use (&$deleteTree): void {
    if (!is_dir($path)) { if (is_file($path)) @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $child = $path . '/' . $entry;
        if (is_dir($child)) $deleteTree($child); else @unlink($child);
    }
    @rmdir($path);
};

try {
    $config = [
        'environment' => 'staging',
        'storage_driver' => 'json',
        'data_dir' => $dataDir,
        'feature_flags' => ['database_runtime' => ['enabled' => false, 'modules' => []]],
    ];
    $storage = new JsonStorageAdapter($dataDir);
    $storage->transaction(static function (array &$data): void {
        $data['users']['player'] = [
            'id' => 'player',
            'status' => 'idle',
            'current_game_id' => null,
            'balance_match' => 50,
            'balance_gold' => 5,
        ];
        $data['games']['finished_game'] = [
            'id' => 'finished_game',
            'status' => 'finished',
            'game_type' => 'domino',
            'player_ids' => ['player', 'bot_one'],
        ];
        $data['queue'] = [];
        $data['invites'] = [];
        $data['notifications'][] = ['id' => 'notice_one', 'user_id' => 'player'];
    });

    $controlFile = $privateDir . '/cutover-rehearsal.json';
    file_put_contents($controlFile, json_encode([
        'schema_version' => 1,
        'environment' => 'staging',
        'rehearsal_id' => 'rehearsal_snapshot_test',
        'state' => 'frozen',
        'started_at_utc' => gmdate(DATE_ATOM),
        'sealed_at_utc' => null,
        'released_at_utc' => null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $seal = new SealedSnapshotControlService($config, $storage, $controlFile);
    $sealed = $seal->seal();
    $assert($sealed['frozen_snapshot']['ready'] === true, 'Sealed empty runtime must be snapshot-ready.');
    $router = new RuntimeStorageRouter($config);
    $manager = new BackupManager(
        $projectRoot,
        $dataDir,
        $primaryRoot,
        $externalRoot,
        7,
        7,
        false
    );
    $service = new FrozenSnapshotRehearsalService(
        $config,
        $seal,
        $manager,
        $router,
        $primaryRoot,
        $externalRoot,
        $restoreRoot,
        $privateDir . '/frozen-snapshot-rehearsal.json'
    );
    $reconciliation = static fn(): array => [
        'ok' => true,
        'read_only' => true,
        'count_parity_complete' => true,
        'report_fingerprint' => hash('sha256', 'clean-test-reconciliation'),
        'blocking_reasons' => [],
        'migration_gaps' => [],
    ];

    $result = $service->prepare('test-build', $reconciliation);
    $assert($result['ok'] === true, 'Frozen snapshot and reconciliation must complete successfully.');
    $assert($result['backup_pair']['same_verified_snapshot'] === true, 'Primary and external snapshots must match exactly.');
    $assert($result['backup_pair']['primary_environment_matches'] === true, 'Primary snapshot environment must match staging.');
    $assert($result['backup_pair']['external_build_matches'] === true, 'External snapshot build must match.');
    $assert($result['data_snapshot']['primary_external_equal'] === true, 'Primary and external JSON fingerprints must match.');
    $assert($result['data_snapshot']['external_restore_equal'] === true, 'External and restored JSON fingerprints must match.');
    $assert($result['restore_rehearsal']['exact_snapshot_restored'] === true, 'Isolated restore must reproduce the exact snapshot.');
    $assert($result['restore_rehearsal']['temporary_target_removed'] === true, 'Temporary restore target must be removed.');
    $assert($result['restore_rehearsal']['live_data_target_used'] === false, 'Live data directory must never be used as restore target.');
    $assert($result['final_reconciliation']['ok'] === true, 'Final reconciliation must remain clean.');
    $assert($result['switch_rehearsal']['ready'] === false, 'DB switch must remain blocked while runtime modules are missing.');
    $assert(in_array('economy', $result['database_runtime']['missing_modules'], true), 'Missing economy runtime must be explicit.');
    $assert($result['switch_rehearsal']['production_switch_performed'] === false, 'Production switch must never run.');
    $assert(is_file($dataDir . '/.cutover-write-block'), 'JSON write barrier must remain active until explicit release.');

    $repeat = $service->prepare('test-build', $reconciliation);
    $assert($repeat['idempotent'] === true, 'Repeated prepare for the same rehearsal must be idempotent.');
    $assert($repeat['action'] === 'prepare_noop', 'Repeated prepare must report a no-op.');
    $snapshotDirs = array_values(array_filter(glob($primaryRoot . '/*') ?: [], 'is_dir'));
    $assert(count($snapshotDirs) === 1, 'Idempotent repeat must not create another primary snapshot.');

    $status = $service->status();
    $assert($status['state'] === 'completed', 'Private rehearsal state must remain available.');
    $seal->release('test complete');
    $assert(!is_file($dataDir . '/.cutover-write-block'), 'Explicit release must remove the JSON write barrier.');

    file_put_contents($controlFile, json_encode([
        'schema_version' => 2,
        'environment' => 'staging',
        'rehearsal_id' => 'rehearsal_cleanup_failure_test',
        'state' => 'frozen',
        'started_at_utc' => gmdate(DATE_ATOM),
        'sealed_at_utc' => null,
        'released_at_utc' => null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $seal->seal();

    $cleanupFailureCaught = false;
    try {
        $service->prepare('test-build', static function () use ($primaryRoot): array {
            $snapshots = array_values(array_filter(glob($primaryRoot . '/*') ?: [], 'is_dir'));
            rsort($snapshots, SORT_STRING);
            $latest = $snapshots[0] ?? '';
            foreach (glob($latest . '/data/*.json') ?: [] as $jsonFile) unlink($jsonFile);
            return [
                'ok' => true,
                'read_only' => true,
                'count_parity_complete' => true,
                'report_fingerprint' => hash('sha256', 'cleanup-failure-reconciliation'),
                'blocking_reasons' => [],
                'migration_gaps' => [],
            ];
        });
    } catch (Throwable) {
        $cleanupFailureCaught = true;
    }
    $assert($cleanupFailureCaught, 'Corrupted post-backup fingerprint input must fail the rehearsal.');
    $restoreDirsAfterFailure = array_values(array_filter(glob($restoreRoot . '/restore-*') ?: [], 'is_dir'));
    $assert($restoreDirsAfterFailure === [], 'Failed fingerprint verification must still remove the isolated restore target.');
    $seal->emergencyRelease('cleanup failure test complete');
    $assert(!is_file($dataDir . '/.cutover-write-block'), 'Emergency release must remove the barrier after a failed rehearsal.');

    $production = $config;
    $production['environment'] = 'production';
    $productionBlocked = false;
    try {
        (new FrozenSnapshotRehearsalService(
            $production,
            new SealedSnapshotControlService($production, $storage, $controlFile),
            $manager,
            new RuntimeStorageRouter($production),
            $primaryRoot,
            $externalRoot,
            $restoreRoot,
            $privateDir . '/production-frozen-state.json'
        ))->prepare('test-build', $reconciliation);
    } catch (RuntimeException $error) {
        $productionBlocked = str_contains($error->getMessage(), 'staging or local');
    }
    $assert($productionBlocked, 'Production frozen snapshot rehearsal must fail closed.');
    fwrite(STDOUT, "FrozenSnapshotRehearsalServiceTest: {$assertions} assertions passed\n");
} finally {
    $deleteTree($root);
}
