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
require $root . '/ledger/RuntimeEconomyBalanceBootstrapService.php';
require $root . '/ledger/RuntimeEconomyRepository.php';
require $root . '/ledger/LegacyFinancialStatusNormalizer.php';
require $root . '/ledger/LegacyFinancialArchiveImportService.php';
require $root . '/ledger/LegacyFinancialArchiveDeltaService.php';
require $root . '/shop/RuntimeShopSchemaInstaller.php';
require $root . '/shop/RuntimeShopRepository.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('RuntimeShopRepositoryTest requires pdo_sqlite.');
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
$assertSame(7, $runner->migrate(false)['executed_count'], 'Shop runtime test must preserve the core migration baseline');
$schemaInstaller = new RuntimeShopSchemaInstaller($database);
$schemaInstall = $schemaInstaller->install();
$assertSame(true, $schemaInstall['ok'], 'Shop runtime schema installer must complete');
$assertSame(true, $schemaInstall['verification']['ok'], 'Shop runtime schema must verify after install');
$assertSame(true, $schemaInstaller->install()['idempotent'], 'Repeated shop runtime schema install must be a no-op');

$now = '2026-07-19 16:30:00.000000';
$legacyUserId = '4101';
$mgwId = 'MGW-SHOPRUNTIME01';
$accountRef = 'mgw:' . $mgwId;
$database->execute(
    'INSERT INTO mgw_users (mgw_id, status, display_name, created_at_utc, updated_at_utc, last_seen_at_utc) '
    . 'VALUES (:id, :status, :name, :created, :updated, :seen)',
    ['id' => $mgwId, 'status' => 'active', 'name' => 'Shop Runtime', 'created' => $now, 'updated' => $now, 'seen' => $now]
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
        'ref' => 'shop-runtime:' . $legacyUserId,
        'hash' => str_repeat('e', 64),
        'created' => $now,
        'verified' => $now,
    ]
);

$initial = [
    'users' => [
        $legacyUserId => [
            'id' => $legacyUserId,
            'telegram_id' => $legacyUserId,
            'balance_match' => 0,
            'balance_gold' => 100,
            'registered_at' => '2026-07-19T16:30:00+00:00',
            'last_seen_at' => '2026-07-19T16:30:00+00:00',
        ],
    ],
    'payments' => [],
    'shop_orders' => [],
    'transactions' => [],
];
$initialStorage = new RuntimeEconomySnapshotStorage($initial);
(new LegacyEconomyShadowSyncService($initialStorage, $database))->run();
$ledger = new LedgerWriteService($database);
$integrity = new LedgerIntegrityVerifier($database);
(new LegacyOpeningBalanceImportService($database, $ledger, $integrity))->run();
$archive = new LegacyFinancialArchiveImportService(
    $initialStorage,
    $database,
    new LegacyFinancialStatusNormalizer()
);
$assertSame(true, $archive->run()['ok'], 'Initial immutable financial archive must be established');

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
                'realtime' => true,
                'economy' => true,
                'history' => true,
                'shop' => true,
            ],
        ],
    ],
];
$router = new RuntimeStorageRouter($config);
$initialRepository = new RuntimeShopRepository($config, $router, $initialStorage, $database);
$initialBootstrap = $initialRepository->bootstrapCurrentJson();
$assertSame(true, $initialBootstrap['ok'], 'Empty shop runtime baseline must activate cleanly');
$assertSame(0, $initialBootstrap['orders']['source_order_count'], 'Empty source must create no live shop rows');
$assertSame(true, $initialRepository->auditParity($initial)['ok'], 'Empty shop mirror must start in parity');

$pending = $initial;
$pending['users'][$legacyUserId]['balance_gold'] = 75;
$pending['shop_orders'][] = [
    'id' => 'shop_runtime_1',
    'client_request_id' => 'request_000001',
    'user_id' => $legacyUserId,
    'username' => 'shop_runtime',
    'item_id' => 'gift_card',
    'denomination_id' => 'gift_25',
    'provider' => 'Test Provider',
    'country_code' => 'PL',
    'amount' => 25,
    'gold_cost' => 25,
    'status' => 'pending',
    'refund_done' => false,
    'created_at' => '2026-07-19T16:31:00+00:00',
];
$pending['transactions'][] = [
    'id' => 'tx_shop_runtime_1',
    'type' => 'balance_change',
    'category' => 'shop_order',
    'order_id' => 'shop_runtime_1',
    'client_request_id' => 'request_000001',
    'user_id' => $legacyUserId,
    'room' => 'gold',
    'amount' => -25,
    'balance_after' => 75,
    'created_at' => '2026-07-19T16:31:00+00:00',
];
$pendingStorage = new RuntimeEconomySnapshotStorage($pending);
$pendingRepository = new RuntimeShopRepository($config, $router, $pendingStorage, $database);
$pendingSync = $pendingRepository->synchronizeCurrentJson();
$assertSame(true, $pendingSync['ok'], 'Pending shop order synchronization must succeed');
$assertSame(1, $pendingSync['orders']['inserted_count'], 'New shop order must create one live mirror row');
$assertSame(1, $pendingSync['economy']['applied_delta_count'], 'Shop debit must be represented by one immutable ledger delta');
$assertSame('pending', (string)$database->fetchValue(
    'SELECT status_raw FROM mgw_runtime_shop_orders WHERE order_ref = :order_ref',
    ['order_ref' => 'shop_runtime_1']
), 'Live mirror must preserve pending status');
$assertSame(true, $pendingRepository->auditParity($pending)['ok'], 'Pending order must finish in exact parity');

$completed = $pending;
$completed['shop_orders'][0]['status'] = 'completed';
$completed['shop_orders'][0]['updated_at'] = '2026-07-19T16:32:00+00:00';
$completed['shop_orders'][0]['completed_at'] = '2026-07-19T16:32:00+00:00';
$completedStorage = new RuntimeEconomySnapshotStorage($completed);
$completedRepository = new RuntimeShopRepository($config, $router, $completedStorage, $database);
$completedSync = $completedRepository->synchronizeCurrentJson();
$assertSame(1, $completedSync['orders']['updated_count'], 'Mutable order status must update the live mirror');
$assertSame(0, $completedSync['economy']['applied_delta_count'], 'Status-only update must not create a balance delta');
$assertSame('completed', (string)$database->fetchValue(
    'SELECT status_raw FROM mgw_runtime_shop_orders WHERE order_ref = :order_ref',
    ['order_ref' => 'shop_runtime_1']
), 'Live mirror must preserve completed status');
$assertSame(true, $completedRepository->auditParity($completed)['ok'], 'Completed order must remain in parity');

$repeat = $completedRepository->synchronizeCurrentJson();
$assertSame(0, $repeat['orders']['updated_count'], 'Repeated synchronization must not rewrite unchanged orders');
$assertSame(1, $repeat['orders']['unchanged_count'], 'Repeated synchronization must report the unchanged order');

$drifted = $completed;
$drifted['shop_orders'][0]['status'] = 'rejected';
$driftAudit = $completedRepository->auditParity($drifted);
$assertSame(false, $driftAudit['ok'], 'Read-only audit must detect an unsynchronized status change');
$assertSame(1, $driftAudit['mismatch_count'], 'Unsynchronized status change must count one mismatch');

$disabledConfig = $config;
$disabledConfig['feature_flags']['database_runtime']['modules']['shop'] = false;
$disabled = new RuntimeShopRepository(
    $disabledConfig,
    new RuntimeStorageRouter($disabledConfig),
    $completedStorage,
    $database
);
$assertThrows(
    static fn() => $disabled->auditParity($completed),
    'requires accounts, economy, history and shop routing',
    'Disabled shop route must reject DB runtime access'
);

fwrite(STDOUT, "RuntimeShopRepositoryTest passed: {$assertions} assertions.\n");
