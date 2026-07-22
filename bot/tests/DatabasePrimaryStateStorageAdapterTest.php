<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/storage/contracts/StorageTransactionInterface.php';
require $projectRoot . '/bot/storage/contracts/StorageAdapterInterface.php';
require $projectRoot . '/bot/database/DatabaseConnectionInterface.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStateSchemaInstaller.php';
require $projectRoot . '/bot/runtime/DatabasePrimaryStateStorageAdapter.php';

final class DatabasePrimaryStateTestConnection implements DatabaseConnectionInterface
{
    private ?array $row = null;
    private bool $schemaInstalled = false;

    public function __construct(private bool $invalidRevisionType = false) {}

    public function driver(): string
    {
        return 'sqlite';
    }

    public function execute(string $sql, array $params = []): int
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_starts_with($normalized, 'create table if not exists')) {
            $this->schemaInstalled = true;
            return 0;
        }
        if (str_starts_with($normalized, 'insert into ' . RuntimePrimaryStateSchemaInstaller::TABLE)) {
            if ($this->row !== null) throw new RuntimeException('duplicate singleton');
            $this->row = [
                'singleton_id' => 1,
                'revision' => (int)$params['revision'],
                'state_json' => (string)$params['state_json'],
                'state_sha256' => (string)$params['state_sha256'],
                'created_at_utc' => (string)$params['created_at_utc'],
                'updated_at_utc' => (string)$params['updated_at_utc'],
            ];
            return 1;
        }
        if (str_starts_with($normalized, 'update ' . RuntimePrimaryStateSchemaInstaller::TABLE)
            && str_contains($normalized, 'set revision = :next_revision')) {
            if ($this->row === null
                || (int)$this->row['revision'] !== (int)$params['expected_revision']) {
                return 0;
            }
            $this->row['revision'] = (int)$params['next_revision'];
            $this->row['state_json'] = (string)$params['state_json'];
            $this->row['state_sha256'] = (string)$params['state_sha256'];
            $this->row['updated_at_utc'] = (string)$params['updated_at_utc'];
            return 1;
        }
        if (str_starts_with($normalized, 'update ' . RuntimePrimaryStateSchemaInstaller::TABLE)
            && str_contains($normalized, 'set state_sha256 = :fingerprint')) {
            if ($this->row === null) return 0;
            $this->row['state_sha256'] = (string)$params['fingerprint'];
            return 1;
        }
        throw new RuntimeException('Unexpected test SQL execute: ' . $normalized);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_starts_with($normalized, 'pragma table_info(')) {
            if (!$this->schemaInstalled) return [];
            return [
                ['name' => 'singleton_id', 'type' => 'INTEGER', 'notnull' => 1, 'pk' => 1],
                ['name' => 'revision', 'type' => $this->invalidRevisionType ? 'TEXT' : 'INTEGER', 'notnull' => 1, 'pk' => 0],
                ['name' => 'state_json', 'type' => 'TEXT', 'notnull' => 1, 'pk' => 0],
                ['name' => 'state_sha256', 'type' => 'TEXT', 'notnull' => 1, 'pk' => 0],
                ['name' => 'created_at_utc', 'type' => 'TEXT', 'notnull' => 1, 'pk' => 0],
                ['name' => 'updated_at_utc', 'type' => 'TEXT', 'notnull' => 1, 'pk' => 0],
            ];
        }
        if (str_starts_with($normalized, 'select singleton_id, revision, state_json')) {
            return $this->row === null ? [] : [$this->row];
        }
        throw new RuntimeException('Unexpected test SQL fetch: ' . $normalized);
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $rows = $this->fetchAll($sql, $params);
        if ($rows === []) return null;
        $firstRow = $rows[0] ?? null;
        if (!is_array($firstRow) || $firstRow === []) return null;
        $value = reset($firstRow);
        return $value === false ? null : $value;
    }

    public function transaction(callable $callback): mixed
    {
        $before = $this->row;
        try {
            return $callback($this);
        } catch (Throwable $error) {
            $this->row = $before;
            throw $error;
        }
    }

    public function pdo(): PDO
    {
        throw new RuntimeException('PDO is not used by the focused fake database.');
    }
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

$invalidInstaller = new RuntimePrimaryStateSchemaInstaller(
    new DatabasePrimaryStateTestConnection(true)
);
$assertThrows(
    static fn() => $invalidInstaller->install(),
    'column type is invalid: revision'
);

$database = new DatabasePrimaryStateTestConnection();
$installer = new RuntimePrimaryStateSchemaInstaller($database);
$installed = $installer->install();
$assertTrue(($installed['ok'] ?? false) === true, 'Runtime primary state schema must install');
$assertTrue(($installed['driver'] ?? '') === 'sqlite', 'Focused schema contract must use SQLite-compatible SQL');
$assertTrue(($installed['engine'] ?? '') === 'sqlite', 'Focused schema contract must report SQLite engine');
$assertTrue(
    preg_match('/^[a-f0-9]{64}$/', (string)($installed['schema_fingerprint'] ?? '')) === 1,
    'Schema installer must produce a stable fingerprint'
);
$installedAgain = $installer->install();
$assertTrue(($installedAgain['schema_fingerprint'] ?? '') === ($installed['schema_fingerprint'] ?? ''), 'Schema install must be idempotent');

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
$assertThrows(
    static fn() => $adapter->status(),
    'fingerprint mismatch'
);
$assertThrows(
    static fn() => $adapter->initializeFromSnapshot($afterRollback),
    'fingerprint mismatch'
);

fwrite(STDOUT, "DatabasePrimaryStateStorageAdapterTest passed: {$assertions} assertions.\n");
