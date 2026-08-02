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
require $root . '/realtime/RealtimeDatabaseStore.php';
require $root . '/realtime/RuntimeRealtimeRepository.php';
require $root . '/realtime/LegacyRealtimeShadowSyncService.php';
require $root . '/realtime/RealtimeRuntimeBridge.php';
require $root . '/notifications/RuntimeNotificationRepository.php';
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
require $root . '/services/UserService.php';
require $root . '/services/NotificationService.php';
require $root . '/services/WeeklyMatchEconomyService.php';
require $root . '/weekly/RuntimeWeeklyBonusSchemaInstaller.php';
require $root . '/weekly/RuntimeWeeklyBonusRepository.php';
require $root . '/weekly/WeeklyBonusRuntimeBridge.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('WeeklyBonusRuntimeBridgeTest requires pdo_sqlite.');
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
$assertSame(7, $runner->migrate(false)['executed_count'], 'Weekly bridge test must apply every managed migration');

$legacyUserId = '6201';
$mgwId = 'MGW-WEEKBRIDGE01';
$accountRef = 'mgw:' . $mgwId;
$now = '2026-07-19 17:30:00.000000';
$database->execute(
    'INSERT INTO mgw_users (mgw_id, status, display_name, created_at_utc, updated_at_utc, last_seen_at_utc) '
    . 'VALUES (:id, :status, :name, :created, :updated, :seen)',
    ['id' => $mgwId, 'status' => 'active', 'name' => 'Weekly Bridge', 'created' => $now, 'updated' => $now, 'seen' => $now]
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
        'ref' => 'weekly-bridge:' . $legacyUserId,
        'hash' => str_repeat('b', 64),
        'created' => $now,
        'verified' => $now,
    ]
);

$snapshot = [
    'users' => [
        $legacyUserId => [
            'id' => $legacyUserId,
            'telegram_id' => $legacyUserId,
            'balance_match' => 50,
            'balance_gold' => 0,
            'weekly_match_welcome_grant_done' => true,
            'weekly_match_welcome_grant_at' => '2026-07-13T09:00:00+00:00',
            'weekly_match_welcome_grant_amount' => 50,
            'weekly_match_first_grant_done' => true,
            'registered_at' => '2026-07-13T09:00:00+00:00',
            'last_seen_at' => '2026-07-19T17:30:00+00:00',
        ],
    ],
    'games' => [],
    'queue' => [],
    'invites' => [],
    'notifications' => [[
        'id' => 'weekly_bridge_notification_1',
        'user_id' => $legacyUserId,
        'type' => 'weekly_match_bonus',
        'title' => 'Еженедельный бонус',
        'message' => 'Начислено 50 коинов.',
        'event_key' => 'weekly_bridge_event_1',
        'created_at' => '2026-07-19T17:30:00+00:00',
        'read' => false,
    ]],
    'payments' => [],
    'shop_orders' => [],
    'transactions' => [[
        'id' => 'tx_weekly_bridge_1',
        'type' => 'balance_change',
        'category' => 'welcome_bonus',
        'user_id' => $legacyUserId,
        'room' => 'match',
        'amount' => 50,
        'balance_before' => 0,
        'balance_after' => 50,
        'created_at' => '2026-07-13T09:00:00+00:00',
    ]],
];
$storage = new RuntimeEconomySnapshotStorage($snapshot);
(new LegacyEconomyShadowSyncService($storage, $database))->run();
$ledger = new LedgerWriteService($database);
$integrity = new LedgerIntegrityVerifier($database);
(new LegacyOpeningBalanceImportService($database, $ledger, $integrity))->run();

$config = [
    'environment' => 'staging',
    'base_url' => 'https://seashell-okapi-889488.hostingersite.com',
    'storage_driver' => 'json',
    'weekly_match_timezone' => 'Europe/Moscow',
    'weekly_match_start_at' => '2026-07-13 12:00:00',
    'weekly_match_bonus_amount' => 50,
    'weekly_match_min_completed' => 3,
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
                'notifications' => true,
                'economy' => true,
                'history' => true,
                'weekly_bonus' => true,
            ],
        ],
    ],
];
$router = new RuntimeStorageRouter($config);
(new RuntimeWeeklyBonusSchemaInstaller($database))->install();
$repository = new RuntimeWeeklyBonusRepository($config, $router, $storage, $database);
$realtimeRepository = new RuntimeRealtimeRepository($config, $router, $database);
$realtimeBridge = new RealtimeRuntimeBridge($config, $router, $storage, $realtimeRepository);
$notificationRepository = new RuntimeNotificationRepository($config, $router, $database);

$bridge = new WeeklyBonusRuntimeBridge($config, $router, $repository);
$realtimeBridge->synchronizeCurrentJson();
$repository->synchronizeCurrentJson();
$notificationRepository->synchronizeAndList($snapshot, $legacyUserId);

$data = [
    'user' => ['id' => $legacyUserId],
    'weekly_match' => ['enabled' => false],
];
$normalized = $bridge->normalizeApiData($data, '');
$assertSame(true, $normalized['weekly_match']['enabled'], 'Weekly bridge must replace JSON response with verified DB status');
$assertSame(50, $normalized['weekly_match']['bonus_amount'], 'Weekly bridge must preserve configured bonus amount');
$assertSame(true, $bridge->shouldSynchronizeApiAction('anything'), 'Weekly bridge synchronization must not depend on a hidden action global');

$testWeeklyStatus = [
    'enabled' => true,
    'bonus_amount' => 50,
    'min_completed_games' => 3,
    'completed_games' => 0,
    'remaining_games' => 3,
    'source' => 'already_calculated_json_test_status',
];
foreach (['stg_test_player_a', 'stg_test_player_b'] as $testPlayerId) {
    $testData = [
        'user' => ['id' => $testPlayerId],
        'weekly_match' => $testWeeklyStatus,
    ];
    $testNormalized = $bridge->normalizeApiData($testData, 'bootstrap');
    $assertSame(
        $testWeeklyStatus,
        $testNormalized['weekly_match'],
        'Exact isolated staging test player must retain the already calculated JSON weekly status: ' . $testPlayerId
    );
}

$assertThrows(
    static fn() => $bridge->normalizeApiData([
        'user' => ['id' => 'stg_test_player_c'],
        'weekly_match' => $testWeeklyStatus,
    ], 'bootstrap'),
    'missing or ambiguous',
    'Unknown staging identity must not bypass the DB-primary weekly status'
);

$productionConfig = $config;
$productionConfig['environment'] = 'production';
$productionConfig['base_url'] = 'https://mini-games-world.com';
$productionBridge = new WeeklyBonusRuntimeBridge(
    $productionConfig,
    new RuntimeStorageRouter($productionConfig),
    $repository
);
$assertThrows(
    static fn() => $productionBridge->normalizeApiData([
        'user' => ['id' => 'stg_test_player_a'],
        'weekly_match' => $testWeeklyStatus,
    ], 'bootstrap'),
    'missing or ambiguous',
    'Production must never bypass DB-primary weekly status for a staging test identity'
);

$wrongHostConfig = $config;
$wrongHostConfig['base_url'] = 'https://staging.invalid.example';
$wrongHostBridge = new WeeklyBonusRuntimeBridge(
    $wrongHostConfig,
    new RuntimeStorageRouter($wrongHostConfig),
    $repository
);
$assertThrows(
    static fn() => $wrongHostBridge->normalizeApiData([
        'user' => ['id' => 'stg_test_player_b'],
        'weekly_match' => $testWeeklyStatus,
    ], 'bootstrap'),
    'missing or ambiguous',
    'Unexpected staging host must not bypass DB-primary weekly status'
);

fwrite(STDOUT, "WeeklyBonusRuntimeBridgeTest passed: {$assertions} assertions.\n");
