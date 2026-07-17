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

foreach (['mgw_balances', 'mgw_idempotency_keys', 'mgw_reservations', 'mgw_ledger_entries', 'mgw_reservation_events'] as $table) {
    $assertSame(
        $table,
        (string)$database->fetchValue(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name",
            ['name' => $table]
        ),
        $table . ' must exist'
    );
}

$ledgerColumns = array_column($database->fetchAll('PRAGMA table_info(mgw_ledger_entries)'), 'name');
$eventColumns = array_column($database->fetchAll('PRAGMA table_info(mgw_reservation_events)'), 'name');
$assertTrue(in_array('entry_sha256', $ledgerColumns, true), 'Ledger rows must carry an integrity hash');
$assertTrue(in_array('previous_entry_sha256', $ledgerColumns, true), 'Ledger rows must support hash chaining');
$assertTrue(!in_array('updated_at_utc', $ledgerColumns, true), 'Ledger entries must not have a mutable update timestamp');
$assertTrue(in_array('event_sha256', $eventColumns, true), 'Reservation events must carry an integrity hash');
$assertTrue(!in_array('updated_at_utc', $eventColumns, true), 'Reservation events must not have a mutable update timestamp');

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

$insertBalance = static function (string $asset, int $available) use ($database, $now): void {
    $database->execute(
        'INSERT INTO mgw_balances (
            account_ref, mgw_id, legacy_user_id, asset_code,
            available_amount, reserved_amount, version, created_at_utc, updated_at_utc
         ) VALUES (
            :account_ref, :mgw_id, :legacy_user_id, :asset_code,
            :available_amount, 0, 1, :created_at, :updated_at
         )',
        [
            'account_ref' => 'mgw:mgw_ledger_user',
            'mgw_id' => 'mgw_ledger_user',
            'legacy_user_id' => '7001',
            'asset_code' => $asset,
            'available_amount' => $available,
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );
};
$insertBalance('match_coin', 50);
$insertBalance('gold_coin', 25);
$assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_balances'), 'Match and Gold must remain separate balances');
$assertSame(
    ['gold_coin', 'match_coin'],
    array_column($database->fetchAll('SELECT asset_code FROM mgw_balances ORDER BY asset_code'), 'asset_code'),
    'Legacy asset codes must remain distinct'
);
$assertThrows(static fn() => $insertBalance('match_coin', 999), 'unique', 'One account may have only one balance per asset');
$assertThrows(static fn() => $insertBalance('future_coin', -1), 'check', 'Balances must reject negative amounts');

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
            status, created_at_utc, updated_at_utc
         ) VALUES (
            :operation_key, :operation_type, :owner_ref, :request_sha256,
            :status, :created_at, :updated_at
         )',
        [
            'operation_key' => 'reserve:match:ledger-1',
            'operation_type' => 'other',
            'owner_ref' => 'mgw:mgw_ledger_user',
            'request_sha256' => hash('sha256', 'different'),
            'status' => 'started',
            'created_at' => $now,
            'updated_at' => $now,
        ]
    ),
    'unique',
    'An operation key may only be claimed once'
);

$database->execute(
    'INSERT INTO mgw_reservations (
        reservation_id, idempotency_key, account_ref, mgw_id, legacy_user_id,
        asset_code, amount, status, source_type, source_ref, metadata_json,
        created_at_utc, updated_at_utc, expires_at_utc
     ) VALUES (
        :reservation_id, :idempotency_key, :account_ref, :mgw_id, :legacy_user_id,
        :asset_code, :amount, :status, :source_type, :source_ref, :metadata_json,
        :created_at, :updated_at, :expires_at
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
            :reservation_id, :idempotency_key, :account_ref, :asset_code, 0,
            :status, :source_type, :created_at, :updated_at
         )',
        [
            'reservation_id' => 'reservation-zero',
            'idempotency_key' => 'reserve:zero',
            'account_ref' => 'mgw:mgw_ledger_user',
            'asset_code' => 'match_coin',
            'status' => 'active',
            'source_type' => 'test',
            'created_at' => $now,
            'updated_at' => $now,
        ]
    ),
    'check',
    'Reservation amount must be positive'
);

$eventHash = hash('sha256', 'reservation-event-ledger-1');
$database->execute(
    'INSERT INTO mgw_reservation_events (
        event_id, reservation_id, event_key, event_type,
        available_delta, reserved_delta, metadata_json, event_sha256, created_at_utc
     ) VALUES (
        :event_id, :reservation_id, :event_key, :event_type,
        -10, 10, :metadata_json, :event_sha256, :created_at
     )',
    [
        'event_id' => 'reservation-event-ledger-1',
        'reservation_id' => 'reservation-ledger-1',
        'event_key' => 'created',
        'event_type' => 'created',
        'metadata_json' => '{"reason":"match_entry"}',
        'event_sha256' => $eventHash,
        'created_at' => $now,
    ]
);
$assertSame($eventHash, (string)$database->fetchValue(
    'SELECT event_sha256 FROM mgw_reservation_events WHERE event_id = :event_id',
    ['event_id' => 'reservation-event-ledger-1']
), 'Reservation event integrity hash must round-trip');
$assertThrows(
    static fn() => $database->execute(
        'INSERT INTO mgw_reservation_events (
            event_id, reservation_id, event_key, event_type, event_sha256, created_at_utc
         ) VALUES (
            :event_id, :reservation_id, :event_key, :event_type, :event_sha256, :created_at
         )',
        [
            'event_id' => 'reservation-event-duplicate-key',
            'reservation_id' => 'reservation-ledger-1',
            'event_key' => 'created',
            'event_type' => 'duplicate',
            'event_sha256' => hash('sha256', 'duplicate'),
            'created_at' => $now,
        ]
    ),
    'unique',
    'Reservation event keys must be idempotent'
);

$entryHash = hash('sha256', 'ledger-entry-1');
$database->execute(
    'INSERT INTO mgw_ledger_entries (
        entry_id, idempotency_key, account_ref, mgw_id, legacy_user_id, asset_code,
        available_delta, reserved_delta, available_before, available_after,
        reserved_before, reserved_after, category, source_type, source_ref,
        reservation_id, metadata_json, previous_entry_sha256, entry_sha256, created_at_utc
     ) VALUES (
        :entry_id, :idempotency_key, :account_ref, :mgw_id, :legacy_user_id, :asset_code,
        -10, 10, 50, 40, 0, 10, :category, :source_type, :source_ref,
        :reservation_id, :metadata_json, NULL, :entry_sha256, :created_at
     )',
    [
        'entry_id' => 'ledger-entry-1',
        'idempotency_key' => 'ledger:reserve:match:ledger-1',
        'account_ref' => 'mgw:mgw_ledger_user',
        'mgw_id' => 'mgw_ledger_user',
        'legacy_user_id' => '7001',
        'asset_code' => 'match_coin',
        'category' => 'reservation_created',
        'source_type' => 'legacy_match',
        'source_ref' => 'game-ledger-1',
        'reservation_id' => 'reservation-ledger-1',
        'metadata_json' => '{"legacy":true}',
        'entry_sha256' => $entryHash,
        'created_at' => $now,
    ]
);
$assertSame($entryHash, (string)$database->fetchValue(
    'SELECT entry_sha256 FROM mgw_ledger_entries WHERE entry_id = :entry_id',
    ['entry_id' => 'ledger-entry-1']
), 'Ledger integrity hash must round-trip');
$assertThrows(
    static fn() => $database->execute(
        'INSERT INTO mgw_ledger_entries (
            entry_id, idempotency_key, account_ref, asset_code,
            available_delta, reserved_delta, available_before, available_after,
            reserved_before, reserved_after, category, source_type, entry_sha256, created_at_utc
         ) VALUES (
            :entry_id, :idempotency_key, :account_ref, :asset_code,
            -10, 10, 50, 49, 0, 10, :category, :source_type, :entry_sha256, :created_at
         )',
        [
            'entry_id' => 'ledger-entry-invalid-math',
            'idempotency_key' => 'ledger:invalid-math',
            'account_ref' => 'mgw:mgw_ledger_user',
            'asset_code' => 'match_coin',
            'category' => 'invalid',
            'source_type' => 'test',
            'entry_sha256' => hash('sha256', 'invalid'),
            'created_at' => $now,
        ]
    ),
    'check',
    'Ledger before, delta and after amounts must reconcile'
);
$assertThrows(
    static fn() => $database->execute(
        'INSERT INTO mgw_ledger_entries (
            entry_id, idempotency_key, account_ref, asset_code,
            available_before, available_after, reserved_before, reserved_after,
            category, source_type, entry_sha256, created_at_utc
         ) VALUES (
            :entry_id, :idempotency_key, :account_ref, :asset_code,
            40, 40, 10, 10, :category, :source_type, :entry_sha256, :created_at
         )',
        [
            'entry_id' => 'ledger-entry-duplicate-idempotency',
            'idempotency_key' => 'ledger:reserve:match:ledger-1',
            'account_ref' => 'mgw:mgw_ledger_user',
            'asset_code' => 'match_coin',
            'category' => 'duplicate',
            'source_type' => 'test',
            'entry_sha256' => hash('sha256', 'duplicate-ledger'),
            'created_at' => $now,
        ]
    ),
    'unique',
    'Ledger idempotency keys must prevent duplicate postings'
);

$assertThrows(
    static fn() => $database->execute(
        'DELETE FROM mgw_reservations WHERE reservation_id = :reservation_id',
        ['reservation_id' => 'reservation-ledger-1']
    ),
    'foreign key',
    'Linked reservations must remain in the audit trail'
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

fwrite(STDOUT, "LedgerSchemaTest: {$assertions} assertions passed\n");
