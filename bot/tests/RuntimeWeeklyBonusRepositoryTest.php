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
require $root . '/services/WeeklyMatchEconomyService.php';
require $root . '/weekly/RuntimeWeeklyBonusSchemaInstaller.php';
require $root . '/weekly/RuntimeWeeklyBonusRepository.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('RuntimeWeeklyBonusRepositoryTest requires pdo_sqlite.');
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
$assertSame(7, $runner->migrate(false)['executed_count'], 'Weekly runtime test must apply every managed migration');

$now = '2026-07-19 17:20:00.000000';
$legacyUserId = '6101';
$mgwId = 'MGW-WEEKRUNTIME1';
$accountRef = 'mgw:' . $mgwId;
$database->execute(
    'INSERT INTO mgw_users (mgw_id, status, display_name, created_at_utc, updated_at_utc, last_seen_at_utc) '
    . 'VALUES (:id, :status, :name, :created, :updated, :seen)',
    ['id' => $mgwId, 'status' => 'active', 'name' => 'Weekly Runtime', 'created' => $now, 'updated' => $now, 'seen' => $now]
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
        'ref' => 'weekly-runtime:' . $legacyUserId,
        'hash' => str_repeat('a', 64),
        'created' => $now,
        'verified' => $now,
    ]
);

$initial = [
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
            'last_seen_at' => '2026-07-19T17:20:00+00:00',
        ],
    ],
    'games' => [],
    'queue' => [],
    'invites' => [],
    'notifications' => [],
    'payments' => [],
    'shop_orders' => [],
    'transactions' => [[
        'id' => 'tx_weekly_welcome_1',
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
$initialStorage = new RuntimeEconomySnapshotStorage($initial);
(new LegacyEconomyShadowSyncService($initialStorage, $database))->run();
$ledger = new LedgerWriteService($database);
$integrity = new LedgerIntegrityVerifier($database);
(new LegacyOpeningBalanceImportService($database, $ledger, $integrity))->run();

$config = [
    'environment' => 'staging',
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
                'economy' => true,
                'history' => true,
                'weekly_bonus' => true,
            ],
        ],
    ],
];
$router = new RuntimeStorageRouter($config);
(new RuntimeRealtimeRepository($config, $router, $database))->synchronize($initial);
$schema = new RuntimeWeeklyBonusSchemaInstaller($database);
$install = $schema->install();
$assertSame(true, $install['ok'], 'Weekly runtime schema must install');
$assertSame(12, $install['verification']['present_column_count'], 'Weekly schema must expose every required column');
$assertSame(true, $schema->install()['idempotent'], 'Repeated weekly schema installation must be a no-op');

$initialRepository = new RuntimeWeeklyBonusRepository($config, $router, $initialStorage, $database);
$initialSync = $initialRepository->bootstrapCurrentJson();
$assertSame(true, $initialSync['ok'], 'Initial weekly runtime synchronization must succeed');
$assertSame(1, $initialSync['weekly_states']['inserted_count'], 'Initial weekly state must create one DB row');
$assertSame(true, $initialRepository->auditParity($initial)['ok'], 'Initial weekly state must be in parity');
$status = $initialRepository->statusForLegacyUser($legacyUserId);
$assertSame(true, $status['enabled'], 'Weekly DB read must return an enabled status');
$assertSame(50, $status['bonus_amount'], 'Weekly DB read must preserve the configured bonus');

$awarded = $initial;
$awarded['users'][$legacyUserId]['balance_match'] = 100;
$awarded['users'][$legacyUserId]['weekly_match_bonus_checked_key'] = '2026-07-13';
$awarded['users'][$legacyUserId]['weekly_match_bonus_checked_at'] = '2026-07-19T17:21:00+00:00';
$awarded['users'][$legacyUserId]['weekly_match_bonus_checked_games'] = 3;
$awarded['users'][$legacyUserId]['weekly_match_bonus_last_key'] = '2026-07-13';
$awarded['users'][$legacyUserId]['weekly_match_bonus_last_at'] = '2026-07-19T17:21:00+00:00';
$awarded['users'][$legacyUserId]['weekly_match_bonus_last_amount'] = 50;
$awarded['users'][$legacyUserId]['weekly_match_bonus_last_qualification'] = 'activity';
$awarded['users'][$legacyUserId]['weekly_bonus_last'] = '2026-07-13';
$awarded['transactions'][] = [
    'id' => 'tx_weekly_activity_1',
    'type' => 'balance_change',
    'category' => 'weekly_bonus',
    'user_id' => $legacyUserId,
    'room' => 'match',
    'amount' => 50,
    'balance_before' => 50,
    'balance_after' => 100,
    'cycle_key' => '2026-07-13',
    'created_at' => '2026-07-19T17:21:00+00:00',
];
$awardedStorage = new RuntimeEconomySnapshotStorage($awarded);
(new RuntimeRealtimeRepository($config, $router, $database))->synchronize($awarded);
$awardedRepository = new RuntimeWeeklyBonusRepository($config, $router, $awardedStorage, $database);
$awardedSync = $awardedRepository->synchronizeCurrentJson();
$assertSame(1, $awardedSync['weekly_states']['updated_count'], 'Weekly award must update the mirrored user state');
$assertSame(1, $awardedSync['economy']['applied_delta_count'], 'Weekly award must create one immutable ledger delta');
$assertSame(true, $awardedRepository->auditParity($awarded)['ok'], 'Awarded weekly state must remain in parity');
$assertSame('2026-07-13', (string)$database->fetchValue(
    'SELECT last_key FROM mgw_runtime_weekly_bonus_state WHERE legacy_user_id = :legacy_user_id',
    ['legacy_user_id' => $legacyUserId]
), 'Weekly mirror must preserve the last awarded cycle');

$repeat = $awardedRepository->synchronizeCurrentJson();
$assertSame(0, $repeat['weekly_states']['updated_count'], 'Repeated weekly synchronization must not rewrite unchanged state');
$assertSame(1, $repeat['weekly_states']['unchanged_count'], 'Repeated weekly synchronization must report unchanged state');

$drifted = $awarded;
$drifted['users'][$legacyUserId]['weekly_match_bonus_checked_games'] = 2;
$driftAudit = $awardedRepository->auditParity($drifted);
$assertSame(false, $driftAudit['ok'], 'Read-only weekly audit must detect drift');
$assertSame(1, $driftAudit['mismatch_count'], 'Weekly drift must count one mismatch');

$disabledConfig = $config;
$disabledConfig['feature_flags']['database_runtime']['modules']['weekly_bonus'] = false;
$disabled = new RuntimeWeeklyBonusRepository(
    $disabledConfig,
    new RuntimeStorageRouter($disabledConfig),
    $awardedStorage,
    $database
);
$assertThrows(
    static fn() => $disabled->auditParity($awarded),
    'requires accounts, realtime, economy, history and weekly_bonus routing',
    'Disabled weekly route must reject DB runtime access'
);

fwrite(STDOUT, "RuntimeWeeklyBonusRepositoryTest passed: {$assertions} assertions.\n");
