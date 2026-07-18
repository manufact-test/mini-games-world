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
        fwrite(STDOUT, "LegacyFinancialArchiveDatabaseIntegrationTest: {$label} skipped.\n");
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
            'mgw_account_ownership',
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
        $assertSame(7, $runner->migrate(false)['executed_count'], "{$label} must build the legacy financial archive schema");
        $assertSame(0, $runner->migrate(false)['executed_count'], "{$label} repeated archive migration must be idempotent");

        foreach (['mgw_legacy_payments', 'mgw_legacy_shop_orders', 'mgw_legacy_financial_transactions'] as $table) {
            $assertSame(
                $table,
                (string)$database->fetchValue(
                    'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name',
                    ['name' => $table]
                ),
                "{$label} {$table} must exist"
            );
        }

        $now = '2026-07-17 20:00:00.000000';
        $database->execute(
            'INSERT INTO mgw_users (
                mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
             ) VALUES (
                :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
             )',
            [
                'mgw_id' => 'mgw_archive_db',
                'status' => 'active',
                'display_name' => 'Archive DB',
                'created_at' => $now,
                'updated_at' => $now,
                'last_seen_at' => $now,
            ]
        );

        $snapshot = json_encode(['id' => 'pay_db_1', 'status' => 'paid', 'room' => 'gold'], JSON_THROW_ON_ERROR);
        $parameters = [
            'id' => 'pay_db_1',
            'account_ref' => 'mgw:mgw_archive_db',
            'mgw_id' => 'mgw_archive_db',
            'legacy_user_id' => '9001',
            'status_raw' => 'paid',
            'status_normalized' => 'completed',
            'room_raw' => 'gold',
            'asset_code' => 'gold_coin',
            'snapshot_json' => $snapshot,
            'snapshot_sha256' => hash('sha256', $snapshot),
            'archive_batch_id' => hash('sha256', 'archive-db-batch'),
            'source_file' => 'payments.json',
            'source_index' => 0,
            'archived_at' => $now,
        ];
        $insertPayment = static function (array $parameters) use ($database): void {
            $database->execute(
                'INSERT INTO mgw_legacy_payments (
                    legacy_payment_id, account_ref, mgw_id, legacy_user_id,
                    status_raw, status_normalized, room_raw, asset_code,
                    snapshot_json, snapshot_sha256, archive_batch_id,
                    source_file, source_index, archived_at_utc
                 ) VALUES (
                    :id, :account_ref, :mgw_id, :legacy_user_id,
                    :status_raw, :status_normalized, :room_raw, :asset_code,
                    :snapshot_json, :snapshot_sha256, :archive_batch_id,
                    :source_file, :source_index, :archived_at
                 )',
                $parameters
            );
        };
        $insertPayment($parameters);
        $assertSame('gold_coin', (string)$database->fetchValue(
            'SELECT asset_code FROM mgw_legacy_payments WHERE legacy_payment_id = :id',
            ['id' => 'pay_db_1']
        ), "{$label} must preserve the legacy Gold asset code");
        $assertSame($parameters['snapshot_sha256'], (string)$database->fetchValue(
            'SELECT snapshot_sha256 FROM mgw_legacy_payments WHERE legacy_payment_id = :id',
            ['id' => 'pay_db_1']
        ), "{$label} must round-trip the exact payment snapshot hash");

        $duplicate = $parameters;
        $duplicate['id'] = 'pay_db_duplicate_source';
        $assertThrows(
            static fn() => $insertPayment($duplicate),
            'duplicate',
            "{$label} must reject duplicate source positions"
        );
    } finally {
        $cleanup();
    }
}

fwrite(STDOUT, "LegacyFinancialArchiveDatabaseIntegrationTest passed: {$assertions} assertions.\n");
