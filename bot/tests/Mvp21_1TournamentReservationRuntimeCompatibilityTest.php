<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/ledger/LedgerIntegrityVerifier.php';
require $root . '/economy/UnifiedBalanceRuntimeState.php';
require $root . '/economy/UnifiedBalanceMigrationRule.php';
require $root . '/economy/UnifiedEconomyRuntimeSyncService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp21_1TournamentReservationRuntimeCompatibilityTest requires pdo_sqlite.');
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$db = new PdoDatabaseConnection($pdo);

$db->execute('CREATE TABLE mgw_users (
    mgw_id TEXT NOT NULL PRIMARY KEY,
    status TEXT NOT NULL
)');
$db->execute('CREATE TABLE mgw_account_ownership (
    account_ref TEXT NOT NULL PRIMARY KEY,
    mgw_id TEXT NOT NULL,
    legacy_user_id TEXT NOT NULL UNIQUE,
    ownership_status TEXT NOT NULL
)');

(require $root . '/database/migrations/20260717_0005_create_balances_ledger_reservations.php')->up($db);

$mgwId = 'MGW-0000000000000021';
$legacyUserId = '2101';
$accountRef = 'legacy:' . $legacyUserId;
$db->execute(
    'INSERT INTO mgw_users (mgw_id,status) VALUES (:mgw_id,:status)',
    ['mgw_id'=>$mgwId,'status'=>'active']
);
$db->execute(
    'INSERT INTO mgw_account_ownership (account_ref,mgw_id,legacy_user_id,ownership_status)
     VALUES (:account_ref,:mgw_id,:legacy_user_id,:status)',
    [
        'account_ref'=>$accountRef,
        'mgw_id'=>$mgwId,
        'legacy_user_id'=>$legacyUserId,
        'status'=>'active',
    ]
);

$clock = '2026-09-20 14:00:00.000000';
$ledger = new LedgerWriteService($db, static function () use (&$clock): string {
    return $clock;
});
$integrity = new LedgerIntegrityVerifier($db);

$ledger->postAvailableDelta([
    'operation_key'=>'mvp21:runtime:grant',
    'account_ref'=>$accountRef,
    'mgw_id'=>$mgwId,
    'legacy_user_id'=>$legacyUserId,
    'asset_code'=>UnifiedBalanceMigrationRule::TARGET_ASSET,
    'available_delta'=>100000,
    'category'=>'test_grant',
    'source_type'=>'test',
]);

$clock = '2026-09-20 14:01:00.000000';
$reservation = $ledger->createReservation([
    'operation_key'=>'mvp21:runtime:tournament-reserve',
    'account_ref'=>$accountRef,
    'mgw_id'=>$mgwId,
    'legacy_user_id'=>$legacyUserId,
    'asset_code'=>UnifiedBalanceMigrationRule::TARGET_ASSET,
    'amount'=>50000,
    'source_type'=>'official_tournament',
    'source_ref'=>'tour-runtime-compat',
]);

$balance = $ledger->getBalance($accountRef, UnifiedBalanceMigrationRule::TARGET_ASSET);
$assertSame(50000, $balance['available_amount'], 'Tournament hold must reduce only spendable balance.');
$assertSame(50000, $balance['reserved_amount'], 'Tournament hold must remain in canonical reserved balance.');

$snapshot = [
    'users'=>[
        $legacyUserId=>[
            'id'=>$legacyUserId,
            UnifiedBalanceRuntimeState::FIELD=>50000,
        ],
    ],
    'transactions'=>[],
];

$sync = new UnifiedEconomyRuntimeSyncService($db, $ledger, $integrity);
$preview = $sync->preview($snapshot);
$assertSame(true, $preview['ok'], 'Unified runtime preview must accept an active canonical reservation.');
$assertSame(true, $preview['reconciled'], 'Spendable runtime balance must already match available DB balance.');
$assertSame(0, $preview['planned_delta_count'], 'Reservation itself must not be mistaken for runtime drift.');
$assertSame(50000, $preview['database_reserved_total'], 'Runtime preview must surface the held tournament amount.');

$run = $sync->run($snapshot);
$assertSame(true, $run['ok'], 'Unified runtime sync must remain healthy while tournament entry is reserved.');
$assertSame(0, $run['applied_delta_count'], 'Healthy reservation parity must not create a fake ledger delta.');
$assertSame(50000, $run['database_reserved_total'], 'Runtime sync report must retain reserved total visibility.');

$clock = '2026-09-20 14:02:00.000000';
// Simulate a normal match entry while the tournament hold remains active.
// Runtime state changes only the spendable amount; the reservation is untouched.
$snapshot['users'][$legacyUserId][UnifiedBalanceRuntimeState::FIELD] = 49900;
$matchRun = $sync->run($snapshot);
$assertSame(1, $matchRun['applied_delta_count'], 'Normal gameplay debit must still project with a tournament hold active.');
$assertSame(100, $matchRun['debited_total'], 'Only the normal match delta may be debited.');

$afterMatch = $ledger->getBalance($accountRef, UnifiedBalanceMigrationRule::TARGET_ASSET);
$assertSame(49900, $afterMatch['available_amount'], 'Normal match debit must reduce spendable amount.');
$assertSame(50000, $afterMatch['reserved_amount'], 'Normal match debit must not touch tournament reserve.');

$verification = $integrity->verifyAccountAsset($accountRef, UnifiedBalanceMigrationRule::TARGET_ASSET);
$assertSame(true, $verification['ok'], 'Ledger chain must remain valid with gameplay plus active tournament reservation.');

$clock = '2026-09-20 14:03:00.000000';
$ledger->releaseReservation([
    'operation_key'=>'mvp21:runtime:tournament-release',
    'reservation_id'=>$reservation['reservation_id'],
    'metadata'=>['reason'=>'participant_left_before_full'],
]);
$snapshot['users'][$legacyUserId][UnifiedBalanceRuntimeState::FIELD] = 99900;

$releasePreview = $sync->preview($snapshot);
$assertSame(true, $releasePreview['ok'], 'Released tournament hold must restore clean runtime parity.');
$assertSame(0, $releasePreview['planned_delta_count'], 'Released hold must not create a second synthetic balance delta.');
$assertSame(0, $releasePreview['database_reserved_total'], 'Released tournament hold must clear reserved total.');

$finalBalance = $ledger->getBalance($accountRef, UnifiedBalanceMigrationRule::TARGET_ASSET);
$assertSame(99900, $finalBalance['available_amount'], 'Release must restore tournament entry while preserving the normal match spend.');
$assertSame(0, $finalBalance['reserved_amount'], 'Release must clear the held tournament entry.');

$assertTrue($assertions >= 18, 'MVP-21.1 runtime compatibility coverage must remain substantial.');
fwrite(STDOUT, "Mvp21_1TournamentReservationRuntimeCompatibilityTest: {$assertions} assertions passed\n");
