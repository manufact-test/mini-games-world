<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/storage/contracts/StorageTransactionInterface.php';
require $projectRoot . '/bot/storage/contracts/StorageAdapterInterface.php';
require $projectRoot . '/bot/database/DatabaseConnectionInterface.php';
require $projectRoot . '/bot/database/PdoDatabaseConnection.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStateSchemaInstaller.php';
require $projectRoot . '/bot/runtime/DatabasePrimaryStateStorageAdapter.php';

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDOUT, "DatabasePrimaryStateStorageAdapterTest skipped: pdo_sqlite unavailable.\n");
    exit(0);
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};

$pdo = new PDO('sqlite::memory:');
$database = new PdoDatabaseConnection($pdo);
$installer = new RuntimePrimaryStateSchemaInstaller($database);
$installed = $installer->install();
$assertTrue(($installed['ok'] ?? false) === true, 'Runtime primary state schema must install');
$assertTrue(($installed['driver'] ?? '') === 'sqlite', 'Runtime primary state test must use SQLite');
$assertTrue(
    preg_match('/^[a-f0-9]{64}$/', (string)($installed['schema_fingerprint'] ?? '')) === 1,
    'Schema installer must produce a stable fingerprint'
);

$adapter = new DatabasePrimaryStateStorageAdapter($database);
$source = [
    'users' => [
        '100' => ['id' => '100', 'balance' => 50, 'status' => 'idle'],
    ],
    'games' => [],
    'queue' => [],
    'transactions' => [],
    'system' => ['sequence' => 1],
];

$initialized = $adapter->initializeFromSnapshot($source);
$assertTrue(($initialized['initialized'] ?? false) === true, 'First snapshot must initialize DB-primary state');
$assertTrue(($initialized['revision'] ?? 0) === 1, 'Initial DB-primary revision must be one');
$assertTrue($adapter->driver() === 'database', 'DB-primary adapter must expose database driver');

$idempotent = $adapter->initializeFromSnapshot($source);
$assertTrue(($idempotent['initialized'] ?? true) === false, 'Same snapshot initialization must be idempotent');
$assertTrue(($idempotent['idempotent'] ?? false) === true, 'Same snapshot must report idempotency');

$different = $source;
$different['users']['100']['balance'] = 51;
$assertThrows(
    static fn() => $adapter->initializeFromSnapshot($different),
    'different snapshot'
);

$read = $adapter->readOnly(static fn(array $data): array => $data);
$assertTrue($read === $source, 'Read-only DB-primary snapshot must preserve exact source structure');

$result = $adapter->transaction(static function (array &$data): string {
    $data['users']['100']['balance'] += 25;
    $data['system']['sequence']++;
    return 'updated';
});
$assertTrue($result === 'updated', 'DB-primary transaction must return callback result');

$afterWrite = $adapter->readOnly(static fn(array $data): array => $data);
$assertTrue(($afterWrite['users']['100']['balance'] ?? 0) === 75, 'DB-primary transaction must persist balance mutation');
$assertTrue(($afterWrite['system']['sequence'] ?? 0) === 2, 'DB-primary transaction must persist system mutation');
$status = $adapter->status();
$assertTrue(($status['revision'] ?? 0) === 2, 'Changed transaction must advance DB-primary revision');
$assertTrue(
    preg_match('/^[a-f0-9]{64}$/', (string)($status['state_sha256'] ?? '')) === 1,
    'DB-primary status must expose a valid state fingerprint'
);

$adapter->transaction(static fn(array &$data): int => count($data));
$assertTrue(($adapter->status()['revision'] ?? 0) === 2, 'Read-equivalent transaction must not advance revision');

$assertThrows(
    static function () use ($adapter): void {
        $adapter->transaction(static function (array &$data): void {
            $data['users']['100']['balance'] = 999;
            throw new RuntimeException('forced rollback');
        });
    },
    'forced rollback'
);
$afterRollback = $adapter->readOnly(static fn(array $data): array => $data);
$assertTrue(($afterRollback['users']['100']['balance'] ?? 0) === 75, 'Failed callback must roll back DB-primary mutation');
$assertTrue(($adapter->status()['revision'] ?? 0) === 2, 'Failed callback must not advance revision');

$database->execute(
    'UPDATE ' . RuntimePrimaryStateSchemaInstaller::TABLE . '
     SET state_sha256 = :fingerprint WHERE singleton_id = 1',
    ['fingerprint' => str_repeat('0', 64)]
);
$assertThrows(
    static fn() => $adapter->readOnly(static fn(array $data): array => $data),
    'fingerprint mismatch'
);

fwrite(STDOUT, "DatabasePrimaryStateStorageAdapterTest passed: {$assertions} assertions.\n");
