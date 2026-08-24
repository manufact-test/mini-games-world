<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$databaseDir = $root . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/ledger/LedgerIntegrityVerifier.php';
require $root . '/economy/UnifiedBalanceMigrationRule.php';
require $root . '/economy/UnifiedBalanceMigrationPlanner.php';
require $root . '/economy/UnifiedBalanceMigrationExecutor.php';
require $root . '/economy/UnifiedBalanceRuntimeState.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('UnifiedBalanceMigrationExecutionTest requires pdo_sqlite.');
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
        if ($contains !== '' && !str_contains($error->getMessage(), $contains)) {
            throw new RuntimeException($message . ': wrong exception: ' . $error->getMessage(), 0, $error);
        }
        return;
    }
    throw new RuntimeException($message . ': expected exception was not thrown.');
};

$mapping = require $root . '/economy/unified_balance_mapping.php';
$rule = UnifiedBalanceMigrationRule::fromApprovedConfig($mapping);
$assertSame('mvp15.3-staging-1to1-v1', $rule->version(), 'Approved product mapping version must be exact');
$assertSame(7, $rule->convert('match_coin', 7), 'Match migration must be 1:1');
$assertSame(9, $rule->convert('gold_coin', 9), 'Gold migration must be 1:1');

$runtimeUser = [
    'id' => 'runtime-test',
    'balance_match' => 100,
    'balance_gold' => 50,
];
$firstRuntime = UnifiedBalanceRuntimeState::ensureUser($runtimeUser);
$assertSame(true, $firstRuntime['migrated'], 'Legacy runtime user must migrate once');
$assertSame(150, $runtimeUser['balance'], 'Legacy Match and Gold must sum into one canonical balance');
$assertSame(100, $runtimeUser['balance_match'], 'Legacy Match snapshot must stay frozen');
$assertSame(50, $runtimeUser['balance_gold'], 'Legacy Gold snapshot must stay frozen');
$runtimeUser['balance'] = 125;
$secondRuntime = UnifiedBalanceRuntimeState::ensureUser($runtimeUser);
$assertSame(false, $secondRuntime['migrated'], 'Canonical runtime user must not migrate twice');
$assertSame(125, $runtimeUser['balance'], 'Current canonical balance must not be re-summed from legacy fields');
$assertSame(150, $runtimeUser['unified_balance_migration']['target_balance'], 'Migration target snapshot must remain immutable');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$expectedMigrations = count(glob($databaseDir . '/migrations/*.php') ?: []);
$assertSame($expectedMigrations, $runner->migrate(false)['executed_count'], 'Execution test must apply all current migrations');

$now = '2026-08-15 08:55:00.000000';
$insert = static function (DatabaseConnectionInterface $db, string $account, string $asset, int $amount) use ($now): void {
    $db->execute(
        'INSERT INTO mgw_balances (
            account_ref, mgw_id, legacy_user_id, asset_code,
            available_amount, reserved_amount, version, created_at_utc, updated_at_utc
         ) VALUES (
            :account_ref, NULL, NULL, :asset_code,
            :available_amount, 0, 0, :created_at_utc, :updated_at_utc
         )',
        [
            'account_ref' => $account,
            'asset_code' => $asset,
            'available_amount' => $amount,
            'created_at_utc' => $now,
            'updated_at_utc' => $now,
        ]
    );
};
$insert($database, 'legacy:alpha', 'match_coin', 100);
$insert($database, 'legacy:alpha', 'gold_coin', 10);
$insert($database, 'legacy:beta', 'match_coin', 50);
$insert($database, 'legacy:beta', 'gold_coin', 20);

$ledger = new LedgerWriteService($database, static fn(): string => $now);
$integrity = new LedgerIntegrityVerifier($database);
$executor = new UnifiedBalanceMigrationExecutor($database, $rule, $ledger, $integrity);

$first = $executor->run();
$assertSame(true, $first['ok'], 'Atomic migration must complete');
$assertSame(false, $first['replayed'], 'First migration run must not be a replay');
$assertSame(2, $first['source_account_count'], 'Two source accounts must migrate');
$assertSame(150, $first['source_totals']['match_coin'], 'Match source total must be preserved');
$assertSame(30, $first['source_totals']['gold_coin'], 'Gold source total must be preserved');
$assertSame(180, $first['target_total'], 'Unified total must equal Match + Gold at 1:1');
$assertSame(4, $first['applied_ledger_entry_count'], 'Each non-zero source asset must create one compensating target ledger entry');
$assertSame(true, $first['source_balances_preserved'], 'Migration must preserve legacy source balances');

$targets = $database->fetchAll(
    "SELECT account_ref, available_amount, reserved_amount FROM mgw_balances
     WHERE asset_code = 'mgw_coin' ORDER BY account_ref"
);
$assertSame(2, count($targets), 'One unified balance row must exist per source account');
$assertSame(110, (int)$targets[0]['available_amount'], 'Alpha unified balance must be exact');
$assertSame(70, (int)$targets[1]['available_amount'], 'Beta unified balance must be exact');
$assertSame(0, (int)$targets[0]['reserved_amount'], 'Unified balance must start unreserved');

$sourceAfter = $database->fetchAll(
    "SELECT asset_code, SUM(available_amount) AS total FROM mgw_balances
     WHERE asset_code IN ('match_coin', 'gold_coin') GROUP BY asset_code ORDER BY asset_code"
);
$sourceMap = [];
foreach ($sourceAfter as $row) $sourceMap[(string)$row['asset_code']] = (int)$row['total'];
$assertSame(30, $sourceMap['gold_coin'], 'Legacy Gold rows must stay unchanged for audit');
$assertSame(150, $sourceMap['match_coin'], 'Legacy Match rows must stay unchanged for audit');

$ledgerCount = (int)$database->fetchValue(
    "SELECT COUNT(*) FROM mgw_ledger_entries
     WHERE asset_code = 'mgw_coin' AND category = 'unified_balance_migration'"
);
$assertSame(4, $ledgerCount, 'Unified migration must leave four immutable ledger entries');

$second = $executor->run();
$assertSame(true, $second['ok'], 'Second run must verify completed migration');
$assertSame(true, $second['replayed'], 'Second run must be an idempotent replay');
$assertSame(0, $second['applied_ledger_entry_count'], 'Replay must not apply new credits');
$assertSame(4, $second['replayed_ledger_entry_count'], 'Replay must account for existing migration entries');
$assertSame($ledgerCount, (int)$database->fetchValue(
    "SELECT COUNT(*) FROM mgw_ledger_entries
     WHERE asset_code = 'mgw_coin' AND category = 'unified_balance_migration'"
), 'Replay must not duplicate ledger entries');

$database->execute(
    "UPDATE mgw_balances SET available_amount = available_amount + 1
     WHERE account_ref = 'legacy:alpha' AND asset_code = 'mgw_coin'"
);
$assertThrows(
    static fn() => $executor->run(),
    'does not match',
    'A conflicting target balance must fail closed instead of being repaired silently'
);

fwrite(STDOUT, "UnifiedBalanceMigrationExecutionTest: {$assertions} assertions passed\n");
