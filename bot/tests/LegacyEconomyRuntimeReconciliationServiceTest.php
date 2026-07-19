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
require $root . '/ledger/LedgerWriteService.php';
require $root . '/ledger/LedgerIntegrityVerifier.php';
require $root . '/ledger/LegacyEconomyShadowSyncService.php';
require $root . '/ledger/LegacyOpeningBalanceImportService.php';
require $root . '/ledger/LegacyEconomyDeltaImportService.php';
require $root . '/ledger/LegacyEconomyRuntimeReconciliationService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('LegacyEconomyRuntimeReconciliationServiceTest requires pdo_sqlite.');
}

final class RuntimeEconomyTestStorage implements StorageAdapterInterface
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
$assertSame(7, $runner->migrate(false)['executed_count'], 'Runtime economy test must create every schema');

$now = '2026-07-19 12:00:00.000000';
$mgwId = 'MGW-RUNTIMEECO001';
$database->execute(
    'INSERT INTO mgw_users (mgw_id, status, display_name, created_at_utc, updated_at_utc, last_seen_at_utc) '
    . 'VALUES (:id, :status, :name, :created, :updated, :seen)',
    ['id' => $mgwId, 'status' => 'active', 'name' => 'Runtime Economy', 'created' => $now, 'updated' => $now, 'seen' => $now]
);
$database->execute(
    'INSERT INTO mgw_identities (mgw_id, provider, provider_subject, linked_at_utc, last_authenticated_at_utc) '
    . 'VALUES (:id, :provider, :subject, :linked, :authenticated)',
    ['id' => $mgwId, 'provider' => 'telegram', 'subject' => '2001', 'linked' => $now, 'authenticated' => $now]
);
$database->execute(
    'INSERT INTO mgw_account_ownership (account_ref, mgw_id, legacy_user_id, ownership_status, source_type, source_ref, source_sha256, created_at_utc, verified_at_utc) '
    . 'VALUES (:account, :id, :legacy, :status, :type, :ref, :hash, :created, :verified)',
    [
        'account' => 'mgw:' . $mgwId,
        'id' => $mgwId,
        'legacy' => '2001',
        'status' => 'active',
        'type' => 'test',
        'ref' => 'test:2001',
        'hash' => str_repeat('b', 64),
        'created' => $now,
        'verified' => $now,
    ]
);

$source = [
    'users' => ['2001' => ['id' => '2001', 'balance_match' => 20, 'balance_gold' => 0]],
    'transactions' => [],
];
$storage = new RuntimeEconomyTestStorage($source);
$shadow = new LegacyEconomyShadowSyncService($storage, $database);
$shadow->run();
$ledger = new LedgerWriteService($database);
$integrity = new LedgerIntegrityVerifier($database);
(new LegacyOpeningBalanceImportService($database, $ledger, $integrity))->run();
$delta = new LegacyEconomyDeltaImportService($database, $ledger, $integrity);
$runtime = new LegacyEconomyRuntimeReconciliationService($database, $delta, $integrity);
$assertSame(true, $runtime->preview()['ready'], 'Opening economy state must reconcile');

$changed = $source;
$changed['users']['2001']['balance_match'] = 25;
$storage->replace($changed);
$shadow->run();
$pending = $runtime->preview();
$assertSame(false, $pending['ready'], 'Unapplied frozen balance delta must block runtime reconciliation');
$assertSame(1, $pending['planned_delta_count'], 'Pending report must count the unapplied delta');
$delta->run();
$assertSame(true, $runtime->preview()['ready'], 'Applied immutable delta must restore reconciliation');

$database->execute(
    "UPDATE mgw_balances SET reserved_amount = 1 WHERE account_ref = :account AND asset_code = 'match_coin'",
    ['account' => 'mgw:' . $mgwId]
);
$reserved = $runtime->preview();
$assertSame(false, $reserved['ready'], 'Reserved balance must block frozen reconciliation');

fwrite(STDOUT, "LegacyEconomyRuntimeReconciliationServiceTest passed: {$assertions} assertions.\n");
