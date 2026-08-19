<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$databaseDir = $root . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';
require $databaseDir . '/DatabaseConfig.php';
require $root . '/accounts/MgwIdGenerator.php';
require $root . '/accounts/AccountIdentityService.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/ledger/LedgerIntegrityVerifier.php';
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/ledger/RuntimeEconomySnapshotStorage.php';
require $root . '/ledger/LegacyEconomyShadowSyncService.php';
require $root . '/ledger/LegacyEconomyDeltaImportService.php';
require $root . '/ledger/LegacyEconomyRuntimeReconciliationService.php';
require $root . '/ledger/RuntimeEconomyBalanceBootstrapService.php';
require $root . '/economy/UnifiedBalanceMigrationRule.php';
require $root . '/economy/UnifiedBalanceMigrationPlanner.php';
require $root . '/economy/UnifiedBalanceMigrationExecutor.php';
require $root . '/economy/UnifiedBalanceMigrationCoordinator.php';
require $root . '/economy/UnifiedBalanceRuntimeState.php';
require $root . '/economy/UnifiedEconomyRuntimeSyncService.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/ledger/RuntimeEconomyRepository.php';
require $root . '/services/UserService.php';

if (!function_exists('now_iso')) {
    function now_iso(): string { return gmdate('c'); }
}
if (!function_exists('clean_string')) {
    function clean_string(mixed $value, int $max = 255): string {
        $value = trim((string)$value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
}

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('UnifiedEconomyRuntimeSyncTest requires pdo_sqlite.');
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$assertSame(10, $runner->migrate(false)['executed_count'], 'Runtime sync test must apply all migrations');

$accounts = new AccountIdentityService($database, 3600);
$firstIdentity = $accounts->resolveTelegramUser([
    'id' => '111001',
    'first_name' => 'Alpha',
], 'sync-alpha-session');
$firstMgwId = (string)$firstIdentity['mgw_id'];
$assertTrue(MgwIdGenerator::isValid($firstMgwId), 'First fixture must have a valid MGW id');

$now = '2026-08-15 09:30:00.000000';
$insertOwnership = static function (
    DatabaseConnectionInterface $db,
    string $legacyUserId,
    string $mgwId,
    string $now
): void {
    $db->execute(
        'INSERT INTO mgw_account_ownership (
            account_ref, mgw_id, legacy_user_id, ownership_status,
            source_type, source_ref, source_sha256, created_at_utc, verified_at_utc
         ) VALUES (
            :account_ref, :mgw_id, :legacy_user_id, :ownership_status,
            :source_type, :source_ref, :source_sha256, :created_at_utc, :verified_at_utc
         )',
        [
            'account_ref' => 'legacy:' . $legacyUserId,
            'mgw_id' => $mgwId,
            'legacy_user_id' => $legacyUserId,
            'ownership_status' => 'active',
            'source_type' => 'legacy_json',
            'source_ref' => 'users.json:' . $legacyUserId,
            'source_sha256' => hash('sha256', 'sync-source|' . $legacyUserId),
            'created_at_utc' => $now,
            'verified_at_utc' => $now,
        ]
    );
};
$insertOwnership($database, '111001', $firstMgwId, $now);

$insertLegacyBalance = static function (
    DatabaseConnectionInterface $db,
    string $legacyUserId,
    string $asset,
    int $amount,
    string $now
): void {
    $db->execute(
        'INSERT INTO mgw_balances (
            account_ref, mgw_id, legacy_user_id, asset_code,
            available_amount, reserved_amount, version, created_at_utc, updated_at_utc
         ) VALUES (
            :account_ref, NULL, :legacy_user_id, :asset_code,
            :available_amount, 0, 0, :created_at_utc, :updated_at_utc
         )',
        [
            'account_ref' => 'legacy:' . $legacyUserId,
            'legacy_user_id' => $legacyUserId,
            'asset_code' => $asset,
            'available_amount' => $amount,
            'created_at_utc' => $now,
            'updated_at_utc' => $now,
        ]
    );
};
$insertLegacyBalance($database, '111001', 'match_coin', 100, $now);
$insertLegacyBalance($database, '111001', 'gold_coin', 10, $now);

$rule = UnifiedBalanceMigrationRule::fromApprovedConfig(require $root . '/economy/unified_balance_mapping.php');
$ledger = new LedgerWriteService($database, static fn(): string => $now);
$integrity = new LedgerIntegrityVerifier($database);
$coordinator = new UnifiedBalanceMigrationCoordinator($database, $rule, $ledger, $integrity);

$before = $coordinator->preview();
$assertSame(false, $before['completed'], 'Read-only preview must not invent a completed cutover marker');
$assertSame(0, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_balances WHERE asset_code = 'mgw_coin'"), 'Read-only preview must not create a target balance');

$migration = $coordinator->ensureMigrated();
$assertSame(true, $migration['ok'], 'One-time cutover must complete');
$assertSame(110, (int)$database->fetchValue("SELECT available_amount FROM mgw_balances WHERE account_ref = 'legacy:111001' AND asset_code = 'mgw_coin'"), 'Cutover must create exact Match+Gold canonical balance');
$assertSame(1, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_idempotency_keys WHERE operation_type = 'unified_balance_cutover' AND status = 'completed'"), 'Cutover must leave one durable completed marker');

$after = $coordinator->preview();
$assertSame(true, $after['completed'], 'Read-only preview must verify the completed marker');
$assertSame(true, $after['read_only'], 'Marker preview must stay read-only');

$sync = new UnifiedEconomyRuntimeSyncService($database, $ledger, $integrity);
$snapshot = ['users' => ['111001' => ['id'=>'111001','balance'=>110,'balance_match'=>100,'balance_gold'=>10]]];
$initialPreview = $sync->preview($snapshot);
$assertSame(true, $initialPreview['ready'], 'Post-cutover canonical snapshot must be ready');
$assertSame(0, $initialPreview['planned_delta_count'], 'Equal canonical and DB balances need no delta');

$snapshot['users']['111001']['balance'] = 90;
$debit = $sync->run($snapshot);
$assertSame(1, $debit['applied_delta_count'], 'Canonical debit must create exactly one DB delta');
$assertSame(20, $debit['debited_total'], 'Canonical debit must preserve exact amount');
$assertSame(90, (int)$database->fetchValue("SELECT available_amount FROM mgw_balances WHERE account_ref = 'legacy:111001' AND asset_code = 'mgw_coin'"), 'DB mgw_coin must follow the canonical debit');
$assertSame(100, (int)$database->fetchValue("SELECT available_amount FROM mgw_balances WHERE account_ref = 'legacy:111001' AND asset_code = 'match_coin'"), 'Legacy Match DB row must remain frozen after canonical debit');
$assertSame(10, (int)$database->fetchValue("SELECT available_amount FROM mgw_balances WHERE account_ref = 'legacy:111001' AND asset_code = 'gold_coin'"), 'Legacy Gold DB row must remain frozen after canonical debit');

$snapshot['users']['111001']['balance'] = 130;
$credit = $sync->run($snapshot);
$assertSame(1, $credit['applied_delta_count'], 'Canonical credit must create exactly one DB delta');
$assertSame(40, $credit['credited_total'], 'Canonical credit must preserve exact amount');
$assertSame(130, (int)$database->fetchValue("SELECT available_amount FROM mgw_balances WHERE account_ref = 'legacy:111001' AND asset_code = 'mgw_coin'"), 'DB mgw_coin must follow the canonical credit');
$assertSame(100, (int)$database->fetchValue("SELECT available_amount FROM mgw_balances WHERE account_ref = 'legacy:111001' AND asset_code = 'match_coin'"), 'Legacy Match remains frozen after canonical credit');
$assertSame(10, (int)$database->fetchValue("SELECT available_amount FROM mgw_balances WHERE account_ref = 'legacy:111001' AND asset_code = 'gold_coin'"), 'Legacy Gold remains frozen after canonical credit');

$secondIdentity = $accounts->resolveTelegramUser(['id'=>'222002','first_name'=>'Beta'], 'sync-beta-session');
$secondMgwId = (string)$secondIdentity['mgw_id'];
$insertOwnership($database, '222002', $secondMgwId, $now);
$insertLegacyBalance($database, '222002', 'match_coin', 0, $now);
$insertLegacyBalance($database, '222002', 'gold_coin', 0, $now);

$migrationEntryCountBefore = (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_ledger_entries WHERE category = 'unified_balance_migration'");
$markerReplay = $coordinator->ensureMigrated();
$assertSame(true, $markerReplay['replayed'], 'Completed marker must prevent a second legacy conversion pass');
$assertSame($migrationEntryCountBefore, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_ledger_entries WHERE category = 'unified_balance_migration'"), 'New account after marker must not create legacy conversion entries');

$snapshot['users']['222002'] = ['id'=>'222002','balance'=>25,'balance_match'=>0,'balance_gold'=>0];
$newAccountSync = $sync->run($snapshot);
$assertSame(1, $newAccountSync['applied_delta_count'], 'A post-cutover account must initialize through mgw_coin runtime sync');
$assertSame(25, (int)$database->fetchValue("SELECT available_amount FROM mgw_balances WHERE account_ref = 'legacy:222002' AND asset_code = 'mgw_coin'"), 'Post-cutover account must receive canonical balance without legacy conversion');
$assertSame(0, (int)$database->fetchValue("SELECT available_amount FROM mgw_balances WHERE account_ref = 'legacy:222002' AND asset_code = 'match_coin'"), 'Post-cutover legacy Match row remains frozen at zero');
$assertSame(0, (int)$database->fetchValue("SELECT available_amount FROM mgw_balances WHERE account_ref = 'legacy:222002' AND asset_code = 'gold_coin'"), 'Post-cutover legacy Gold row remains frozen at zero');

$finalPreview = $sync->preview($snapshot);
$assertSame(true, $finalPreview['ready'], 'Final canonical parity must be ready');
$assertSame(true, $finalPreview['reconciled'], 'Final canonical parity must be reconciled');
$assertSame(0, $finalPreview['planned_delta_count'], 'Final canonical parity must require no writes');
$assertSame(false, $finalPreview['sensitive_identifiers_exposed'], 'Public sync report must not expose identifiers');

$partialSnapshot = ['users' => ['111001' => $snapshot['users']['111001']]];
$partialPreview = $sync->preview($partialSnapshot);
$assertSame(true, $partialPreview['ready'], 'Partial post-cutover snapshot must not classify another owned balance as unmanaged');
$assertSame(0, $partialPreview['planned_delta_count'], 'Partial snapshot must not create a debit for an absent account');
$emptyPreview = $sync->preview(['users'=>[]]);
$assertSame(true, $emptyPreview['ready'], 'Empty stripped rollback snapshot must be safe after completed cutover');
$assertSame(0, $emptyPreview['planned_delta_count'], 'Empty stripped rollback snapshot must never modify durable balances');
$assertSame(25, (int)$database->fetchValue("SELECT available_amount FROM mgw_balances WHERE account_ref = 'legacy:222002' AND asset_code = 'mgw_coin'"), 'Absent account balance must remain unchanged');

$runtimeConfig = [
    'environment' => 'local',
    'storage_driver' => 'json',
    'initial_match_coins' => 0,
    'initial_gold_coins' => 0,
    'admin_ids' => [],
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
            'modules' => ['accounts'=>true,'economy'=>true],
        ],
    ],
];
$router = new RuntimeStorageRouter($runtimeConfig);
$repository = new RuntimeEconomyRepository($runtimeConfig, $router, $database);
$database->execute("DELETE FROM mgw_legacy_realtime_shadow WHERE entity_type = 'economy_user_balance'");
$postCutoverSync = $repository->synchronize($partialSnapshot);
$assertSame(true, $postCutoverSync['ok'], 'Post-cutover repository sync must succeed without economy balance shadow rows');
$assertSame('post_cutover', $postCutoverSync['phase'], 'Durable marker must select the post-cutover synchronization lane');
$assertSame(true, $postCutoverSync['legacy_delta']['skipped'] ?? false, 'Legacy delta importer must stay frozen after cutover');
$assertSame(130, (int)$database->fetchValue("SELECT available_amount FROM mgw_balances WHERE account_ref = 'legacy:111001' AND asset_code = 'mgw_coin'"), 'Post-cutover repository sync must preserve current canonical balance');
$assertSame(25, (int)$database->fetchValue("SELECT available_amount FROM mgw_balances WHERE account_ref = 'legacy:222002' AND asset_code = 'mgw_coin'"), 'Post-cutover repository sync must preserve absent account balance');
$postCutoverAudit = $repository->auditParity($partialSnapshot);
$assertSame(true, $postCutoverAudit['ok'], 'Post-cutover audit must ignore retired legacy shadow parity');
$assertSame('post_cutover', $postCutoverAudit['phase'], 'Post-cutover audit must report the durable cutover lane');

$runtimeState = ['users'=>[], 'games'=>[]];
$userService = new UserService($runtimeConfig, $database);
$rehydrated = $userService->ensureUser($runtimeState, [
    'id' => '111001',
    'first_name' => 'Provider Alpha',
    'username' => 'provider_alpha',
    'mgw_id' => $firstMgwId,
    'mgw_account_ref' => 'legacy:111001',
    'mgw_identity_provider' => 'telegram',
]);
$assertSame(130, (int)$rehydrated['balance'], 'Missing runtime user must restore the existing canonical DB balance');
$assertSame(130, (int)$runtimeState['users']['111001']['balance'], 'Rehydrated canonical balance must persist into the resumed runtime copy');

fwrite(STDOUT, "UnifiedEconomyRuntimeSyncTest: {$assertions} assertions passed\n");
