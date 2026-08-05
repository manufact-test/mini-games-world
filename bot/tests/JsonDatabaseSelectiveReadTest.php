<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/storage/contracts/StorageTransactionInterface.php';
require_once $root . '/bot/storage/contracts/StorageAdapterInterface.php';
require_once $root . '/bot/storage/contracts/SelectiveReadStorageInterface.php';
require_once $root . '/bot/storage/JsonDatabase.php';
require_once $root . '/bot/storage/JsonStorageAdapter.php';

$directory = sys_get_temp_dir() . '/mgw-json-selective-' . bin2hex(random_bytes(6));
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child)) $removeTree($child);
        else @unlink($child);
    }
    @rmdir($path);
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

try {
    $storage = new JsonStorageAdapter($directory);
    $assert($storage instanceof SelectiveReadStorageInterface, 'JSON adapter must expose selective read capability.');

    file_put_contents($directory . '/users.json', json_encode([
        'user-a' => ['username' => 'alpha'],
        'user-b' => ['username' => 'beta'],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($directory . '/games.json', json_encode([
        ['id' => 'game-1', 'status' => 'finished'],
    ], JSON_THROW_ON_ERROR));

    // This unrelated payload is intentionally large enough to catch accidental
    // full-snapshot reads in source/benchmark review, but selective callbacks
    // must never receive or depend on it.
    file_put_contents($directory . '/transactions.json', json_encode([
        'payload' => str_repeat('x', 1024 * 1024),
    ], JSON_THROW_ON_ERROR));

    $selected = $storage->readOnlySections(
        ['users', 'games', 'users'],
        static fn(array $data): array => $data
    );
    $assert(array_keys($selected) === ['users', 'games'], 'Selective reads must preserve order and remove duplicate sections.');
    $assert(($selected['users']['user-b']['username'] ?? '') === 'beta', 'Selective users data must remain authoritative.');
    $assert(($selected['games'][0]['id'] ?? '') === 'game-1', 'Selective games data must remain authoritative.');
    $assert(!array_key_exists('transactions', $selected), 'Unrequested runtime ledgers must not enter the selective callback snapshot.');

    $full = $storage->readOnly(static fn(array $data): array => $data);
    $assert(count($full) === 10, 'Legacy full readOnly must continue returning the complete ten-section snapshot.');
    $assert(isset($full['transactions']['payload']), 'Legacy full readOnly must remain backward compatible.');

    $invalidRejected = false;
    try {
        $storage->readOnlySections(['users', 'unknown-section'], static fn(array $data): array => $data);
    } catch (InvalidArgumentException) {
        $invalidRejected = true;
    }
    $assert($invalidRejected, 'Unknown selective sections must fail closed instead of silently reading the wrong file.');
} finally {
    $removeTree($directory);
}

fwrite(STDOUT, "JsonDatabaseSelectiveReadTest: {$assertions} assertions passed\n");
