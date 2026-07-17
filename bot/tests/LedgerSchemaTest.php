<?php
declare(strict_types=1);

$databaseDir = dirname(__DIR__) . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('LedgerSchemaTest requires pdo_sqlite.');
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
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$assertSame(5, $runner->migrate(false)['executed_count'], 'Ledger schema test must include five migrations');
$assertSame(0, $runner->migrate(false)['executed_count'], 'Repeated migration run must be idempotent');

foreach ([
    'mgw_balances',
    'mgw_idempotency_keys',
    'mgw_reservations',
    'mgw_ledger_entries',
    'mgw_reservation_events',
] as $table) {
    $assertSame(
        $table,
        (string)$database->fetchValue(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name",
            ['name' => $table]
        ),
        $table . ' must exist'
    );
}

$now = '2026-07-17 12:00:00.000000';
$database->execute(
    'INSERT INTO mgw_users (
        mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
     ) VALUES (
        :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
     )',
    [
        'mgw_id' => 'mgw_ledger_user',
        'status' => 'active',
        'display_name' => 'Ledger User',
        'created_at' => $now,
        'updated_at' => $now,
        'last_seen_at' => $now,
    ]
);

$balanceParameters = static fn(string $asset, int $available): array => [
    'account_ref' => 'mgw:mgw_ledger_user',
    'mgw_id' => 'mgw_ledger_user',
    'legacy_user_id' => '7001',
    'asset_code' => $asset,
    'available_amount' => $available,
    'reserved_amount' => 0,
    'version' => 1,
    'created_at' => $now,
    'updated_at' => $now,
];
foreach ([['match_coin', 50], ['gold_coin', 25]] as [$asset, $available]) {
    $database->execute(
        'INSERT INTO mgw_balances (
            account_ref, mgw_id, legacy_user_id, asset_code,
            available_amount, reserved_amount, version, created_at_utc, updated_at_utc
         ) VALUES (
            :account_ref, :mgw_id, :legacy_user_id, :asset_code,
            :available_amount, :reserved_amount, :version, :created_at, :updated_at
         )',
        $balanceParameters($asset, $available)
    );
}
$assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_balances'), 'Match and Gold balances must remain separate rows');
$assertSame(
    ['gold_coin', 'match_coin'],
    array_column($database->fetchAll('SELECT asset_code FROM mgw_balances ORDER BY asset_code'), 'asset_code'),
    'Legacy asset codes must remain distinct'
);
$assertThrows(
    static fn() => $database->execute(
        'INSERT INTO mgw_balances (
            account_ref, mgw_id, legacy_user_id, asset_code,
            available_amount, reserved_amount, version, created_at_utc, updated_at_utc
         ) VALUES (
            :account_ref, :mgw_id, :legacy_user_id, :asset_code,
            :available_amount, :reserved_amount, :version, :created_at, :updated_at
         )',
        $balanceParameters('match_coin', 999)
    ),
    'unique',
    'One account may have only one row per asset'
);
$negativeBalance = $balanceParameters('future_coin', -1);
$assertThrows(
    static fn() => $database->execute(
        'INSERT INTO mgw_balances (
            account_ref, mgw_id, legacy_user_id, asset_code,
            available_amount, reserved_amount, version, created_at_utc, updated_at_utc
         ) VALUES (
            :account_ref, :mgw_id, :legacy_user_id, :asset_code,
            :available_amount, :reserved_amount, :version, :created_at, :updated_at
         )',
        $negativeBalance
    ),
    'check',
    'Balances must never be negative'
);

$database->execute(
    'INSERT INTO mgw_idempotency_keys (
        operation_key, operation_type, owner_ref, request_sha256,
        status, result_json, created_at_utc, updated_at_utc, expires_at_utc
     ) VALUES (
        :operation_key, :operation_type, :owner_ref, :request_sha256,
        :status, NULL, :created_at, :updated_at, NULL
     )',
    [
        'operation_key' => 'reserve:match:ledger-1',
        'operation_type' => 'match_reservation',
        'owner_ref' => 'mgw:mgw_ledger_user',
        'request_sha256' => hash('sha256', 'reserve-request-1'),
        'status' => 'started',
        'created_at' => $now,
        'updated_at' => $now,
    ]
);
$assertThrows(
    static fn() => $database->execute(
        'INSERT INTO mgw_idempotency_keys (
            operation_key, operation_type, owner_ref, request_sha256,
            status, result_json, created_at_utc, updated_at_utc, expires_at_utc
         ) VALUES (
            :operation_key, :operation_type, :owner_ref, :request_sha256,
            :status, NULL, :created_at, :updated_at, NULL
         )',
        [
            'operation_key' => 'reserve:match:ledger-1',
            'operation_type' => 'different_operation',
            'owner_ref' => 'mgw:mgw_ledger_user',
            'request_sha256' => hash('sha256', 'different-request'),
            'status' => 'started',
            'created_at' => $now,
            'updated_at' => $now,
        ]
    ),
    'unique',
    'An idempotency operation key may only be claimed once'
);

$database->execute(
    'INSERT INTO mgw_reservations (
        reservation_id, idempotency_key, account_ref, mgw_id, legacy_user_id,
        asset_code, amount, status, source_type, source_ref, metadata_json,
        created_at_utc, updated_at_utc, expires_at_utc, consumed_at_utc, released_at_utc
     ) VALUES (
        :reservation_id, :idempotency_key, :account_ref, :mgw_id, :legacy_user_id,
        :asset_code, :amount, :status, :source_type, :source_ref, :metadata_json,
        :created_at, :updated_at, :expires_at, NULL, NULL
     )',
    [
        'reservation_id' => 'reservation-ledger-1',
        'idempotency_key' => 'reserve:match:ledger-1',
        'account_ref' => 'mgw:mgw_ledger_user',
        'mgw_id' => 'mgw_ledger_user',
        'legacy_user_id' => '7001',
        'asset_code' => 'match_coin',
        'amount' => 10,
        'status' => 'active',
        'source_type' => 'legacy_match',
        'source_ref' => 'game-ledger-1',
        'metadata_json' => '{"room":"match"}',
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => '2026-07-17 12:05:00.000000',
    ]
);
$assertThrows(
    static fn() => $database->execute(
        'INSERT INTO mgw_reservations (
            reservation_id, idempotency_key, account_ref, asset_code, amount,
            status, source_type, created_at_utc, updated_at_utc
         ) VALUES (
            :reservation_id, :idempotency_key, :account_ref, :asset_code, :amount,
            :status, :source_type, :created_at, :updated_at
         )',
        [
            'reservation_id' => 'reservation-invalid-zero',
            'idempotency_key' => 'reserve:invalid-zero',
            'account_ref' => 'mgw:mgw_ledger_user',
            'asset_code' => 'match_coin',
            'amount' => 0,
            'status' => 'active',
            'source_type' => 'test',
            'created_at' => $now,
            'updated_at' => $now,
        ]
    ),
    'check',
    'Reservation amount must be positive'
);

$database->execute(
    'INSERT INTO mgw_reservation_events (
        event_id, reservation_id, event_key, event_type,
        available_delta, reserved_delta, metadata_json, created_at_utc
     ) VALUES (
        :event_id, :reservation_id, :event_key, :event_type,
        :available_delta, :reserved_delta, :metadata_json, :created_at
     )',
    [
        'event_id' => 'reservation-event-ledger-1',
        'reservation_id' => 'reservation-ledger-1',
        'event_key' => 'created',
        'event_type' => 'created',
        'available_delta' => -10,
        'reserved_delta' => 10,
        'metadata_json' => '{"reason":"match_entry"}',
        'created_at' => $now,
    ]
);

$database->execute(
    'INSERT INTO mgw_ledger_entries (
        entry_id, idempotency_key, account_ref, mgw_id, legacy_user_id, asset_code,
        available_delta, reserved_delta, available_before, available_after,
        reserved_before, reserved_after, category, source_type, source_ref,
        reservation_id, metadata_json, created_at_utc
     ) VALUES (
        :entry_id, :idempotency_key, :account_ref, :mgw_id, :legacy_user_id, :asset_code,
        :available_delta, :reserved_delta, :available_before, :available_after,
        :reserved_before, :reserved_after, :category, :source_type, :source_ref,
        :reservation_id, :metadata_json, :created_at
     )',
    [
        'entry_id' => 'ledger-entry-1',
        'idempotency_key' => 'ledger:reserve:match:ledger-1',
        'account_ref' => 'mgw:mgw_ledger_user',
        'mgw_id' => 'mgw_ledger_user',
        'legacy_user_id' => '7001',
        'asset_code' => 'match_coin',
        'available_delta' => -10,
        'reserved_delta' => 10,
        'available_before' => 50,
        'available_after' => 40,
        'reserved_before' => 0,
        'reserved_after' => 10,
        'category' => 'reservation_created',
        'source_type' => 'legacy_match',
        'source_ref' => 'game-ledger-1',
        'reservation_id' => 'reservation-ledger-1',
        'metadata_json' => '{"legacy":true}',
        'created_at' => $now,
    ]
);
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries'), 'Valid ledger entry must be stored');
$assertThrows(
    static fn() => $database->execute(
        'INSERT INTO mgw_ledger_entries (
            entry_id, idempotency_key, account_ref, asset_code,
            available_delta, reserved_delta, available_before, available_after,
            reserved_before, reserved_after, category, source_type, created_at_utc
         ) VALUES (
            :entry_id, :idempotency_key, :account_ref, :asset_code,
            :available_delta, :reserved_delta, :available_before, :available_after,
            :reserved_before, :reserved_after, :category, :source_type, :created_at
         )',
        [
            'entry_id' => 'ledger-entry-invalid-math',
            'idempotency_key' => 'ledger:invalid-math',
            'account_ref' => 'mgw:mgw_ledger_user',
            'asset_code' => 'match_coin',
            'available_delta' => -10,
            'reserved_delta' => 10,
            'available_before' => 50,
            'available_after' => 49,
            'reserved_before' => 0,
            'reserved_after' => 10,
            'category' => 'invalid',
            'source_type' => 'test',
            'created_at' => $now,
        ]
    ),
    'check',
    'Ledger before, delta and after amounts must reconcile'
);

$assertThrows(
    static fn() => $database->execute(
        'UPDATE mgw_ledger_entries SET category = :category WHERE entry_id = :entry_id',
        ['category' => 'tampered', 'entry_id' => 'ledger-entry-1']
    ),
    'immutable',
    'Ledger entries must reject updates'
);
$assertThrows(
    static fn() => $database->execute(
        'DELETE FROM mgw_ledger_entries WHERE entry_id = :entry_id',
        ['entry_id' => 'ledger-entry-1']
    ),
    'immutable',
    'Ledger entries must reject deletes'
);
$assertThrows(
    static fn() => $database->execute(
        'UPDATE mgw_reservation_events SET event_type = :event_type WHERE event_id = :event_id',
        ['event_type' => 'tampered', 'event_id' => 'reservation-event-ledger-1']
    ),
    'immutable',
    'Reservation events must reject updates'
);
$assertThrows(
    static fn() => $database->execute(
        'DELETE FROM mgw_reservation_events WHERE event_id = :event_id',
        ['event_id' => 'reservation-event-ledger-1']
    ),
    'immutable',
    'Reservation events must reject deletes'
);
$assertThrows(
    static fn() => $database->execute(
        'DELETE FROM mgw_reservations WHERE reservation_id = :reservation_id',
        ['reservation_id' => 'reservation-ledger-1']
    ),
    'released or consumed',
    'Reservations must not disappear from the audit trail'
);

$database->execute(
    'UPDATE mgw_reservations
     SET status = :status, updated_at_utc = :updated_at, released_at_utc = :released_at
     WHERE reservation_id = :reservation_id',
    [
        'status' => 'released',
        'updated_at' => '2026-07-17 12:01:00.000000',
        'released_at' => '2026-07-17 12:01:00.000000',
        'reservation_id' => 'reservation-ledger-1',
    ]
);
$assertSame('released', (string)$database->fetchValue(
    'SELECT status FROM mgw_reservations WHERE reservation_id = :reservation_id',
    ['reservation_id' => 'reservation-ledger-1']
), 'Reservation status transitions must remain possible');

$assertTrue(
    (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries') === 1
    && (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_reservation_events') === 1,
    'Failed mutations must leave immutable audit rows intact'
);

fwrite(STDOUT, "LedgerSchemaTest: {$assertions} assertions passed\n");
