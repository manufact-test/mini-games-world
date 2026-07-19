<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/database/MigrationRepository.php';
require $root . '/database/MigrationRunner.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LegacyFinancialStatusNormalizer.php';
require $root . '/ledger/LegacyFinancialArchiveImportService.php';
require $root . '/ledger/LegacyFinancialArchiveDeltaService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('LegacyFinancialArchiveDeltaServiceTest requires pdo_sqlite.');
}

final class ArchiveDeltaTestStorage implements StorageAdapterInterface
{
    public function __construct(private array $data) {}
    public function replace(array $data): void { $this->data = $data; }
    public function transaction(callable $callback): mixed { return $callback($this->data); }
    public function readOnly(callable $callback): mixed { $snapshot = $this->data; return $callback($snapshot); }
    public function driver(): string { return 'json'; }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $root . '/database/migrations');
$assertSame(7, $runner->migrate(false)['executed_count'], 'Archive delta test must create every schema');

$source = [
    'payments' => [],
    'shop_orders' => [],
    'transactions' => [],
];
$storage = new ArchiveDeltaTestStorage($source);
$import = new LegacyFinancialArchiveImportService(
    $storage,
    $database,
    new LegacyFinancialStatusNormalizer()
);
$import->run();
$delta = new LegacyFinancialArchiveDeltaService($database, $import);

$appended = $source;
$appended['payments'][] = [
    'id' => 'payment-delta-1',
    'user_id' => '1001',
    'status' => 'pending',
    'room' => 'match',
    'coins' => 10,
    'created_at' => '2026-07-19T12:00:00+00:00',
];
$storage->replace($appended);
$preview = $delta->preview();
$assertSame(true, $preview['ready'], 'Append-only delta must be accepted');
$assertSame(true, $preview['requires_metadata_advance'], 'Append-only delta must require controlled metadata advancement');
$first = $delta->run();
$assertSame(true, $first['metadata_advanced'], 'First delta run must advance metadata');
$assertSame(1, $first['created_counts']['payments'], 'First delta run must create one payment archive row');
$repeat = $delta->run();
$assertSame(false, $repeat['metadata_advanced'], 'Repeat must not advance metadata');
$assertSame(0, array_sum($repeat['created_counts']), 'Repeat must not create rows');

$meta = json_decode((string)$database->fetchValue(
    "SELECT meta_value FROM mgw_meta WHERE meta_key = 'legacy_financial_archive_import_v1'"
), true, 512, JSON_THROW_ON_ERROR);
$assertSame('completed', $meta['status'], 'Successful delta must return metadata to completed');
$assertSame($first['source_fingerprint'], $meta['source_fingerprint'], 'Completed metadata must use the appended fingerprint');

fwrite(STDOUT, "LegacyFinancialArchiveDeltaServiceTest passed: {$assertions} assertions.\n");
