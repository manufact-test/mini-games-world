<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/database/MigrationRepository.php';
require $root . '/database/MigrationRunner.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/ledger/LedgerIntegrityVerifier.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('LedgerWriteServiceTest requires pdo_sqlite.');
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
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $root . '/database/migrations');
$assertSame(6, $runner->migrate(false)['executed_count'], 'Ledger write test must create all schemas');

$mgwId = 'MGW-0123456789ABCDEF';
$now = '2026-07-17 13:00:00.000000';
$database->execute(
    'INSERT INTO mgw_users (
        mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
     ) VALUES (
        :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
     )',
    [
        'mgw_id' => $mgwId,
        'status' => 'active',
        'display_name' => 'Ledger Test',
        'created_at' => $now,
        'updated_at' => $now,
        'last_seen_at' => $now,
    ]
);

$clockValue = $now;
$service = new LedgerWriteService($database, static function () use (&$clockValue): string {
    return $clockValue;
});
$verifier = new LedgerIntegrityVerifier($database);
$accountRef = 'mgw:' . $mgwId;

$grant = $service->postAvailableDelta([
    'operation_key' => 'test:grant:match:1',
    'account_ref' => $accountRef,
    'asset_code' => 'match_coin',
    'available_delta' => 100,
    'category' => 'legacy_grant',
    'source_type' => 'test',
    'source_ref' => 'grant-1',
    'metadata' => ['b' => 2, 'a' => 1],
]);
$assertSame(false, $grant['replayed'], 'First grant must execute');
$assertSame(100, $grant['balance']['available_amount'], 'Grant must increase available balance');
$assertSame(0, $grant['balance']['reserved_amount'], 'Grant must not reserve funds');
$assertSame(1, $grant['balance']['version'], 'First grant must increment balance version');

$grantReplay = $service->postAvailableDelta([
    'operation_key' => 'test:grant:match:1',
    'account_ref' => $accountRef,
    'asset_code' => 'match_coin',
    'available_delta' => 100,
    'category' => 'legacy_grant',
    'source_type' => 'test',
    'source_ref' => 'grant-1',
    'metadata' => ['a' => 1, 'b' => 2],
]);
$assertSame(true, $grantReplay['replayed'], 'Repeated identical grant must replay');
$assertSame($grant['entry_id'], $grantReplay['entry_id'], 'Replay must return the original ledger entry');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries'), 'Replay must not duplicate ledger entries');
$assertThrows(
    static fn() => $service->postAvailableDelta([
        'operation_key' => 'test:grant:match:1',
        'account_ref' => $accountRef,
        'asset_code' => 'match_coin',
        'available_delta' => 101,
        'category' => 'legacy_grant',
        'source_type' => 'test',
    ]),
    'different input',
    'Reusing an operation key with different input must fail closed'
);

$assertThrows(
    static fn() => $service->postAvailableDelta([
        'operation_key' => 'test:overspend:1',
        'account_ref' => $accountRef,
        'asset_code' => 'match_coin',
        'available_delta' => -101,
        'category' => 'test_debit',
        'source_type' => 'test',
    ]),
    'insufficient',
    'Overspend must fail'
);
$assertSame(0, (int)$database->fetchValue(
    'SELECT COUNT(*) FROM mgw_idempotency_keys WHERE operation_key = :operation_key',
    ['operation_key' => 'test:overspend:1']
), 'Failed overspend must roll back its idempotency claim');
$assertSame(100, $service->getBalance($accountRef, 'match_coin')['available_amount'], 'Failed overspend must not change balance');

$gold = $service->postAvailableDelta([
    'operation_key' => 'test:grant:gold:1',
    'account_ref' => $accountRef,
    'asset_code' => 'gold_coin',
    'available_delta' => 25,
    'category' => 'legacy_grant',
    'source_type' => 'test',
]);
$assertSame(25, $gold['balance']['available_amount'], 'Gold balance must remain separate');
$assertSame(100, $service->getBalance($accountRef, 'match_coin')['available_amount'], 'Gold write must not change Match balance');

$clockValue = '2026-07-17 13:01:00.000000';
$reservation = $service->createReservation([
    'operation_key' => 'test:reserve:match:1',
    'account_ref' => $accountRef,
    'asset_code' => 'match_coin',
    'amount' => 40,
    'source_type' => 'legacy_match',
    'source_ref' => 'game-1',
    'metadata' => ['room' => 'match'],
    'expires_at_utc' => '2026-07-17 13:06:00+00:00',
]);
$assertSame('active', $reservation['reservation_status'], 'Reservation must become active');
$assertSame(60, $reservation['balance']['available_amount'], 'Reservation must move funds out of available');
$assertSame(40, $reservation['balance']['reserved_amount'], 'Reservation must move funds into reserved');
$assertSame(2, (int)$database->fetchValue(
    'SELECT COUNT(*) FROM mgw_ledger_entries WHERE account_ref = :account_ref AND asset_code = :asset_code',
    ['account_ref' => $accountRef, 'asset_code' => 'match_coin']
), 'Reservation must append one ledger entry');

$reservationReplay = $service->createReservation([
    'operation_key' => 'test:reserve:match:1',
    'account_ref' => $accountRef,
    'asset_code' => 'match_coin',
    'amount' => 40,
    'source_type' => 'legacy_match',
    'source_ref' => 'game-1',
    'metadata' => ['room' => 'match'],
    'expires_at_utc' => '2026-07-17 13:06:00+00:00',
]);
$assertSame(true, $reservationReplay['replayed'], 'Repeated reservation must replay');
$assertSame($reservation['reservation_id'], $reservationReplay['reservation_id'], 'Reservation replay must keep the original ID');

$clockValue = '2026-07-17 13:02:00.000000';
$released = $service->releaseReservation([
    'operation_key' => 'test:release:match:1',
    'reservation_id' => $reservation['reservation_id'],
    'metadata' => ['reason' => 'match_cancelled'],
]);
$assertSame('released', $released['reservation_status'], 'Release must close the reservation');
$assertSame(100, $released['balance']['available_amount'], 'Release must return funds to available');
$assertSame(0, $released['balance']['reserved_amount'], 'Release must clear reserved funds');
$releaseReplay = $service->releaseReservation([
    'operation_key' => 'test:release:match:1',
    'reservation_id' => $reservation['reservation_id'],
    'metadata' => ['reason' => 'match_cancelled'],
]);
$assertSame(true, $releaseReplay['replayed'], 'Repeated release must replay after reservation is closed');

$clockValue = '2026-07-17 13:03:00.000000';
$reservationTwo = $service->createReservation([
    'operation_key' => 'test:reserve:match:2',
    'account_ref' => $accountRef,
    'asset_code' => 'match_coin',
    'amount' => 30,
    'source_type' => 'legacy_match',
    'source_ref' => 'game-2',
]);
$clockValue = '2026-07-17 13:04:00.000000';
$consumed = $service->consumeReservation([
    'operation_key' => 'test:consume:match:2',
    'reservation_id' => $reservationTwo['reservation_id'],
    'metadata' => ['reason' => 'match_started'],
]);
$assertSame('consumed', $consumed['reservation_status'], 'Consume must close the reservation');
$assertSame(70, $consumed['balance']['available_amount'], 'Consumed reservation must remain deducted');
$assertSame(0, $consumed['balance']['reserved_amount'], 'Consumed reservation must clear reserved funds');

$matchVerification = $verifier->verifyAccountAsset($accountRef, 'match_coin');
$assertSame(true, $matchVerification['ok'], 'Healthy Match ledger chain must verify');
$assertSame(5, $matchVerification['entry_count'], 'Match chain must contain grant, two reserves and two finishes');
$assertSame(70, $matchVerification['balance']['available_amount'], 'Verifier must report current Match balance');
$goldVerification = $verifier->verifyAccountAsset($accountRef, 'gold_coin');
$assertSame(true, $goldVerification['ok'], 'Healthy Gold ledger chain must verify independently');
$assertSame(1, $goldVerification['entry_count'], 'Gold chain must contain only its own grant');

$reservationVerification = $verifier->verifyReservation($reservation['reservation_id']);
$assertSame(true, $reservationVerification['ok'], 'Released reservation events must verify');
$assertSame(2, $reservationVerification['event_count'], 'Released reservation must have created and released events');
$reservationTwoVerification = $verifier->verifyReservation($reservationTwo['reservation_id']);
$assertSame(true, $reservationTwoVerification['ok'], 'Consumed reservation events must verify');
$assertSame(2, $reservationTwoVerification['event_count'], 'Consumed reservation must have created and consumed events');

$firstEntryId = (string)$database->fetchValue(
    'SELECT entry_id FROM mgw_ledger_entries
     WHERE account_ref = :account_ref AND asset_code = :asset_code
     ORDER BY ledger_sequence LIMIT 1',
    ['account_ref' => $accountRef, 'asset_code' => 'match_coin']
);
$database->execute(
    'UPDATE mgw_ledger_entries SET metadata_json = :metadata_json WHERE entry_id = :entry_id',
    ['metadata_json' => '{"tampered":true}', 'entry_id' => $firstEntryId]
);
$tampered = $verifier->verifyAccountAsset($accountRef, 'match_coin');
$assertSame(false, $tampered['ok'], 'Tampered ledger chain must fail verification');
$assertTrue(
    in_array('entry_hash_mismatch', array_column($tampered['errors'], 'code'), true),
    'Tampered ledger chain must report an entry hash mismatch'
);

fwrite(STDOUT, "LedgerWriteServiceTest: {$assertions} assertions passed\n");
