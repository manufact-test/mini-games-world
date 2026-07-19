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
require $root . '/payments/RuntimePaymentSchemaInstaller.php';
require $root . '/payments/RuntimePaymentRepository.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('RuntimePaymentRepositoryTest requires pdo_sqlite.');
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
$assertSame(7, $runner->migrate(false)['executed_count'], 'Payment runtime test must apply every managed migration');

$now = '2026-07-19 17:00:00.000000';
$legacyUserId = '5101';
$mgwId = 'MGW-PAYRUNTIME001';
$accountRef = 'mgw:' . $mgwId;
$database->execute(
    'INSERT INTO mgw_users (mgw_id, status, display_name, created_at_utc, updated_at_utc, last_seen_at_utc) '
    . 'VALUES (:id, :status, :name, :created, :updated, :seen)',
    ['id' => $mgwId, 'status' => 'active', 'name' => 'Payment Runtime', 'created' => $now, 'updated' => $now, 'seen' => $now]
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
        'ref' => 'payment-runtime:' . $legacyUserId,
        'hash' => str_repeat('f', 64),
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
            'registered_at' => '2026-07-19T17:00:00+00:00',
            'last_seen_at' => '2026-07-19T17:00:00+00:00',
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
                'payments' => true,
            ],
        ],
    ],
];
$router = new RuntimeStorageRouter($config);
$schema = new RuntimePaymentSchemaInstaller($database);
$install = $schema->install();
$assertSame(true, $install['ok'], 'Payment runtime schema must install');
$assertSame(17, $install['verification']['present_column_count'], 'Payment schema must expose every required column');
$assertSame(true, $schema->install()['idempotent'], 'Repeated payment schema installation must be a no-op');

$initialRepository = new RuntimePaymentRepository($config, $router, $initialStorage, $database);
$initialBootstrap = $initialRepository->bootstrapCurrentJson();
$assertSame(true, $initialBootstrap['ok'], 'Empty payment runtime baseline must activate cleanly');
$assertSame(0, $initialBootstrap['payments']['source_payment_count'], 'Empty source must create no payment rows');
$assertSame(true, $initialRepository->auditParity($initial)['ok'], 'Empty payment mirror must start in parity');
$assertSame([], $initialRepository->paymentRecords(), 'Empty payment mirror must return no records');

$draft = $initial;
$draft['payments'][] = [
    'id' => 'pay_runtime_1',
    'user_id' => $legacyUserId,
    'username' => 'payment_runtime',
    'provider' => 'manual_test',
    'status' => 'draft',
    'room' => 'gold',
    'coins' => 25,
    'price' => 25,
    'amount_rub' => 25,
    'currency' => 'RUB',
    'rate' => 1,
    'balance_applied' => false,
    'created_at' => '2026-07-19T17:01:00+00:00',
    'updated_at' => '2026-07-19T17:01:00+00:00',
];
$draft['transactions'][] = [
    'id' => 'tx_payment_draft_1',
    'type' => 'payment_draft',
    'category' => 'payment_draft',
    'payment_id' => 'pay_runtime_1',
    'user_id' => $legacyUserId,
    'room' => 'gold',
    'amount' => 0,
    'coins' => 25,
    'amount_rub' => 25,
    'currency' => 'RUB',
    'created_at' => '2026-07-19T17:01:00+00:00',
];
$draftStorage = new RuntimeEconomySnapshotStorage($draft);
$draftRepository = new RuntimePaymentRepository($config, $router, $draftStorage, $database);
$draftSync = $draftRepository->synchronizeCurrentJson();
$assertSame(true, $draftSync['ok'], 'Draft payment synchronization must succeed');
$assertSame(1, $draftSync['payments']['inserted_count'], 'New draft must create one payment mirror row');
$assertSame(0, $draftSync['economy']['applied_delta_count'], 'Draft payment must not change ledger balance');
$assertSame('draft', (string)$database->fetchValue(
    'SELECT status_raw FROM mgw_runtime_payments WHERE payment_ref = :payment_ref',
    ['payment_ref' => 'pay_runtime_1']
), 'Payment mirror must preserve draft status');
$assertSame(true, $draftRepository->auditParity($draft)['ok'], 'Draft payment must finish in exact parity');

$paid = $draft;
$paid['users'][$legacyUserId]['balance_gold'] = 125;
$paid['users'][$legacyUserId]['gold_deposited_total'] = 25;
$paid['payments'][0]['status'] = 'paid';
$paid['payments'][0]['balance_applied'] = true;
$paid['payments'][0]['paid_at'] = '2026-07-19T17:02:00+00:00';
$paid['payments'][0]['applied_at'] = '2026-07-19T17:02:00+00:00';
$paid['payments'][0]['updated_at'] = '2026-07-19T17:02:00+00:00';
$paid['transactions'][] = [
    'id' => 'tx_payment_apply_1',
    'type' => 'balance_change',
    'category' => 'payment_apply',
    'payment_id' => 'pay_runtime_1',
    'user_id' => $legacyUserId,
    'room' => 'gold',
    'amount' => 25,
    'amount_rub' => 25,
    'currency' => 'RUB',
    'balance_before' => 100,
    'balance_after' => 125,
    'created_at' => '2026-07-19T17:02:00+00:00',
];
$paidStorage = new RuntimeEconomySnapshotStorage($paid);
$paidRepository = new RuntimePaymentRepository($config, $router, $paidStorage, $database);
$paidSync = $paidRepository->synchronizeCurrentJson();
$assertSame(1, $paidSync['payments']['updated_count'], 'Paid status must update the payment mirror');
$assertSame(1, $paidSync['economy']['applied_delta_count'], 'Paid balance credit must create one immutable ledger delta');
$assertSame('paid', (string)$database->fetchValue(
    'SELECT status_raw FROM mgw_runtime_payments WHERE payment_ref = :payment_ref',
    ['payment_ref' => 'pay_runtime_1']
), 'Payment mirror must preserve paid status');
$assertSame(true, $paidRepository->auditParity($paid)['ok'], 'Paid payment must remain in parity');
$records = $paidRepository->paymentRecords();
$assertSame(1, count($records), 'Payment DB read must return one verified record');
$assertSame($paid['payments'][0], $records[0], 'Payment DB read must preserve the exact JSON payload');

$second = $paid;
$second['payments'][] = [
    'id' => 'pay_runtime_2',
    'user_id' => $legacyUserId,
    'provider' => 'manual_test',
    'status' => 'draft',
    'room' => 'match',
    'coins' => 20,
    'price' => 10,
    'amount_rub' => 10,
    'currency' => 'RUB',
    'rate' => 2,
    'balance_applied' => false,
    'created_at' => '2026-07-19T17:03:00+00:00',
    'updated_at' => '2026-07-19T17:03:00+00:00',
];
$second['transactions'][] = [
    'id' => 'tx_payment_draft_2',
    'type' => 'payment_draft',
    'category' => 'payment_draft',
    'payment_id' => 'pay_runtime_2',
    'user_id' => $legacyUserId,
    'room' => 'match',
    'amount' => 0,
    'coins' => 20,
    'amount_rub' => 10,
    'currency' => 'RUB',
    'created_at' => '2026-07-19T17:03:00+00:00',
];
$secondStorage = new RuntimeEconomySnapshotStorage($second);
$secondRepository = new RuntimePaymentRepository($config, $router, $secondStorage, $database);
$secondSync = $secondRepository->synchronizeCurrentJson();
$assertSame(1, $secondSync['payments']['inserted_count'], 'Second draft must create one additional row');
$secondRecords = $secondRepository->paymentRecords();
$assertSame('pay_runtime_1', (string)$secondRecords[0]['id'], 'Payment DB read must preserve first source position');
$assertSame('pay_runtime_2', (string)$secondRecords[1]['id'], 'Payment DB read must preserve second source position');
$repeat = $secondRepository->synchronizeCurrentJson();
$assertSame(0, $repeat['payments']['updated_count'], 'Repeated payment synchronization must not rewrite unchanged rows');
$assertSame(2, $repeat['payments']['unchanged_count'], 'Repeated payment synchronization must report both rows unchanged');

$drifted = $second;
$drifted['payments'][0]['status'] = 'rejected';
$driftAudit = $secondRepository->auditParity($drifted);
$assertSame(false, $driftAudit['ok'], 'Read-only audit must detect unsynchronized payment status');
$assertSame(1, $driftAudit['mismatch_count'], 'Unsynchronized payment status must count one mismatch');

$disabledConfig = $config;
$disabledConfig['feature_flags']['database_runtime']['modules']['payments'] = false;
$disabled = new RuntimePaymentRepository(
    $disabledConfig,
    new RuntimeStorageRouter($disabledConfig),
    $secondStorage,
    $database
);
$assertThrows(
    static fn() => $disabled->auditParity($second),
    'requires accounts, economy, history and payments routing',
    'Disabled payment route must reject DB runtime access'
);

fwrite(STDOUT, "RuntimePaymentRepositoryTest passed: {$assertions} assertions.\n");
