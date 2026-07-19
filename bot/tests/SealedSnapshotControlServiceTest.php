<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/storage/contracts/StorageTransactionInterface.php';
require_once dirname(__DIR__) . '/storage/contracts/StorageAdapterInterface.php';
require_once dirname(__DIR__) . '/storage/JsonDatabase.php';
require_once dirname(__DIR__) . '/storage/JsonStorageAdapter.php';
require_once dirname(__DIR__) . '/core/RuntimeConfigLoader.php';
require_once dirname(__DIR__) . '/cutover/seal/SealedSnapshotControlService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$root = sys_get_temp_dir() . '/mgw-sealed-snapshot-' . bin2hex(random_bytes(6));
$dataDir = $root . '/data';
$privateDir = $root . '/private';
mkdir($dataDir, 0700, true);
mkdir($privateDir, 0700, true);

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
        'feature_flags' => [
            'features' => ['matchmaking' => true, 'invitations' => true, 'payments' => true],
        ],
    ];
    $storage = new JsonStorageAdapter($dataDir);
    $storage->transaction(static function (array &$data): void {
        $data['users']['player'] = ['id' => 'player', 'status' => 'idle', 'current_game_id' => null];
        $data['games'] = [];
        $data['queue'] = [];
        $data['invites'] = [];
    });
    $controlFile = $privateDir . '/cutover-rehearsal.json';
    file_put_contents($controlFile, json_encode([
        'schema_version' => 1,
        'environment' => 'staging',
        'rehearsal_id' => 'rehearsal_test',
        'state' => 'frozen',
        'started_at_utc' => gmdate(DATE_ATOM),
        'sealed_at_utc' => null,
        'released_at_utc' => null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $service = new SealedSnapshotControlService($config, $storage, $controlFile);
    $before = $service->status();
    $assert($before['drain']['ready'] === true, 'Frozen empty staging data must be drained.');
    $assert($before['frozen_snapshot']['ready'] === false, 'Snapshot must require an explicit seal.');
    $assert($before['control_consistency']['ok'] === true, 'Frozen control without a marker must be consistent.');

    $sealed = $service->seal();
    $assert($sealed['freeze']['sealed'] === true, 'Seal must become active.');
    $assert($sealed['freeze']['storage_write_block_active'] === true, 'Seal must create the JSON write barrier.');
    $assert($sealed['frozen_snapshot']['ready'] === true, 'Sealed and drained state must be snapshot-ready.');
    $assert(is_file($dataDir . '/.cutover-write-block'), 'Write-block marker must exist.');

    $writeBlocked = false;
    try {
        $storage->transaction(static function (array &$data): void {
            $data['system']['should_not_write'] = true;
        });
    } catch (RuntimeException $error) {
        $writeBlocked = str_contains($error->getMessage(), 'только для чтения');
    }
    $assert($writeBlocked, 'All JSON transactions must fail while sealed.');
    $read = $storage->readOnly(static fn(array $data): array => $data);
    $assert(isset($read['users']['player']), 'Read-only JSON access must remain available while sealed.');

    file_put_contents($privateDir . '/runtime.php', "<?php\nreturn ['features' => ['matchmaking' => true, 'invitations' => true, 'payments' => true]];\n");
    $merged = RuntimeConfigLoader::merge($config, $privateDir . '/config.php');
    $assert(($merged['feature_flags']['features']['matchmaking'] ?? null) === false, 'Sealed runtime must block matchmaking.');
    $assert(($merged['feature_flags']['features']['invitations'] ?? null) === false, 'Sealed runtime must block invitations.');
    $assert(($merged['feature_flags']['financial_read_only'] ?? null) === true, 'Sealed runtime must expose financial read-only mode.');
    $assert(($merged['feature_flags']['cutover_rehearsal']['sealed'] ?? null) === true, 'Sealed runtime status must be visible.');

    $repeat = $service->seal();
    $assert($repeat['idempotent'] === true, 'Repeated seal must be idempotent.');
    $released = $service->release('test complete');
    $assert($released['freeze']['active'] === false, 'Release must clear the active freeze.');
    $assert($released['recovery']['write_block_removed'] === true, 'Release must confirm that the write barrier was removed.');
    $assert(!is_file($dataDir . '/.cutover-write-block'), 'Release must remove the JSON write barrier.');
    $storage->transaction(static function (array &$data): void {
        $data['system']['writes_resumed'] = true;
    });
    $after = $storage->readOnly(static fn(array $data): array => $data);
    $assert(($after['system']['writes_resumed'] ?? false) === true, 'JSON writes must resume after release.');

    file_put_contents($dataDir . '/.cutover-write-block', "{}\n");
    $staleMarker = $service->status();
    $assert($staleMarker['ok'] === false, 'A stale write barrier must make control status unhealthy.');
    $assert($staleMarker['control_consistency']['ok'] === false, 'A stale write barrier must be reported explicitly.');
    $assert(in_array('JSON write block is active without sealed control', $staleMarker['control_consistency']['blockers'], true), 'Stale marker blocker must be visible.');

    file_put_contents($controlFile, '{invalid-json');
    $emergency = $service->emergencyRelease('recover corrupt control');
    $assert($emergency['action'] === 'emergency_release', 'Emergency release action must be explicit.');
    $assert($emergency['recovery']['control_read_recovered'] === true, 'Emergency release must recover an unreadable control file.');
    $assert($emergency['recovery']['write_block_removed'] === true, 'Emergency release must remove a stale write barrier.');
    $assert(!is_file($dataDir . '/.cutover-write-block'), 'Emergency release must leave no write barrier.');
    $storage->transaction(static function (array &$data): void {
        $data['system']['emergency_writes_resumed'] = true;
    });
    $afterEmergency = $storage->readOnly(static fn(array $data): array => $data);
    $assert(($afterEmergency['system']['emergency_writes_resumed'] ?? false) === true, 'Writes must resume after emergency release.');

    $production = $config;
    $production['environment'] = 'production';
    $productionBlocked = false;
    try {
        (new SealedSnapshotControlService($production, $storage, $controlFile))->seal();
    } catch (RuntimeException $error) {
        $productionBlocked = str_contains($error->getMessage(), 'staging or local');
    }
    $assert($productionBlocked, 'Production sealing must fail closed.');
    fwrite(STDOUT, "SealedSnapshotControlServiceTest: {$assertions} assertions passed\n");
} finally {
    $deleteTree($root);
}
