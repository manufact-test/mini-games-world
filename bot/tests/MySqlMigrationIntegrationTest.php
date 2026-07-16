<?php
declare(strict_types=1);

$dsn = trim((string)(getenv('MGW_TEST_MYSQL_DSN') ?: ''));
$user = (string)(getenv('MGW_TEST_MYSQL_USER') ?: '');
$password = (string)(getenv('MGW_TEST_MYSQL_PASSWORD') ?: '');

if ($dsn === '') {
    fwrite(STDOUT, "MySqlMigrationIntegrationTest: skipped (no CI database).\n");
    return;
}

$dsnValue = static function (string $key) use ($dsn): string {
    return preg_match('/(?:^|;)' . preg_quote($key, '/') . '=([^;]+)/', $dsn, $matches) === 1
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
        'driver' => 'mysql',
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
    foreach (['mgw_ci_transaction', 'mgw_meta', 'mgw_schema_migrations'] as $table) {
        $database->execute('DROP TABLE IF EXISTS `' . $table . '`');
    }
};

$cleanup();

try {
    $assertSame('mysql', $database->driver(), 'PDO connection factory must create the mysql driver');
    $assertTrue((int)$database->fetchValue('SELECT 1') === 1, 'PDO connection factory must connect to MySQL');

    $runner = new MigrationRunner($database, $databaseDir . '/migrations');

    $before = $runner->status();
    $assertSame('mysql', $before['driver'], 'CI integration must use PDO mysql');
    $assertSame(1, $before['pending_count'], 'Clean MySQL schema must have one pending migration');

    $migrated = $runner->migrate(false);
    $assertSame(1, $migrated['executed_count'], 'Initial MySQL migration must execute once');
    $assertSame(false, $migrated['executed'][0]['transactional'], 'MySQL DDL migration must not use a wrapping transaction');

    $after = $runner->status();
    $assertSame(1, $after['applied_count'], 'MySQL migration record must be persisted');
    $assertSame(0, $after['pending_count'], 'MySQL schema must be current after migration');

    $secondRun = $runner->migrate(false);
    $assertSame(0, $secondRun['executed_count'], 'Repeated MySQL migration must be idempotent');

    $metaEngine = strtoupper((string)$database->fetchValue(
        "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mgw_meta'"
    ));
    $metaCollation = strtolower((string)$database->fetchValue(
        "SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mgw_meta'"
    ));
    $migrationEngine = strtoupper((string)$database->fetchValue(
        "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mgw_schema_migrations'"
    ));
    $assertSame('INNODB', $metaEngine, 'mgw_meta must use InnoDB');
    $assertTrue(str_starts_with($metaCollation, 'utf8mb4_'), 'mgw_meta must use utf8mb4 collation');
    $assertSame('INNODB', $migrationEngine, 'Migration registry must use InnoDB');

    $checksum = (string)$database->fetchValue('SELECT checksum FROM mgw_schema_migrations LIMIT 1');
    $assertSame(64, strlen($checksum), 'Applied migration checksum must be stored');

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
    $assertSame(0, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_ci_transaction'), 'MySQL DML transaction must roll back');

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
    $assertSame([1, 3], $ids, 'MySQL nested savepoint rollback must preserve the outer transaction');

    fwrite(STDOUT, "MySqlMigrationIntegrationTest: {$assertions} assertions passed\n");
} finally {
    $cleanup();
}
