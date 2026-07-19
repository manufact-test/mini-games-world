<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/PdoConnectionFactory.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/database/MigrationRepository.php';
require $root . '/database/MigrationRunner.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/ledger/LedgerIntegrityVerifier.php';
require $root . '/ledger/LegacyEconomyShadowSyncService.php';
require $root . '/ledger/LegacyOpeningBalanceImportService.php';
require $root . '/ledger/LegacyEconomyDeltaImportService.php';
require $root . '/ledger/LegacyEconomyRuntimeReconciliationService.php';
require $root . '/ledger/RuntimeEconomySnapshotStorage.php';
require $root . '/ledger/RuntimeEconomyRepository.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('RuntimeEconomyRepositoryTest requires pdo_sqlite.');
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($contains))) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $root . '/database/migrations');
$assertSame(7, $runner->migrate(false)['executed_count'], 'Economy runtime test must apply every migration');

$now = '2026-07-19 14:00:00.000000';
$legacyUserId = '3001';
$mgwId = 'MGW-ECONOMYRT0001';
$accountRef = 'mgw:' . $mgwId;
$database->execute(
    'INSERT INTO mgw_users (mgw_id, status, display_name, created_at_utc, updated_at_utc, last_seen_at_utc) '
    . 'VALUES (:id, :status, :name, :created, :updated, :seen)',
    ['id' => $mgwId, 'status' => 'active', 'name' => 'Economy Runtime', 'created' => $now, 'updated' => $now, 'seen' => $now]
);
$database->execute(
    'INSERT INTO mgw_identities (mgw_id, provider, provider_subject, linked_at_utc, last_authenticated_at_utc) '
    . 'VALUES (:id, :provider, :subject, :linked, :authenticated)',
    ['id' => $mgwId, 'provider' => 'telegram', 'subject' => $legacyUserId, 'linked' => $now, 'authenticated' => $now]
);
$database->execute(
    'INSERT INTO mgw_account_ownership (account_ref, mgw_id, legacy_user_id, ownership_status, source_type, source_ref, source_sha256, created_at_utc, verified_at_utc) '
    . 'VALUES (:account, :id, :legacy, :status, :type, :ref, :hash, :created, :verified)',
    [
        'account' => $accountRef,
        'id' => $mgwId,
        'legacy' => $legacyUserId,
        'status' => 'active',
        'type' => 'test',
        'ref' => 'test:' . $legacyUserId,
        'hash' => str_repeat('c', 64),
        'created' => $now,
        'verified' => $now,
    ]
);

$initial = [
    'users' => [
        $legacyUserId => [
            'id' => $legacyUserId,
            'telegram_id' => $legacyUserId,
            'balance_match' => 20,
            'balance_gold' => 0,
            'registered_at' => '2026-07-19T14:00:00+00:00',
            'last_seen_at' => '2026-07-19T14:00:00+00:00',
        ],
    ],
    'transactions' => [],
];
$initialStorage = new RuntimeEconomySnapshotStorage($initial);
$shadow = new LegacyEconomyShadowSyncService($initialStorage, $database);
$shadow->run();
$ledger = new LedgerWriteService($database);
$integrity = new LedgerIntegrityVerifier($database);
(new LegacyOpeningBalanceImportService($database, $ledger, $integrity))->run();

$config = [
    'environment' => 'staging',
    'storage_driver' => 'json',
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'mgw_test',
        'user' => 'mgw_test',
        'password' => 'test-password',
        'charset' => 'utf8mb4',
    ],
    'feature_flags' => [
        'database_runtime' => [
            'enabled' => true,
            'modules' => [
                'accounts' => true,
                'economy' => true,
            ],
        ],
    ],
];
$router = new RuntimeStorageRouter($config);
$repository = new RuntimeEconomyRepository($config, $router, $database);
$assertSame(true, $repository->auditParity($initial)['ok'], 'Imported opening balances must start in parity');

$credited = $initial;
$credited['users'][$legacyUserId]['balance_match'] = 25;
$first = $repository->synchronize($credited);
$assertSame(true, $first['ok'], 'Economy synchronization must succeed');
$assertSame(1, $first['delta']['applied_delta_count'], 'Five-coin change must create one immutable delta');
$assertSame(5, $first['delta']['credited_total'], 'Credit total must preserve the JSON difference');
$assertSame(25, (int)$database->fetchValue(
    "SELECT available_amount FROM mgw_balances WHERE account_ref = :account AND asset_code = 'match_coin'",
    ['account' => $accountRef]
), 'Database balance must match the current JSON balance');
$assertSame(true, $repository->auditParity($credited)['ok'], 'Read-only audit must pass after synchronization');

$repeat = $repository->synchronize($credited);
$assertSame(0, $repeat['delta']['applied_delta_count'], 'Repeated synchronization must not duplicate ledger entries');

$debited = $credited;
$debited['users'][$legacyUserId]['balance_match'] = 22;
$second = $repository->synchronize($debited);
$assertSame(1, $second['delta']['applied_delta_count'], 'Balance reduction must create one immutable delta');
$assertSame(3, $second['delta']['debited_total'], 'Debit total must preserve the JSON difference');
$assertSame(22, (int)$database->fetchValue(
    "SELECT available_amount FROM mgw_balances WHERE account_ref = :account AND asset_code = 'match_coin'",
    ['account' => $accountRef]
), 'Round-trip balance must finish at the latest JSON value');
$assertSame(3, (int)$database->fetchValue(
    "SELECT COUNT(*) FROM mgw_ledger_entries WHERE account_ref = :account AND asset_code = 'match_coin'",
    ['account' => $accountRef]
), 'Opening, credit and debit must remain separate immutable ledger entries');

$drifted = $debited;
$drifted['users'][$legacyUserId]['balance_match'] = 23;
$driftAudit = $repository->auditParity($drifted);
$assertSame(false, $driftAudit['ok'], 'Read-only audit must detect JSON state not yet synchronized');
$assertSame(1, $driftAudit['shadow_delta_count'], 'Audit must count the changed balance shadow row');

$disabledConfig = $config;
$disabledConfig['feature_flags']['database_runtime']['modules']['economy'] = false;
$disabled = new RuntimeEconomyRepository(
    $disabledConfig,
    new RuntimeStorageRouter($disabledConfig),
    $database
);
$assertThrows(
    static fn() => $disabled->auditParity($debited),
    'requires accounts and economy routing',
    'Disabled economy route must reject DB runtime access'
);

fwrite(STDOUT, "RuntimeEconomyRepositoryTest passed: {$assertions} assertions.\n");
