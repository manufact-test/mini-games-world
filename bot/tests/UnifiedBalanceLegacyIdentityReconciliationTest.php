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

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('UnifiedBalanceLegacyIdentityReconciliationTest requires pdo_sqlite.');
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
        if (!str_contains($error->getMessage(), $contains)) {
            throw new RuntimeException($message . ': wrong exception: ' . $error->getMessage(), 0, $error);
        }
        return;
    }
    throw new RuntimeException($message . ': expected exception was not thrown.');
};

$mapping = require $root . '/economy/unified_balance_mapping.php';
$rule = UnifiedBalanceMigrationRule::fromApprovedConfig($mapping);
$now = '2026-08-15 10:20:00.000000';
$canonicalMgwId = 'MGW-0000000000000001';
$foreignMgwId = 'MGW-0000000000000002';

$makeDatabase = static function () use ($databaseDir): DatabaseConnectionInterface {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $database = new PdoDatabaseConnection($pdo);
    $runner = new MigrationRunner($database, $databaseDir . '/migrations');
    $result = $runner->migrate(false);
    if ((int)($result['executed_count'] ?? 0) !== 9) {
        throw new RuntimeException('Expected all nine database migrations in identity regression fixture.');
    }
    return $database;
};

$insertOwnership = static function (
    DatabaseConnectionInterface $db,
    string $legacyUserId,
    string $mgwId
) use ($now): void {
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
            'source_type' => 'test',
            'source_ref' => 'identity-regression',
            'source_sha256' => hash('sha256', $legacyUserId . '|' . $mgwId),
            'created_at_utc' => $now,
            'verified_at_utc' => $now,
        ]
    );
};

$insertBalance = static function (
    DatabaseConnectionInterface $db,
    string $legacyUserId,
    string $asset,
    int $amount,
    ?string $mgwId
) use ($now): void {
    $db->execute(
        'INSERT INTO mgw_balances (
            account_ref, mgw_id, legacy_user_id, asset_code,
            available_amount, reserved_amount, version, created_at_utc, updated_at_utc
         ) VALUES (
            :account_ref, :mgw_id, :legacy_user_id, :asset_code,
            :available_amount, 0, 0, :created_at_utc, :updated_at_utc
         )',
        [
            'account_ref' => 'legacy:' . $legacyUserId,
            'mgw_id' => $mgwId,
            'legacy_user_id' => $legacyUserId,
            'asset_code' => $asset,
            'available_amount' => $amount,
            'created_at_utc' => $now,
            'updated_at_utc' => $now,
        ]
    );
};

$db = $makeDatabase();
$insertOwnership($db, 'alpha', $canonicalMgwId);
$insertBalance($db, 'alpha', 'match_coin', 100, null);
$insertBalance($db, 'alpha', 'gold_coin', 25, $canonicalMgwId);
$executor = new UnifiedBalanceMigrationExecutor(
    $db,
    $rule,
    new LedgerWriteService($db, static fn(): string => $now),
    new LedgerIntegrityVerifier($db)
);
$result = $executor->run();
$assertSame(true, $result['ok'], 'Canonical NULL/backfilled source identity must migrate');
$assertSame(125, $result['target_total'], 'Reconciled identity must not alter the 1:1 source total');
$target = $db->fetchAll(
    "SELECT mgw_id, legacy_user_id, available_amount FROM mgw_balances
     WHERE account_ref = 'legacy:alpha' AND asset_code = 'mgw_coin'"
);
$assertSame(1, count($target), 'Exactly one canonical target balance must be created');
$assertSame($canonicalMgwId, (string)$target[0]['mgw_id'], 'Target must use immutable ownership MGW ID');
$assertSame('alpha', (string)$target[0]['legacy_user_id'], 'Target must retain canonical legacy identity');
$assertSame(125, (int)$target[0]['available_amount'], 'Target amount must remain Match + Gold at 1:1');
$sourceMgwIds = $db->fetchAll(
    "SELECT asset_code, mgw_id FROM mgw_balances
     WHERE account_ref = 'legacy:alpha' AND asset_code IN ('match_coin','gold_coin')
     ORDER BY asset_code"
);
$assertSame(null, $sourceMgwIds[1]['mgw_id'], 'Legacy NULL source metadata must remain immutable');

$conflictDb = $makeDatabase();
$insertOwnership($conflictDb, 'beta', $canonicalMgwId);
$insertBalance($conflictDb, 'beta', 'match_coin', 50, null);
$insertBalance($conflictDb, 'beta', 'gold_coin', 10, $foreignMgwId);
$conflictExecutor = new UnifiedBalanceMigrationExecutor(
    $conflictDb,
    $rule,
    new LedgerWriteService($conflictDb, static fn(): string => $now),
    new LedgerIntegrityVerifier($conflictDb)
);
$assertThrows(
    static fn() => $conflictExecutor->run(),
    'conflicts with canonical account ownership',
    'Foreign non-null MGW identity must remain a hard migration blocker'
);

fwrite(STDOUT, "UnifiedBalanceLegacyIdentityReconciliationTest: {$assertions} assertions passed\n");
