<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$databaseDir = $root . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';
require $root . '/accounts/MgwIdGenerator.php';
require $root . '/accounts/AccountIdentityService.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/ledger/LedgerIntegrityVerifier.php';
require $root . '/economy/UnifiedBalanceMigrationRule.php';
require $root . '/economy/UnifiedBalanceMigrationPlanner.php';
require $root . '/economy/UnifiedBalanceMigrationExecutor.php';
require $root . '/economy/UnifiedBalanceMigrationCoordinator.php';
require $root . '/economy/UnifiedBalanceRuntimeState.php';
require $root . '/economy/UnifiedEconomyRuntimeSyncService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('UnifiedEconomyDevUserSyncRegressionTest requires pdo_sqlite.');
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$assertSame(12, $runner->migrate(false)['executed_count'], 'Regression fixture must apply current schema');

$accounts = new AccountIdentityService($database, 3600);
$normal = $accounts->resolveTelegramUser(['id' => '910001', 'first_name' => 'Normal'], 'normal-session');
$dev = $accounts->resolveTelegramUser(['id' => '910002', 'first_name' => 'Dev'], 'dev-session');
$normalMgw = (string)$normal['mgw_id'];
$devMgw = (string)$dev['mgw_id'];
$now = '2026-08-15 10:30:00.000000';

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
            'source_type' => 'runtime_identity',
            'source_ref' => 'development:' . $legacyUserId,
            'source_sha256' => hash('sha256', 'dev-sync|' . $legacyUserId . '|' . $mgwId),
            'created_at_utc' => $now,
            'verified_at_utc' => $now,
        ]
    );
};
$insertOwnership($database, '910001', $normalMgw, $now);
$insertOwnership($database, '910002', $devMgw, $now);

$insertBalance = static function (
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
$insertBalance($database, '910001', 'match_coin', 100, $now);
$insertBalance($database, '910001', 'gold_coin', 10, $now);
$insertBalance($database, '910002', 'match_coin', 40, $now);
$insertBalance($database, '910002', 'gold_coin', 5, $now);

$rule = UnifiedBalanceMigrationRule::fromApprovedConfig(require $root . '/economy/unified_balance_mapping.php');
$ledger = new LedgerWriteService($database, static fn(): string => $now);
$integrity = new LedgerIntegrityVerifier($database);
$coordinator = new UnifiedBalanceMigrationCoordinator($database, $rule, $ledger, $integrity);
$migration = $coordinator->ensureMigrated();
$assertSame(true, $migration['ok'], 'Cutover must migrate both normal and development source accounts');
$assertSame(2, $migration['source_account_count'], 'Cutover must include both source accounts');

$snapshot = [
    'users' => [
        '910001' => [
            'id' => '910001',
            'balance' => 110,
            'balance_match' => 100,
            'balance_gold' => 10,
        ],
        '910002' => [
            'id' => '910002',
            'is_dev_user' => true,
            'balance' => 45,
            'balance_match' => 40,
            'balance_gold' => 5,
        ],
    ],
];

$sync = new UnifiedEconomyRuntimeSyncService($database, $ledger, $integrity);
$preview = $sync->preview($snapshot);
$assertSame(true, $preview['ready'], 'Development user must remain a managed unified balance after cutover');
$assertSame(2, $preview['source_user_count'], 'Unified sync must include both normal and development runtime users');
$assertSame(0, $preview['planned_delta_count'], 'Migrated development balance must already be at parity');
$assertSame([], $preview['blocking_reasons'], 'Development user must not create an unmanaged unified balance blocker');

fwrite(STDOUT, "UnifiedEconomyDevUserSyncRegressionTest: {$assertions} assertions passed\n");
