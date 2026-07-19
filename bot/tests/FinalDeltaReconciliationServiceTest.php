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
require $root . '/ledger/LegacyFinancialStatusNormalizer.php';
require $root . '/ledger/LegacyFinancialArchiveImportService.php';
require $root . '/ledger/LegacyFinancialArchiveDeltaService.php';
require $root . '/ledger/LegacyEconomyDeltaImportService.php';
require $root . '/ledger/LegacyEconomyRuntimeReconciliationService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('FinalDeltaReconciliationServiceTest requires pdo_sqlite.');
}

final class FinalDeltaTestStorage implements StorageAdapterInterface
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
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $root . '/database/migrations');
$assertSame(7, $runner->migrate(false)['executed_count'], 'Final delta test must create every schema');

$now = '2026-07-19 12:00:00.000000';
$mgwId = 'MGW-FINALDELTA001';
$database->execute(
    'INSERT INTO mgw_users (mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc) '
    . 'VALUES (:mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at)',
    [
        'mgw_id' => $mgwId,
        'status' => 'active',
        'display_name' => 'Final Delta',
        'created_at' => $now,
        'updated_at' => $now,
        'last_seen_at' => $now,
    ]
);
$database->execute(
    'INSERT INTO mgw_identities (mgw_id, provider, provider_subject, linked_at_utc, last_authenticated_at_utc) '
    . 'VALUES (:mgw_id, :provider, :subject, :linked_at, :authenticated_at)',
    [
        'mgw_id' => $mgwId,
        'provider' => 'telegram',
        'subject' => '1001',
        'linked_at' => $now,
        'authenticated_at' => $now,
    ]
);
$database->execute(
    'INSERT INTO mgw_account_ownership (account_ref, mgw_id, legacy_user_id, ownership_status, source_type, source_ref, source_sha256, created_at_utc, verified_at_utc) '
    . 'VALUES (:account_ref, :mgw_id, :legacy_user_id, :status, :source_type, :source_ref, :source_sha256, :created_at, :verified_at)',
    [
        'account_ref' => 'mgw:' . $mgwId,
        'mgw_id' => $mgwId,
        'legacy_user_id' => '1001',
        'status' => 'active',
        'source_type' => 'test',
        'source_ref' => 'test:1001',
        'source_sha256' => str_repeat('a', 64),
        'created_at' => $now,
        'verified_at' => $now,
    ]
);

$source = [
    'users' => [
        '1001' => [
            'id' => '1001',
            'telegram_id' => '1001',
            'balance_match' => 30,
            'balance_gold' => 0,
            'registered_at' => '2026-07-18T10:00:00+00:00',
        ],
    ],
    'transactions' => [
        [
            'id' => 'tx-welcome',
            'type' => 'balance_change',
            'category' => 'welcome_bonus',
            'user_id' => '1001',
            'room' => 'match',
            'amount' => 30,
            'created_at' => '2026-07-18T10:00:00+00:00',
        ],
    ],
    'payments' => [],
    'shop_orders' => [],
];
$storage = new FinalDeltaTestStorage($source);
$ledger = new LedgerWriteService($database);
$integrity = new LedgerIntegrityVerifier($database);
$economyShadow = new LegacyEconomyShadowSyncService($storage, $database);
$economyShadow->run();
$opening = new LegacyOpeningBalanceImportService($database, $ledger, $integrity);
$opening->run();
$assertSame(30, (int)$database->fetchValue(
    "SELECT available_amount FROM mgw_balances WHERE account_ref = :account_ref AND asset_code = 'match_coin'",
    ['account_ref' => 'mgw:' . $mgwId]
), 'Opening import must establish the original Match balance');

$archiveImport = new LegacyFinancialArchiveImportService(
    $storage,
    $database,
    new LegacyFinancialStatusNormalizer()
);
$archiveImport->run();
$archiveDelta = new LegacyFinancialArchiveDeltaService($database, $archiveImport);

$changed = $source;
$changed['users']['1001']['balance_match'] = 45;
$changed['transactions'][] = [
    'id' => 'tx-payment-apply',
    'type' => 'balance_change',
    'category' => 'payment_apply',
    'payment_id' => 'pay-final-delta-1',
    'user_id' => '1001',
    'room' => 'match',
    'amount' => 15,
    'balance_before' => 30,
    'balance_after' => 45,
    'created_at' => '2026-07-19T11:30:00+00:00',
];
$changed['payments'][] = [
    'id' => 'pay-final-delta-1',
    'user_id' => '1001',
    'provider' => 'manual_test',
    'status' => 'paid',
    'room' => 'match',
    'coins' => 15,
    'price' => 10,
    'currency' => 'RUB',
    'balance_applied' => true,
    'created_at' => '2026-07-19T11:25:00+00:00',
    'updated_at' => '2026-07-19T11:30:00+00:00',
    'applied_at' => '2026-07-19T11:30:00+00:00',
];
$storage->replace($changed);
$economyShadow->run();

$archivePreview = $archiveDelta->preview();
$assertSame(true, $archivePreview['ready'], 'Append-only archive delta must be ready');
$assertSame(true, $archivePreview['requires_metadata_advance'], 'Completed archive metadata must advance for appended records');
$assertSame(2, $archivePreview['planned_create_total'], 'One payment and one related transaction must be planned');
$archiveRun = $archiveDelta->run();
$assertSame(true, $archiveRun['metadata_advanced'], 'Archive delta must record metadata advancement');
$assertSame(1, $archiveRun['created_counts']['payments'], 'Archive delta must add the appended payment');
$assertSame(1, $archiveRun['created_counts']['transactions'], 'Archive delta must add the appended transaction');

$economyDelta = new LegacyEconomyDeltaImportService($database, $ledger, $integrity);
$economyPreview = $economyDelta->preview();
$assertSame(true, $economyPreview['ready'], 'Economy delta plan must pass ownership and integrity preconditions');
$assertSame(1, $economyPreview['planned_delta_count'], 'Only Match balance must require a delta');
$economyRun = $economyDelta->run();
$assertSame(1, $economyRun['applied_delta_count'], 'Economy delta must append one immutable ledger operation');
$assertSame(15, $economyRun['credited_total'], 'Economy delta must credit the exact frozen difference');
$assertSame(45, (int)$database->fetchValue(
    "SELECT available_amount FROM mgw_balances WHERE account_ref = :account_ref AND asset_code = 'match_coin'",
    ['account_ref' => 'mgw:' . $mgwId]
), 'Database balance must converge to frozen JSON');
$assertSame(1, (int)$database->fetchValue(
    "SELECT COUNT(*) FROM mgw_ledger_entries WHERE category = 'legacy_cutover_delta'"
), 'Final delta must remain a separate immutable ledger entry');

$runtime = new LegacyEconomyRuntimeReconciliationService($database, $economyDelta, $integrity);
$runtimeReport = $runtime->preview();
$assertSame(true, $runtimeReport['ready'], 'Runtime economy reconciliation must accept managed post-opening deltas');
$assertSame(0, $runtimeReport['planned_delta_count'], 'No economy delta may remain after convergence');
$assertSame(['match_coin' => 45, 'gold_coin' => 0], $runtimeReport['database_totals'], 'Runtime totals must equal the frozen source');

$archiveRepeat = $archiveDelta->run();
$assertSame(false, $archiveRepeat['metadata_advanced'], 'Repeated archive delta must not advance metadata again');
$assertSame(0, array_sum($archiveRepeat['created_counts']), 'Repeated archive delta must not duplicate rows');
$economyRepeat = $economyDelta->run();
$assertSame(0, $economyRepeat['applied_delta_count'], 'Repeated economy delta must not create another ledger entry');
$assertSame(0, $economyRepeat['planned_delta_count'], 'Repeated economy delta must already be converged');

$tampered = $changed;
$tampered['payments'][0]['coins'] = 16;
$storage->replace($tampered);
$tamperedPreview = $archiveDelta->preview();
$assertSame(false, $tamperedPreview['ready'], 'Changing an already archived source record must fail closed');
$assertTrue($tamperedPreview['conflict_total'] > 0, 'Changed archive source must be reported as a conflict');

fwrite(STDOUT, "FinalDeltaReconciliationServiceTest passed: {$assertions} assertions.\n");
