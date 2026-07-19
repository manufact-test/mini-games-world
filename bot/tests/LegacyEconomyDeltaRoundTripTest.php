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

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('LegacyEconomyDeltaRoundTripTest requires pdo_sqlite.');
}

final class EconomyRoundTripStorage implements StorageAdapterInterface
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
$assertSame(7, $runner->migrate(false)['executed_count'], 'Round-trip test must create every schema');

$now = '2026-07-19 12:00:00.000000';
$mgwId = 'MGW-DELTAROUND001';
$database->execute(
    'INSERT INTO mgw_users (mgw_id, status, display_name, created_at_utc, updated_at_utc, last_seen_at_utc) '
    . 'VALUES (:id, :status, :name, :created, :updated, :seen)',
    ['id' => $mgwId, 'status' => 'active', 'name' => 'Delta Round Trip', 'created' => $now, 'updated' => $now, 'seen' => $now]
);
$database->execute(
    'INSERT INTO mgw_identities (mgw_id, provider, provider_subject, linked_at_utc, last_authenticated_at_utc) '
    . 'VALUES (:id, :provider, :subject, :linked, :authenticated)',
    ['id' => $mgwId, 'provider' => 'telegram', 'subject' => '3001', 'linked' => $now, 'authenticated' => $now]
);
$database->execute(
    'INSERT INTO mgw_account_ownership (account_ref, mgw_id, legacy_user_id, ownership_status, source_type, source_ref, source_sha256, created_at_utc, verified_at_utc) '
    . 'VALUES (:account, :id, :legacy, :status, :type, :ref, :hash, :created, :verified)',
    [
        'account' => 'mgw:' . $mgwId,
        'id' => $mgwId,
        'legacy' => '3001',
        'status' => 'active',
        'type' => 'test',
        'ref' => 'test:3001',
        'hash' => str_repeat('c', 64),
        'created' => $now,
        'verified' => $now,
    ]
);

$state30 = ['users' => ['3001' => ['id' => '3001', 'balance_match' => 30, 'balance_gold' => 0]], 'transactions' => []];
$state45 = ['users' => ['3001' => ['id' => '3001', 'balance_match' => 45, 'balance_gold' => 0]], 'transactions' => []];
$storage = new EconomyRoundTripStorage($state30);
$shadow = new LegacyEconomyShadowSyncService($storage, $database);
$shadow->run();
$ledger = new LedgerWriteService($database);
$integrity = new LedgerIntegrityVerifier($database);
(new LegacyOpeningBalanceImportService($database, $ledger, $integrity))->run();
$delta = new LegacyEconomyDeltaImportService($database, $ledger, $integrity);

$storage->replace($state45);
$shadow->run();
$assertSame(1, $delta->run()['applied_delta_count'], 'First 30 to 45 transition must apply');
$storage->replace($state30);
$shadow->run();
$assertSame(1, $delta->run()['applied_delta_count'], '45 to 30 transition must apply');
$storage->replace($state45);
$shadow->run();
$assertSame(1, $delta->run()['applied_delta_count'], 'Returning to the previous 45 snapshot must use a new balance-transition key');
$assertSame(45, (int)$database->fetchValue(
    "SELECT available_amount FROM mgw_balances WHERE account_ref = :account AND asset_code = 'match_coin'",
    ['account' => 'mgw:' . $mgwId]
), 'Round trip must finish at the repeated frozen balance');
$assertSame(3, (int)$database->fetchValue(
    "SELECT COUNT(*) FROM mgw_ledger_entries WHERE category = 'legacy_cutover_delta'"
), 'Every distinct balance transition must remain in the immutable ledger');

fwrite(STDOUT, "LegacyEconomyDeltaRoundTripTest passed: {$assertions} assertions.\n");
