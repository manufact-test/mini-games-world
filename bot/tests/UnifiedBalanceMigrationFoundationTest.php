<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$databaseDir = $root . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';
require $root . '/economy/UnifiedBalanceMigrationRule.php';
require $root . '/economy/UnifiedBalanceMigrationPlanner.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('UnifiedBalanceMigrationFoundationTest requires pdo_sqlite.');
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if ($contains !== '' && !str_contains($error->getMessage(), $contains)) {
            throw new RuntimeException(
                $message . ': wrong exception message: ' . $error->getMessage(),
                0,
                $error
            );
        }
        return;
    }
    throw new RuntimeException($message . ': expected exception was not thrown.');
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$assertSame(11, $runner->migrate(false)['executed_count'], 'Foundation test must apply all current migrations');

$assertThrows(
    static fn() => UnifiedBalanceMigrationRule::fromApprovedConfig([
        'approved' => false,
        'target_asset' => 'mgw_coin',
    ]),
    'not explicitly approved',
    'Migration rule must fail closed without explicit approval'
);

$rule = UnifiedBalanceMigrationRule::fromApprovedConfig([
    'approved' => true,
    'version' => 'test-only-v1',
    'target_asset' => 'mgw_coin',
    'approved_by' => 'automated-test-only',
    'approved_at_utc' => '2026-08-15T08:00:00Z',
    'rates' => [
        'match_coin' => ['numerator' => 2, 'denominator' => 1],
        'gold_coin' => ['numerator' => 3, 'denominator' => 1],
    ],
]);
$assertSame(10, $rule->convert('match_coin', 5), 'Match fixture conversion must be exact');
$assertSame(21, $rule->convert('gold_coin', 7), 'Gold fixture conversion must be exact');
$assertSame(64, strlen($rule->fingerprint()), 'Approved rule must have a SHA-256 fingerprint');

$fractionalRule = UnifiedBalanceMigrationRule::fromApprovedConfig([
    'approved' => true,
    'version' => 'test-fraction-v1',
    'target_asset' => 'mgw_coin',
    'approved_by' => 'automated-test-only',
    'approved_at_utc' => '2026-08-15T08:00:00Z',
    'rates' => [
        'match_coin' => ['numerator' => 1, 'denominator' => 2],
        'gold_coin' => ['numerator' => 1, 'denominator' => 1],
    ],
]);
$assertThrows(
    static fn() => $fractionalRule->convert('match_coin', 3),
    'rounding',
    'Conversion must fail rather than round a fractional result'
);

$now = '2026-08-15 08:00:00.000000';
$insertBalance = static function (
    DatabaseConnectionInterface $database,
    string $accountRef,
    string $asset,
    int $available,
    int $reserved = 0
) use ($now): void {
    $database->execute(
        'INSERT INTO mgw_balances (
            account_ref, mgw_id, legacy_user_id, asset_code,
            available_amount, reserved_amount, version, created_at_utc, updated_at_utc
         ) VALUES (
            :account_ref, NULL, NULL, :asset_code,
            :available_amount, :reserved_amount, 0, :created_at_utc, :updated_at_utc
         )',
        [
            'account_ref' => $accountRef,
            'asset_code' => $asset,
            'available_amount' => $available,
            'reserved_amount' => $reserved,
            'created_at_utc' => $now,
            'updated_at_utc' => $now,
        ]
    );
};

$insertBalance($database, 'legacy:test-alpha', 'match_coin', 100);
$insertBalance($database, 'legacy:test-alpha', 'gold_coin', 10);
$insertBalance($database, 'legacy:test-beta', 'match_coin', 50);
$insertBalance($database, 'legacy:test-beta', 'gold_coin', 20);

$countRows = static function (DatabaseConnectionInterface $database, string $table): int {
    $rows = $database->fetchAll('SELECT COUNT(*) AS row_count FROM ' . $table);
    return (int)($rows[0]['row_count'] ?? 0);
};

$balanceRowsBefore = $countRows($database, 'mgw_balances');
$ledgerRowsBefore = $countRows($database, 'mgw_ledger_entries');
$reservationRowsBefore = $countRows($database, 'mgw_reservations');

$planner = new UnifiedBalanceMigrationPlanner($database, $rule);
$preview = $planner->preview();
$assertSame(true, $preview['ready'], 'Clean source balances must produce a ready read-only plan');
$assertSame(true, $preview['read_only'], 'Planner must declare read-only behavior');
$assertSame(2, $preview['source_account_count'], 'Planner must count source accounts without exposing IDs');
$assertSame(4, $preview['source_balance_row_count'], 'Planner must count both legacy assets');
$assertSame(150, $preview['source_totals']['match_coin']['available_amount'], 'Match source total must be exact');
$assertSame(30, $preview['source_totals']['gold_coin']['available_amount'], 'Gold source total must be exact');
$assertSame(300, $preview['source_totals']['match_coin']['converted_available_amount'], 'Converted Match total must use approved fixture rule');
$assertSame(90, $preview['source_totals']['gold_coin']['converted_available_amount'], 'Converted Gold total must use approved fixture rule');
$assertSame(390, $preview['planned_target']['available_amount'], 'Target plan must sum exact converted source amounts');
$assertSame(0, $preview['planned_target']['reserved_amount'], 'Clean fixture must have no planned reserved amount');
$assertSame(0, $preview['active_source_reservations']['count'], 'Clean fixture must have no active reservations');
$assertSame(0, $preview['target_existing']['balance_row_count'], 'Clean fixture must not have a target balance');
$assertSame([], $preview['blockers'], 'Clean fixture must have no blockers');
$assertSame(false, $preview['production_changed'], 'Preview must never claim a production mutation');
$assertSame(false, $preview['sensitive_identifiers_exposed'], 'Preview must not expose account identifiers');
$assertSame(64, strlen((string)$preview['plan_fingerprint']), 'Plan must have a SHA-256 fingerprint');
$encodedPreview = json_encode($preview, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$assertSame(false, str_contains($encodedPreview, 'legacy:test-alpha'), 'Raw source account refs must not appear in preview');
$assertSame($balanceRowsBefore, $countRows($database, 'mgw_balances'), 'Preview must not write balances');
$assertSame($ledgerRowsBefore, $countRows($database, 'mgw_ledger_entries'), 'Preview must not write ledger entries');
$assertSame($reservationRowsBefore, $countRows($database, 'mgw_reservations'), 'Preview must not write reservations');

$database->execute(
    'UPDATE mgw_balances
     SET available_amount = 90, reserved_amount = 10, version = version + 1, updated_at_utc = :updated_at_utc
     WHERE account_ref = :account_ref AND asset_code = :asset_code',
    [
        'updated_at_utc' => $now,
        'account_ref' => 'legacy:test-alpha',
        'asset_code' => 'match_coin',
    ]
);
$database->execute(
    'INSERT INTO mgw_reservations (
        reservation_id, idempotency_key, account_ref, mgw_id, legacy_user_id,
        asset_code, amount, status, source_type, source_ref, metadata_json,
        created_at_utc, updated_at_utc, expires_at_utc, consumed_at_utc, released_at_utc
     ) VALUES (
        :reservation_id, :idempotency_key, :account_ref, NULL, NULL,
        :asset_code, :amount, :status, :source_type, :source_ref, NULL,
        :created_at_utc, :updated_at_utc, NULL, NULL, NULL
     )',
    [
        'reservation_id' => 'res_test_active',
        'idempotency_key' => 'test-active-reservation',
        'account_ref' => 'legacy:test-alpha',
        'asset_code' => 'match_coin',
        'amount' => 10,
        'status' => 'active',
        'source_type' => 'test',
        'source_ref' => 'mvp15-3-test',
        'created_at_utc' => $now,
        'updated_at_utc' => $now,
    ]
);

$blockedByReservation = $planner->preview();
$assertSame(false, $blockedByReservation['ready'], 'Active reservation must block migration readiness');
$assertSame(1, $blockedByReservation['active_source_reservations']['count'], 'Planner must count active source reservations');
$assertSame(10, $blockedByReservation['active_source_reservations']['amount'], 'Planner must total active reservation amount');
$assertTrue(
    str_contains(implode(' ', $blockedByReservation['blockers']), 'Active Match/Gold reservations'),
    'Active reservation blocker must be explicit'
);
$assertTrue(
    str_contains(implode(' ', $blockedByReservation['blockers']), 'reserved amounts'),
    'Reserved source amount blocker must be explicit'
);

$database->execute(
    "UPDATE mgw_reservations
     SET status = 'released', released_at_utc = :released_at_utc, updated_at_utc = :updated_at_utc
     WHERE reservation_id = :reservation_id",
    [
        'released_at_utc' => $now,
        'updated_at_utc' => $now,
        'reservation_id' => 'res_test_active',
    ]
);
$stillBlockedByReservedBalance = $planner->preview();
$assertSame(false, $stillBlockedByReservedBalance['ready'], 'Orphan reserved balance must remain blocked after reservation release');
$assertSame(0, $stillBlockedByReservedBalance['active_source_reservations']['count'], 'Released reservation must no longer count active');

$database->execute(
    'UPDATE mgw_balances
     SET available_amount = 100, reserved_amount = 0, version = version + 1, updated_at_utc = :updated_at_utc
     WHERE account_ref = :account_ref AND asset_code = :asset_code',
    [
        'updated_at_utc' => $now,
        'account_ref' => 'legacy:test-alpha',
        'asset_code' => 'match_coin',
    ]
);
$readyAgain = $planner->preview();
$assertSame(true, $readyAgain['ready'], 'Resolved reservation state must restore readiness');

$insertBalance($database, 'legacy:test-alpha', 'mgw_coin', 0);
$targetBlocked = $planner->preview();
$assertSame(false, $targetBlocked['ready'], 'Any pre-existing target balance row must block a competing migration');
$assertSame(1, $targetBlocked['target_existing']['balance_row_count'], 'Target row blocker must be counted');
$assertTrue(
    str_contains(implode(' ', $targetBlocked['blockers']), 'Target mgw_coin balance rows already exist'),
    'Existing target blocker must be explicit'
);

fwrite(STDOUT, "UnifiedBalanceMigrationFoundationTest: {$assertions} assertions passed\n");