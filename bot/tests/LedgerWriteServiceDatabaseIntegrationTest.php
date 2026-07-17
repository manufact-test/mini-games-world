<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/PdoConnectionFactory.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/database/MigrationRepository.php';
require $root . '/database/MigrationRunner.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/ledger/LedgerIntegrityVerifier.php';

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
        fwrite(STDOUT, "LedgerWriteServiceDatabaseIntegrationTest: {$label} skipped.\n");
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
            'mgw_legacy_financial_transactions',
            'mgw_legacy_shop_orders',
            'mgw_legacy_payments',
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
        $runner = new MigrationRunner($database, $root . '/database/migrations');
        $assertSame(6, $runner->migrate(false)['executed_count'], "{$label} must build the ledger schema");

        $mgwId = 'MGW-1234567890ABCDEF';
        $now = '2026-07-17 14:00:00.000000';
        $database->execute(
            'INSERT INTO mgw_users (
                mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
             ) VALUES (
                :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
             )',
            [
                'mgw_id' => $mgwId,
                'status' => 'active',
                'display_name' => $label . ' Ledger',
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

        $credit = $service->postAvailableDelta([
            'operation_key' => strtolower($label) . ':credit:1',
            'account_ref' => $accountRef,
            'asset_code' => 'match_coin',
            'available_delta' => 100,
            'category' => 'legacy_grant',
            'source_type' => 'integration_test',
            'metadata' => ['database' => $label],
        ]);
        $assertSame(100, $credit['balance']['available_amount'], "{$label} credit must update balance");
        $creditReplay = $service->postAvailableDelta([
            'operation_key' => strtolower($label) . ':credit:1',
            'account_ref' => $accountRef,
            'asset_code' => 'match_coin',
            'available_delta' => 100,
            'category' => 'legacy_grant',
            'source_type' => 'integration_test',
            'metadata' => ['database' => $label],
        ]);
        $assertSame(true, $creditReplay['replayed'], "{$label} duplicate credit must replay");
        $assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries'), "{$label} replay must not duplicate entry");

        $assertThrows(
            static fn() => $service->postAvailableDelta([
                'operation_key' => strtolower($label) . ':debit:too-much',
                'account_ref' => $accountRef,
                'asset_code' => 'match_coin',
                'available_delta' => -101,
                'category' => 'test_debit',
                'source_type' => 'integration_test',
            ]),
            'insufficient',
            "{$label} overspend must fail"
        );
        $assertSame(100, $service->getBalance($accountRef, 'match_coin')['available_amount'], "{$label} failed debit must roll back");

        $clockValue = '2026-07-17 14:01:00.000000';
        $reservation = $service->createReservation([
            'operation_key' => strtolower($label) . ':reserve:1',
            'account_ref' => $accountRef,
            'asset_code' => 'match_coin',
            'amount' => 35,
            'source_type' => 'legacy_match',
            'source_ref' => strtolower($label) . '-game-1',
        ]);
        $assertSame(65, $reservation['balance']['available_amount'], "{$label} reservation must debit available");
        $assertSame(35, $reservation['balance']['reserved_amount'], "{$label} reservation must credit reserved");

        $clockValue = '2026-07-17 14:02:00.000000';
        $released = $service->releaseReservation([
            'operation_key' => strtolower($label) . ':release:1',
            'reservation_id' => $reservation['reservation_id'],
        ]);
        $assertSame(100, $released['balance']['available_amount'], "{$label} release must restore available");
        $assertSame(0, $released['balance']['reserved_amount'], "{$label} release must clear reserved");
        $assertSame(true, $verifier->verifyAccountAsset($accountRef, 'match_coin')['ok'], "{$label} ledger hash chain must verify");
        $assertSame(true, $verifier->verifyReservation($reservation['reservation_id'])['ok'], "{$label} reservation event hashes must verify");

        $assertSame(3, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries'), "{$label} must append credit, reserve and release entries");
        $assertSame(3, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_idempotency_keys WHERE status = :status', ['status' => 'completed']), "{$label} completed operations must persist");
    } finally {
        $cleanup();
    }
}

fwrite(STDOUT, "LedgerWriteServiceDatabaseIntegrationTest: {$assertions} assertions passed\n");
