<?php
declare(strict_types=1);

interface DatabaseConnectionInterface
{
    public function driver(): string;
    public function execute(string $sql, array $parameters = []): int;
    public function fetchAll(string $sql, array $parameters = []): array;
    public function fetchValue(string $sql, array $parameters = []): mixed;
    public function transaction(callable $callback): mixed;
}

final class RuntimePrimaryStagingSchemaInspectorTestDatabase implements DatabaseConnectionInterface
{
    public int $executeCalls = 0;

    public function __construct(private bool $invalidOutboxStatusType = false) {}

    public function driver(): string { return 'mysql'; }
    public function execute(string $sql, array $parameters = []): int
    {
        $this->executeCalls++;
        if (!str_starts_with(strtolower(trim($sql)), 'create table if not exists')) {
            throw new RuntimeException('Unexpected schema test mutation.');
        }
        return 0;
    }
    public function fetchAll(string $sql, array $parameters = []): array
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if ($normalized === 'show columns from mgw_runtime_primary_state') {
            return [
                ['Field' => 'singleton_id', 'Type' => 'tinyint unsigned', 'Null' => 'NO', 'Key' => 'PRI'],
                ['Field' => 'revision', 'Type' => 'bigint unsigned', 'Null' => 'NO', 'Key' => ''],
                ['Field' => 'state_json', 'Type' => 'longtext', 'Null' => 'NO', 'Key' => ''],
                ['Field' => 'state_sha256', 'Type' => 'char(64)', 'Null' => 'NO', 'Key' => ''],
                ['Field' => 'created_at_utc', 'Type' => 'varchar(40)', 'Null' => 'NO', 'Key' => ''],
                ['Field' => 'updated_at_utc', 'Type' => 'varchar(40)', 'Null' => 'NO', 'Key' => ''],
            ];
        }
        if ($normalized === 'show columns from mgw_runtime_primary_projection_outbox') {
            return [
                ['Field' => 'state_revision', 'Type' => 'bigint unsigned', 'Null' => 'NO', 'Key' => 'PRI'],
                ['Field' => 'event_id', 'Type' => 'char(64)', 'Null' => 'NO', 'Key' => ''],
                ['Field' => 'projection_version', 'Type' => 'varchar(64)', 'Null' => 'NO', 'Key' => ''],
                ['Field' => 'state_sha256', 'Type' => 'char(64)', 'Null' => 'NO', 'Key' => ''],
                ['Field' => 'state_json', 'Type' => 'longtext', 'Null' => 'NO', 'Key' => ''],
                ['Field' => 'status', 'Type' => $this->invalidOutboxStatusType ? 'int' : 'varchar(16)', 'Null' => 'NO', 'Key' => ''],
                ['Field' => 'attempt_count', 'Type' => 'int unsigned', 'Null' => 'NO', 'Key' => ''],
                ['Field' => 'lease_token', 'Type' => 'varchar(64)', 'Null' => 'NO', 'Key' => ''],
                ['Field' => 'lease_expires_at_utc', 'Type' => 'varchar(40)', 'Null' => 'NO', 'Key' => ''],
                ['Field' => 'last_error', 'Type' => 'varchar(500)', 'Null' => 'NO', 'Key' => ''],
                ['Field' => 'available_at_utc', 'Type' => 'varchar(40)', 'Null' => 'NO', 'Key' => ''],
                ['Field' => 'created_at_utc', 'Type' => 'varchar(40)', 'Null' => 'NO', 'Key' => ''],
                ['Field' => 'updated_at_utc', 'Type' => 'varchar(40)', 'Null' => 'NO', 'Key' => ''],
            ];
        }
        if (str_starts_with($normalized, "show table status like 'mgw_runtime_primary_state'")) {
            return [['Engine' => 'InnoDB']];
        }
        if (str_starts_with($normalized, "show table status like 'mgw_runtime_primary_projection_outbox'")) {
            return [['Engine' => 'InnoDB']];
        }
        throw new RuntimeException('Unexpected schema test query: ' . $normalized);
    }
    public function fetchValue(string $sql, array $parameters = []): mixed { return null; }
    public function transaction(callable $callback): mixed { return $callback($this); }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStateSchemaInstaller.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingSchemaInspector.php';

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

$database = new RuntimePrimaryStagingSchemaInspectorTestDatabase();
$stateInstalled = (new RuntimePrimaryStateSchemaInstaller($database))->install();
$outboxInstalled = (new RuntimePrimaryProjectionOutboxSchemaInstaller($database))->install();
$executeBeforeInspection = $database->executeCalls;
$inspected = (new RuntimePrimaryStagingSchemaInspector($database))->inspect();
$assertTrue($database->executeCalls === $executeBeforeInspection, 'Schema inspector must not execute DDL or mutations');
$assertTrue(($inspected['read_only'] ?? false) === true, 'Schema inspector must report read-only behavior');
$assertTrue(
    hash_equals((string)$stateInstalled['schema_fingerprint'], (string)$inspected['state']['schema_fingerprint']),
    'Read-only state schema fingerprint must match the installer contract'
);
$assertTrue(
    hash_equals((string)$outboxInstalled['schema_fingerprint'], (string)$inspected['outbox']['schema_fingerprint']),
    'Read-only outbox schema fingerprint must match the installer contract'
);
$assertTrue(($inspected['state']['engine'] ?? '') === 'innodb', 'State schema must require InnoDB');
$assertTrue(($inspected['outbox']['engine'] ?? '') === 'innodb', 'Outbox schema must require InnoDB');

$invalid = new RuntimePrimaryStagingSchemaInspectorTestDatabase(true);
$assertThrows(
    static fn() => (new RuntimePrimaryStagingSchemaInspector($invalid))->inspect(),
    'outbox schema type is invalid: status'
);

fwrite(STDOUT, "RuntimePrimaryStagingSchemaInspectorTest passed: {$assertions} assertions.\n");
