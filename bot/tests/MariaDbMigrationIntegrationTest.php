<?php
declare(strict_types=1);

$dsn = trim((string)(getenv('MGW_TEST_MARIADB_DSN') ?: ''));
$user = (string)(getenv('MGW_TEST_MARIADB_USER') ?: '');
$password = (string)(getenv('MGW_TEST_MARIADB_PASSWORD') ?: '');

if ($dsn === '') {
    fwrite(STDOUT, "MariaDbMigrationIntegrationTest: skipped (no CI database).\n");
    return;
}

$dsnValue = static function (string $key) use ($dsn): string {
    return preg_match('/(?:^|[:;])' . preg_quote($key, '/') . '=([^;]+)/', $dsn, $matches) === 1
        ? trim((string)$matches[1])
        : '';
};
$host = $dsnValue('host');
$port = (int)($dsnValue('port') !== '' ? $dsnValue('port') : '3306');
$name = $dsnValue('dbname');

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
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$config = DatabaseConfig::fromApplicationConfig([
    'database' => [
        'enabled' => true,
        'driver' => 'mariadb',
        'host' => $host,
        'port' => $port,
        'name' => $name,
        'user' => $user,
        'password' => $password,
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
        'mgw_ci_transaction',
        'mgw_meta',
        'mgw_schema_migrations',
    ] as $table) {
        $database->execute('DROP TABLE IF EXISTS `' . $table . '`');
    }
};

$cleanup();

try {
    $assertSame('mysql', $database->driver(), 'PDO connection factory must normalize MariaDB to mysql');
    $assertTrue((int)$database->fetchValue('SELECT 1') === 1, 'PDO connection factory must connect to MariaDB');

    $runner = new MigrationRunner($database, $databaseDir . '/migrations');

    $before = $runner->status();
    $assertSame('mysql', $before['driver'], 'MariaDB must use PDO mysql');
    $assertSame(6, $before['pending_count'], 'Clean MariaDB schema must have six pending migrations');

    $migrated = $runner->migrate(false);
    $assertSame(6, $migrated['executed_count'], 'MariaDB migrations must execute once');
    foreach ($migrated['executed'] as $migration) {
        $assertSame(false, $migration['transactional'], 'MariaDB DDL migrations must not use a wrapping transaction');
    }

    $after = $runner->status();
    $assertSame(6, $after['applied_count'], 'MariaDB migration records must be persisted');
    $assertSame(0, $after['pending_count'], 'MariaDB schema must be current after migration');

    $secondRun = $runner->migrate(false);
    $assertSame(0, $secondRun['executed_count'], 'Repeated MariaDB migration must be idempotent');

    $tableEngine = static fn(string $table): string => strtoupper((string)$database->fetchValue(
        "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "'"
    ));
    $metaCollation = strtolower((string)$database->fetchValue(
        "SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mgw_meta'"
    ));

    $assertSame('INNODB', $tableEngine('mgw_meta'), 'MariaDB mgw_meta must use InnoDB');
    $assertTrue(str_starts_with($metaCollation, 'utf8mb4_'), 'MariaDB mgw_meta must use utf8mb4 collation');
    foreach ([
        'mgw_schema_migrations',
        'mgw_users',
        'mgw_identities',
        'mgw_matches',
        'mgw_match_player_snapshots',
        'mgw_invites',
        'mgw_notifications',
        'mgw_legacy_realtime_shadow',
        'mgw_balances',
        'mgw_idempotency_keys',
        'mgw_reservations',
        'mgw_ledger_entries',
        'mgw_reservation_events',
        'mgw_legacy_payments',
        'mgw_legacy_shop_orders',
        'mgw_legacy_financial_transactions',
    ] as $table) {
        $assertSame('INNODB', $tableEngine($table), 'MariaDB ' . $table . ' must use InnoDB');
    }

    $checksumRows = $database->fetchAll('SELECT checksum FROM mgw_schema_migrations ORDER BY version');
    $assertSame(6, count($checksumRows), 'Every MariaDB migration must store a checksum');
    foreach ($checksumRows as $row) {
        $assertSame(64, strlen((string)$row['checksum']), 'MariaDB applied migration checksum must be stored');
    }

    $database->execute('CREATE TABLE mgw_ci_transaction (id INT NOT NULL PRIMARY KEY, value VARCHAR(32) NOT NULL) ENGINE=InnoDB');
    try {
        $database->transaction(function (DatabaseConnectionInterface $connection): void {
            $connection->execute(
                'INSERT INTO mgw_ci_transaction (id, value) VALUES (:id, :value)',
                ['id' => 1, 'value' => 'rollback']
            );
            throw new RuntimeException('intentional-rollback');
        });
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'intentional-rollback') throw $error;
    }
    $assertSame(0, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_ci_transaction'), 'MariaDB DML transaction must roll back');

    $database->transaction(function (DatabaseConnectionInterface $outer): void {
        $outer->execute('INSERT INTO mgw_ci_transaction (id, value) VALUES (1, :value)', ['value' => 'outer']);
        try {
            $outer->transaction(function (DatabaseConnectionInterface $inner): void {
                $inner->execute('INSERT INTO mgw_ci_transaction (id, value) VALUES (2, :value)', ['value' => 'inner']);
                throw new RuntimeException('inner-rollback');
            });
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'inner-rollback') throw $error;
        }
        $outer->execute('INSERT INTO mgw_ci_transaction (id, value) VALUES (3, :value)', ['value' => 'after']);
    });
    $ids = array_map('intval', array_column($database->fetchAll('SELECT id FROM mgw_ci_transaction ORDER BY id'), 'id'));
    $assertSame([1, 3], $ids, 'MariaDB nested savepoint rollback must preserve the outer transaction');

    fwrite(STDOUT, "MariaDbMigrationIntegrationTest: {$assertions} assertions passed\n");
} finally {
    $cleanup();
}
