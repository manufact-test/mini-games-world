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
require dirname(__DIR__) . '/accounts/MgwIdGenerator.php';
require dirname(__DIR__) . '/accounts/AccountIdentityService.php';

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
        fwrite(STDOUT, "AccountIdentityDatabaseIntegrationTest: {$label} skipped.\n");
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
        $assertSame(5, $runner->migrate(false)['executed_count'], "{$label} schema must migrate from empty");

        $accounts = new AccountIdentityService($database, 3600);
        $first = $accounts->resolveTelegramUser([
            'id' => '10001',
            'first_name' => 'Primary',
            'username' => 'primary_user',
        ], 'shared-session');
        $repeat = $accounts->resolveTelegramUser([
            'id' => '10001',
            'first_name' => 'Primary Updated',
            'username' => 'primary_updated',
        ], 'second-session');

        $assertTrue(MgwIdGenerator::isValid($first['mgw_id']), "{$label} must persist a valid MGW ID");
        $assertSame($first['mgw_id'], $repeat['mgw_id'], "{$label} repeated login must resolve the same MGW ID");
        $assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_users'), "{$label} must prevent duplicate account creation");
        $assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_identities'), "{$label} must enforce one Telegram identity mapping");
        $assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_sessions'), "{$label} must keep both owned sessions");

        $assertThrows(
            static fn() => $accounts->resolveTelegramUser([
                'id' => '20002',
                'first_name' => 'Intruder',
                'username' => 'intruder',
            ], 'shared-session'),
            'session ownership',
            "{$label} must reject cross-account session takeover"
        );
        $assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_users'), "{$label} rejected takeover must roll back account creation");
    } finally {
        $cleanup();
    }
}

fwrite(STDOUT, "AccountIdentityDatabaseIntegrationTest: {$assertions} assertions passed\n");
