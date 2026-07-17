<?php
declare(strict_types=1);

$databaseDir = dirname(__DIR__) . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/DatabaseConfig.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/PdoConnectionFactory.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
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

$targets = [
    'MySQL' => [
        'dsn' => trim((string)(getenv('MGW_TEST_MYSQL_DSN') ?: '')),
        'user' => (string)(getenv('MGW_TEST_MYSQL_USER') ?: ''),
        'password' => (string)(getenv('MGW_TEST_MYSQL_PASSWORD') ?: ''),
        'driver' => 'mysql',
    ],
    'MariaDB' => [
        'dsn' => trim((string)(getenv('MGW_TEST_MARIADB_DSN') ?: '')),
        'user' => (string)(getenv('MGW_TEST_MARIADB_USER') ?: ''),
        'password' => (string)(getenv('MGW_TEST_MARIADB_PASSWORD') ?: ''),
        'driver' => 'mariadb',
    ],
];

foreach ($targets as $label => $target) {
    if ($target['dsn'] === '') {
        fwrite(STDOUT, "LedgerSchemaDatabaseIntegrationTest: {$label} skipped.\n");
        continue;
    }

    $dsnValue = static function (string $key) use ($target): string {
        return preg_match('/(?:^|[:;])' . preg_quote($key, '/') . '=([^;]+)/', $target['dsn'], $matches) === 1
            ? trim((string)$matches[1])
            : '';
    };
    $config = DatabaseConfig::fromApplicationConfig([
        'database' => [
            'enabled' => true,
            'driver' => $target['driver'],
            'host' => $dsnValue('host'),
            'port' => (int)($dsnValue('port') !== '' ? $dsnValue('port') : '3306'),
            'name' => $dsnValue('dbname'),
            'user' => $target['user'],
            'password' => $target['password'],
            'charset' => 'utf8mb4',
        ],
    ]);
    $database = PdoConnectionFactory::create($config);
    $cleanup = static function () use ($database): void {
        foreach ([
            'mgw_reservation_events',
            'mgw_ledger_entries',
            'mgw_reservations',
            'mgw_idempotency_keys',
            'mgw_balances',
            'mgw_legacy_realtime_shadow',
            'mgw_notifications',
            'mgw_invite_events',
            'mgw_invites',
            'mgw_match_player_snapshots',
            'mgw_match_snapshots',
            'mgw_match_players',
            'mgw_match_queue',
            'mgw_matches',
            'mgw_sessions',
            'mgw_devices',
            'mgw_identities',
            'mgw_users',
            'mgw_meta',
            'mgw_schema_migrations',
        ] as $table) {
            $database->execute('DROP TABLE IF EXISTS `' . $table . '`');
        }
    };

    $cleanup();
    try {
        $runner = new MigrationRunner($database, $databaseDir . '/migrations');
        $assertSame(5, $runner->migrate(false)['executed_count'], "{$label} must build the ledger schema");

        $now = '2026-07-17 12:00:00.000000';
        $database->execute(
            'INSERT INTO mgw_users (
                mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
             ) VALUES (
                :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
             )',
            [
                'mgw_id' => 'mgw_ledger_db',
                'status' => 'active',
                'display_name' => 'Ledger DB',
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
                    'account_ref' => 'mgw:mgw_ledger_db',
                    'mgw_id' => 'mgw_ledger_db',
                    'legacy_user_id' => '8001',
                    'asset_code' => $asset,
                    'available_amount' => $available,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        };
        $insertBalance('match_coin', 50);
        $insertBalance('gold_coin', 25);
        $assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_balances'), "{$label} must keep Match and Gold separate");
        $assertThrows(static fn() => $insertBalance('match_coin', 100), 'duplicate', "{$label} must reject duplicate account assets");
        $assertThrows(static fn() => $insertBalance('future_coin', -1), 'constraint', "{$label} must reject negative balances");

        $database->execute(
            'INSERT INTO mgw_idempotency_keys (
                operation_key, operation_type, owner_ref, request_sha256,
                status, created_at_utc, updated_at_utc
             ) VALUES (
                :operation_key, :operation_type, :owner_ref, :request_sha256,
                :status, :created_at, :updated_at
             )',
            [
                'operation_key' => 'db:reserve:1',
                'operation_type' => 'match_reservation',
                'owner_ref' => 'mgw:mgw_ledger_db',
                'request_sha256' => hash('sha256', 'db-reserve-1'),
                'status' => 'started',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $assertThrows(
            static fn() => $database->execute(
                'INSERT INTO mgw_idempotency_keys (
                    operation_key, operation_type, request_sha256, status, created_at_utc, updated_at_utc
                 ) VALUES (
                    :operation_key, :operation_type, :request_sha256, :status, :created_at, :updated_at
                 )',
                [
                    'operation_key' => 'db:reserve:1',
                    'operation_type' => 'other',
                    'request_sha256' => hash('sha256', 'different'),
                    'status' => 'started',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            ),
            'duplicate',
            "{$label} must reject duplicate operation keys"
        );

        $database->execute(
            'INSERT INTO mgw_reservations (
                reservation_id, idempotency_key, account_ref, mgw_id, legacy_user_id,
                asset_code, amount, status, source_type, source_ref,
                created_at_utc, updated_at_utc
             ) VALUES (
                :reservation_id, :idempotency_key, :account_ref, :mgw_id, :legacy_user_id,
                :asset_code, :amount, :status, :source_type, :source_ref,
                :created_at, :updated_at
             )',
            [
                'reservation_id' => 'db-reservation-1',
                'idempotency_key' => 'db:reserve:1',
                'account_ref' => 'mgw:mgw_ledger_db',
                'mgw_id' => 'mgw_ledger_db',
                'legacy_user_id' => '8001',
                'asset_code' => 'match_coin',
                'amount' => 10,
                'status' => 'active',
                'source_type' => 'legacy_match',
                'source_ref' => 'db-game-1',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $assertThrows(
            static fn() => $database->execute(
                'INSERT INTO mgw_reservations (
                    reservation_id, idempotency_key, account_ref, asset_code,
                    amount, status, source_type, created_at_utc, updated_at_utc
                 ) VALUES (
                    :reservation_id, :idempotency_key, :account_ref, :asset_code,
                    0, :status, :source_type, :created_at, :updated_at
                 )',
                [
                    'reservation_id' => 'db-reservation-zero',
                    'idempotency_key' => 'db:reserve:zero',
                    'account_ref' => 'mgw:mgw_ledger_db',
                    'asset_code' => 'match_coin',
                    'status' => 'active',
                    'source_type' => 'test',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            ),
            'constraint',
            "{$label} must reject zero reservations"
        );

        $eventHash = hash('sha256', $label . ':reservation-event');
        $database->execute(
            'INSERT INTO mgw_reservation_events (
                event_id, reservation_id, event_key, event_type,
                available_delta, reserved_delta, event_sha256, created_at_utc
             ) VALUES (
                :event_id, :reservation_id, :event_key, :event_type,
                -10, 10, :event_sha256, :created_at
             )',
            [
                'event_id' => 'db-reservation-event-1',
                'reservation_id' => 'db-reservation-1',
                'event_key' => 'created',
                'event_type' => 'created',
                'event_sha256' => $eventHash,
                'created_at' => $now,
            ]
        );
        $assertSame($eventHash, (string)$database->fetchValue(
            'SELECT event_sha256 FROM mgw_reservation_events WHERE event_id = :event_id',
            ['event_id' => 'db-reservation-event-1']
        ), "{$label} reservation event hash must round-trip");

        $entryHash = hash('sha256', $label . ':ledger-entry');
        $database->execute(
            'INSERT INTO mgw_ledger_entries (
                entry_id, idempotency_key, account_ref, mgw_id, legacy_user_id, asset_code,
                available_delta, reserved_delta, available_before, available_after,
                reserved_before, reserved_after, category, source_type, source_ref,
                reservation_id, entry_sha256, created_at_utc
             ) VALUES (
                :entry_id, :idempotency_key, :account_ref, :mgw_id, :legacy_user_id, :asset_code,
                -10, 10, 50, 40, 0, 10, :category, :source_type, :source_ref,
                :reservation_id, :entry_sha256, :created_at
             )',
            [
                'entry_id' => 'db-ledger-entry-1',
                'idempotency_key' => 'db:ledger:reserve:1',
                'account_ref' => 'mgw:mgw_ledger_db',
                'mgw_id' => 'mgw_ledger_db',
                'legacy_user_id' => '8001',
                'asset_code' => 'match_coin',
                'category' => 'reservation_created',
                'source_type' => 'legacy_match',
                'source_ref' => 'db-game-1',
                'reservation_id' => 'db-reservation-1',
                'entry_sha256' => $entryHash,
                'created_at' => $now,
            ]
        );
        $assertSame($entryHash, (string)$database->fetchValue(
            'SELECT entry_sha256 FROM mgw_ledger_entries WHERE entry_id = :entry_id',
            ['entry_id' => 'db-ledger-entry-1']
        ), "{$label} ledger hash must round-trip");
        $assertThrows(
            static fn() => $database->execute(
                'INSERT INTO mgw_ledger_entries (
                    entry_id, idempotency_key, account_ref, asset_code,
                    available_delta, reserved_delta, available_before, available_after,
                    reserved_before, reserved_after, category, source_type,
                    entry_sha256, created_at_utc
                 ) VALUES (
                    :entry_id, :idempotency_key, :account_ref, :asset_code,
                    -10, 10, 50, 49, 0, 10, :category, :source_type,
                    :entry_sha256, :created_at
                 )',
                [
                    'entry_id' => 'db-ledger-invalid-math',
                    'idempotency_key' => 'db:ledger:invalid-math',
                    'account_ref' => 'mgw:mgw_ledger_db',
                    'asset_code' => 'match_coin',
                    'category' => 'invalid',
                    'source_type' => 'test',
                    'entry_sha256' => hash('sha256', 'invalid'),
                    'created_at' => $now,
                ]
            ),
            'constraint',
            "{$label} must reject inconsistent ledger arithmetic"
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
                    'entry_id' => 'db-ledger-duplicate-key',
                    'idempotency_key' => 'db:ledger:reserve:1',
                    'account_ref' => 'mgw:mgw_ledger_db',
                    'asset_code' => 'match_coin',
                    'category' => 'duplicate',
                    'source_type' => 'test',
                    'entry_sha256' => hash('sha256', 'duplicate'),
                    'created_at' => $now,
                ]
            ),
            'duplicate',
            "{$label} must reject duplicate ledger idempotency keys"
        );
        $assertThrows(
            static fn() => $database->execute(
                'DELETE FROM mgw_reservations WHERE reservation_id = :reservation_id',
                ['reservation_id' => 'db-reservation-1']
            ),
            'foreign key',
            "{$label} must preserve linked reservations"
        );
    } finally {
        $cleanup();
    }
}

fwrite(STDOUT, "LedgerSchemaDatabaseIntegrationTest: {$assertions} assertions passed\n");
