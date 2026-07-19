<?php
declare(strict_types=1);

$root = dirname(__DIR__);
foreach ([
    '/storage/contracts/StorageTransactionInterface.php',
    '/storage/contracts/StorageAdapterInterface.php',
    '/database/DatabaseConnectionInterface.php',
    '/database/DatabaseConfig.php',
    '/database/PdoDatabaseConnection.php',
    '/database/PdoConnectionFactory.php',
    '/database/DatabaseMigrationInterface.php',
    '/database/MigrationRepository.php',
    '/database/MigrationRunner.php',
    '/storage/RuntimeStorageRouter.php',
    '/ledger/LedgerIntegrity.php',
    '/ledger/LedgerWriteService.php',
    '/ledger/LedgerIntegrityVerifier.php',
    '/ledger/LegacyEconomyShadowSyncService.php',
    '/ledger/LegacyOpeningBalanceImportService.php',
    '/ledger/LegacyEconomyDeltaImportService.php',
    '/ledger/LegacyEconomyRuntimeReconciliationService.php',
    '/ledger/RuntimeEconomySnapshotStorage.php',
    '/ledger/RuntimeEconomyBalanceBootstrapService.php',
    '/ledger/RuntimeEconomyRepository.php',
    '/ledger/LegacyFinancialStatusNormalizer.php',
    '/ledger/LegacyFinancialArchiveImportService.php',
    '/ledger/LegacyFinancialArchiveDeltaService.php',
    '/payments/RuntimePaymentSchemaInstaller.php',
    '/payments/RuntimePaymentRepository.php',
] as $file) require $root . $file;

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('RuntimePaymentRepositoryTest requires pdo_sqlite.');

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
$assertSame(7, (new MigrationRunner($database, $root . '/database/migrations'))->migrate(false)['executed_count'], 'All migrations must apply');

$legacyUserId = '5101';
$mgwId = 'MGW-PAYRUNTIME001';
$accountRef = 'mgw:' . $mgwId;
$now = '2026-07-19 17:00:00.000000';
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
    'users' => [$legacyUserId => [
        'id' => $legacyUserId,
        'telegram_id' => $legacyUserId,
        'balance_match' => 0,
        'balance_gold' => 100,
        'registered_at' => '2026-07-19T17:00:00+00:00',
        'last_seen_at' => '2026-07-19T17:00:00+00:00',
    ]],
    'payments' => [],
    'shop_orders' => [],
    'transactions' => [],
];
$initialStorage = new RuntimeEconomySnapshotStorage($initial);
(new LegacyEconomyShadowSyncService($initialStorage, $database))->run();
$ledger = new LedgerWriteService($database);
$integrity = new LedgerIntegrityVerifier($database);
(new LegacyOpeningBalanceImportService($database, $ledger, $integrity))->run();
$assertSame(true, (new LegacyFinancialArchiveImportService(
    $initialStorage,
    $database,
    new LegacyFinancialStatusNormalizer()
))->run()['ok'], 'Initial archive must be established');

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
    'feature_flags' => ['database_runtime' => [
        'enabled' => true,
        'modules' => [
            'accounts' => true,
            'realtime' => true,
            'economy' => true,
            'history' => true,
            'payments' => true,
        ],
    ]],
];
$router = new RuntimeStorageRouter($config);
$schema = new RuntimePaymentSchemaInstaller($database);
$install = $schema->install();
$assertSame(true, $install['ok'], 'Payment schema must install');
$assertSame(17, $install['verification']['present_column_count'], 'Every payment column must exist');
$assertSame(true, $schema->install()['idempotent'], 'Repeated schema install must be a no-op');

$repository = new RuntimePaymentRepository($config, $router, $initialStorage, $database);
$assertSame(true, $repository->bootstrapCurrentJson()['ok'], 'Empty payment baseline must activate');
$assertSame([], $repository->paymentRecords(), 'Empty payment mirror must read empty');

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
$draftRepository = new RuntimePaymentRepository(
    $config,
    $router,
    new RuntimeEconomySnapshotStorage($draft),
    $database
);
$draftSync = $draftRepository->synchronizeCurrentJson();
$assertSame(1, $draftSync['payments']['inserted_count'], 'Draft must create one mirror row');
$assertSame(0, $draftSync['economy']['applied_delta_count'], 'Draft must not change balance');
$assertSame(true, $draftRepository->auditParity($draft)['ok'], 'Draft must finish in parity');

$paid = $draft;
$paid['users'][$legacyUserId]['balance_gold'] = 125;
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
$paidRepository = new RuntimePaymentRepository(
    $config,
    $router,
    new RuntimeEconomySnapshotStorage($paid),
    $database
);
$paidSync = $paidRepository->synchronizeCurrentJson();
$assertSame(1, $paidSync['payments']['updated_count'], 'Paid transition must update the mirror');
$assertSame(1, $paidSync['economy']['applied_delta_count'], 'Paid transition must create one ledger delta');
$assertSame(true, $paidRepository->auditParity($paid)['ok'], 'Paid transition must finish in parity');
$records = $paidRepository->paymentRecords();
$assertSame(1, count($records), 'DB read must return one payment');
$assertSame(
    LedgerIntegrity::canonicalJson($paid['payments'][0]),
    LedgerIntegrity::canonicalJson($records[0]),
    'DB read must preserve the exact payment payload'
);
$repeat = $paidRepository->synchronizeCurrentJson();
$assertSame(0, $repeat['payments']['updated_count'], 'Repeat sync must not rewrite payment');
$assertSame(1, $repeat['payments']['unchanged_count'], 'Repeat sync must report payment unchanged');

$drifted = $paid;
$drifted['payments'][0]['status'] = 'rejected';
$driftAudit = $paidRepository->auditParity($drifted);
$assertSame(false, $driftAudit['ok'], 'Audit must detect payment drift');
$assertSame(1, $driftAudit['mismatch_count'], 'Payment drift must count one mismatch');

$disabledConfig = $config;
$disabledConfig['feature_flags']['database_runtime']['modules']['payments'] = false;
$disabled = new RuntimePaymentRepository(
    $disabledConfig,
    new RuntimeStorageRouter($disabledConfig),
    new RuntimeEconomySnapshotStorage($paid),
    $database
);
$assertThrows(
    static fn() => $disabled->auditParity($paid),
    'requires accounts, economy, history and payments routing',
    'Disabled payment route must reject DB runtime access'
);

fwrite(STDOUT, "RuntimePaymentRepositoryTest passed: {$assertions} assertions.\n");
