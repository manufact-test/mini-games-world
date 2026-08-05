<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$interface = $read('bot/storage/contracts/ExclusiveSnapshotStorageInterface.php');
$database = $read('bot/storage/JsonDatabase.php');
$adapter = $read('bot/storage/JsonStorageAdapter.php');
$endpoint = $read('bot/invites.php');
$repository = $read('bot/invites/RuntimeInviteRepository.php');
$runtimeTest = $read('bot/tests/RuntimeInviteRepositoryTest.php');
$lockTest = $read('bot/tests/JsonDatabaseExclusiveSnapshotTest.php');
$e2e = $read('e2e/staging/d2-d3-d5-integration.spec.mjs');

$assert(str_contains($interface, 'interface ExclusiveSnapshotStorageInterface')
    && str_contains($interface, 'exclusiveReadOnlySections'),
    'The storage contract must expose an explicit exclusive snapshot capability.');
$assert(str_contains($adapter, 'ExclusiveSnapshotStorageInterface')
    && str_contains($adapter, '$this->database->exclusiveReadOnlySections('),
    'The JSON adapter must implement the exclusive snapshot contract.');
$assert(str_contains($database, 'return $this->readSectionsWithLock($sections, LOCK_EX, $callback);')
    && str_contains($database, 'flock($lockHandle, $lockMode)'),
    'The JSON database must hold one exclusive file lock through the bridge callback.');

$detached = '$snapshot = $db->readOnly(static fn(array $data): array => $data);';
$assert(!str_contains($endpoint, $detached)
    && str_contains($endpoint, '$db->exclusiveReadOnlySections(')
    && str_contains($endpoint, "['invites']")
    && str_contains($endpoint, 'static fn(array $data): array => $runtimeInvites->synchronize($data)'),
    'Invite synchronization must consume the snapshot while its exclusive JSON lock is still held.');

$assert(str_contains($repository, "SELECT GET_LOCK(:lock_name, 10)")
    && str_contains($repository, "SELECT RELEASE_LOCK(:lock_name)")
    && str_contains($repository, 'return $database->transaction(')
    && str_contains($repository, 'Invite DB synchronization lock is unavailable.'),
    'The DB mirror must use one process-wide lock and one outer transaction.');
$assert(str_contains($runtimeTest, 'A failed full synchronization must roll back rows inserted earlier in the same pass')
    && str_contains($lockTest, 'JSON writer must remain blocked while the exclusive snapshot callback runs.'),
    'Focused tests must prove DB rollback and JSON writer serialization.');
$assert(str_contains($e2e, 'create_direct status; public error: ${createPublicError}')
    && str_contains($e2e, 'Share, picker and cancellation keep terminal card in place'),
    'The live two-player regression must preserve the exact rapid Share-to-direct path.');

fwrite(STDOUT, "ProductionMvp14InviteBridgeSerializedSnapshotTest: {$assertions} assertions passed\n");
