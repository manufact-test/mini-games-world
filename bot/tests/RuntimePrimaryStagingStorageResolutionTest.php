<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/storage/contracts/StorageTransactionInterface.php';
require $projectRoot . '/bot/storage/contracts/StorageAdapterInterface.php';
require $projectRoot . '/bot/database/DatabaseConnectionInterface.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStateSchemaInstaller.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxWriter.php';
require $projectRoot . '/bot/runtime/DatabasePrimaryStateStorageAdapter.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingStorageResolution.php';

final class RuntimePrimaryStagingStorageResolutionTestDatabase implements DatabaseConnectionInterface
{
    public function __construct(private array $row) {}
    public function driver(): string { return 'mysql'; }
    public function execute(string $sql, array $parameters = []): int
    {
        throw new RuntimeException('Resolution test must not mutate the database.');
    }
    public function fetchAll(string $sql, array $parameters = []): array
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_starts_with($normalized, 'select singleton_id, revision, state_json, state_sha256')) {
            return [$this->row];
        }
        throw new RuntimeException('Unexpected resolution test query: ' . $normalized);
    }
    public function fetchValue(string $sql, array $parameters = []): mixed { return null; }
    public function transaction(callable $callback): mixed { return $callback($this); }
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

$snapshot = ['users' => [], 'games' => [], 'system' => ['sequence' => 1]];
ksort($snapshot, SORT_STRING);
$stateJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$stateSha = hash('sha256', $stateJson);
$database = new RuntimePrimaryStagingStorageResolutionTestDatabase([
    'singleton_id' => 1,
    'revision' => 4,
    'state_json' => $stateJson,
    'state_sha256' => $stateSha,
    'created_at_utc' => '2026-07-20T16:00:00+00:00',
    'updated_at_utc' => '2026-07-20T17:00:00+00:00',
]);
$storage = new DatabasePrimaryStateStorageAdapter(
    $database,
    new RuntimePrimaryProjectionOutboxWriter()
);
$status = $storage->status();
$readiness = [
    'activation_allowed' => true,
    'read_only_audit' => true,
    'drift_check_passed' => true,
    'state_revision' => 4,
    'state_sha256' => $stateSha,
    'repository_commit' => str_repeat('a', 40),
    'database_identity_fingerprint' => str_repeat('b', 64),
    'evidence_fingerprint' => str_repeat('c', 64),
    'all_module_fingerprint' => str_repeat('d', 64),
];

$resolution = new RuntimePrimaryStagingStorageResolution($storage, $readiness, $status);
$assertTrue($resolution->storage() === $storage, 'Resolution must preserve the exact validated storage instance');
$report = $resolution->safeReport();
$assertTrue(($report['resolved'] ?? false) === true, 'Resolution report must identify successful resolution');
$assertTrue(($report['storage_driver'] ?? '') === 'database', 'Resolution report must identify DB-primary storage');
$assertTrue(($report['rollback_driver'] ?? '') === 'json', 'Resolution report must preserve JSON rollback');
$assertTrue(($report['projection_outbox_enabled'] ?? false) === true, 'Resolution must require the projection outbox');
$assertTrue(($report['application_entrypoint_routed'] ?? true) === false, 'Resolution must not claim application routing');
$assertTrue(($report['production_changed'] ?? true) === false, 'Resolution must not claim production mutation');

$notReady = $readiness;
$notReady['activation_allowed'] = false;
$assertThrows(
    static fn() => new RuntimePrimaryStagingStorageResolution($storage, $notReady, $status),
    'missing activation readiness evidence'
);

$wrongRevision = $readiness;
$wrongRevision['state_revision'] = 5;
$assertThrows(
    static fn() => new RuntimePrimaryStagingStorageResolution($storage, $wrongRevision, $status),
    'no longer matches activation readiness'
);

$withoutOutbox = new DatabasePrimaryStateStorageAdapter($database);
$withoutOutboxStatus = $withoutOutbox->status();
$assertThrows(
    static fn() => new RuntimePrimaryStagingStorageResolution(
        $withoutOutbox,
        $readiness,
        $withoutOutboxStatus
    ),
    'projection outbox enabled'
);

fwrite(STDOUT, "RuntimePrimaryStagingStorageResolutionTest passed: {$assertions} assertions.\n");
