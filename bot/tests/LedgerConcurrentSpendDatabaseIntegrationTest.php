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

if (!function_exists('pcntl_fork')) {
    fwrite(STDOUT, "LedgerConcurrentSpendDatabaseIntegrationTest: skipped (pcntl unavailable).\n");
    return;
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
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

$connectionConfig = static function (array $target): DatabaseConfig {
    $dsnValue = static function (string $key) use ($target): string {
        return preg_match('/(?:^|[:;])' . preg_quote($key, '/') . '=([^;]+)/', $target['dsn'], $matches) === 1
            ? trim((string)$matches[1])
            : '';
    };
    return DatabaseConfig::fromApplicationConfig([
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
};

foreach ($targets as $label => $target) {
    if ($target['dsn'] === '') {
        fwrite(STDOUT, "LedgerConcurrentSpendDatabaseIntegrationTest: {$label} skipped.\n");
        continue;
    }

    $config = $connectionConfig($target);
    $cleanup = static function () use ($config): void {
        $cleanupDatabase = PdoConnectionFactory::create($config);
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
            $cleanupDatabase->execute('DROP TABLE IF EXISTS `' . $table . '`');
        }
    };

    $cleanup();
    $tempDir = sys_get_temp_dir() . '/mgw-ledger-concurrency-' . strtolower($label) . '-' . bin2hex(random_bytes(5));
    if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
        throw new RuntimeException('Could not create concurrency test directory.');
    }

    $database = null;
    $runner = null;
    $service = null;

    try {
        $database = PdoConnectionFactory::create($config);
        $runner = new MigrationRunner($database, $root . '/database/migrations');
        $assertSame(7, $runner->migrate(false)['executed_count'], "{$label} must build the ledger schema");

        $mgwId = $label === 'MySQL' ? 'MGW-CONCURMYSQL001' : 'MGW-CONCURMARIA001';
        $now = '2026-07-17 15:00:00.000000';
        $database->execute(
            'INSERT INTO mgw_users (
                mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
             ) VALUES (
                :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
             )',
            [
                'mgw_id' => $mgwId,
                'status' => 'active',
                'display_name' => $label . ' Concurrent',
                'created_at' => $now,
                'updated_at' => $now,
                'last_seen_at' => $now,
            ]
        );
        $accountRef = 'mgw:' . $mgwId;
        $service = new LedgerWriteService($database, static fn(): string => '2026-07-17 15:00:00.000000');
        $service->postAvailableDelta([
            'operation_key' => strtolower($label) . ':concurrent:grant',
            'account_ref' => $accountRef,
            'asset_code' => 'match_coin',
            'available_delta' => 100,
            'category' => 'legacy_grant',
            'source_type' => 'concurrency_test',
        ]);

        unset($service, $runner, $database);
        $service = null;
        $runner = null;
        $database = null;

        $children = [];
        for ($index = 1; $index <= 2; $index++) {
            $pid = pcntl_fork();
            if ($pid === -1) throw new RuntimeException('Could not fork concurrency worker.');
            if ($pid === 0) {
                try {
                    while (!is_file($tempDir . '/start')) usleep(1000);
                    $childDatabase = PdoConnectionFactory::create($config);
                    $childService = new LedgerWriteService(
                        $childDatabase,
                        static fn(): string => '2026-07-17 15:01:00.000000'
                    );
                    $result = $childService->postAvailableDelta([
                        'operation_key' => strtolower($label) . ':concurrent:debit:' . $index,
                        'account_ref' => $accountRef,
                        'asset_code' => 'match_coin',
                        'available_delta' => -80,
                        'category' => 'test_debit',
                        'source_type' => 'concurrency_test',
                    ]);
                    file_put_contents(
                        $tempDir . '/result-' . $index . '.json',
                        json_encode(['status' => 'success', 'balance' => $result['balance']], JSON_THROW_ON_ERROR)
                    );
                    exit(0);
                } catch (Throwable $error) {
                    file_put_contents(
                        $tempDir . '/result-' . $index . '.json',
                        json_encode(['status' => 'error', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR)
                    );
                    exit(0);
                }
            }
            $children[] = $pid;
        }

        file_put_contents($tempDir . '/start', 'go');
        foreach ($children as $pid) pcntl_waitpid($pid, $status);

        $results = [];
        for ($index = 1; $index <= 2; $index++) {
            $path = $tempDir . '/result-' . $index . '.json';
            if (!is_file($path)) throw new RuntimeException('Concurrency worker did not write a result.');
            $results[] = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }
        $statuses = array_column($results, 'status');
        sort($statuses);
        $assertSame(['error', 'success'], $statuses, "{$label} must allow exactly one concurrent overspending debit");
        $errorMessages = array_column(array_values(array_filter(
            $results,
            static fn(array $result): bool => $result['status'] === 'error'
        )), 'message');
        $assertSame(true, str_contains(strtolower((string)($errorMessages[0] ?? '')), 'insufficient'), "{$label} losing debit must fail for insufficient balance");

        $database = PdoConnectionFactory::create($config);
        $service = new LedgerWriteService($database, static fn(): string => '2026-07-17 15:02:00.000000');
        $finalBalance = $service->getBalance($accountRef, 'match_coin');
        $assertSame(20, $finalBalance['available_amount'], "{$label} concurrent debit must not make balance negative");
        $assertSame(0, $finalBalance['reserved_amount'], "{$label} concurrent debit must not alter reserved balance");
        $assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries'), "{$label} must append only one of the two debits");
        $assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_idempotency_keys'), "{$label} failed debit claim must roll back");
        $assertSame(true, (new LedgerIntegrityVerifier($database))->verifyAccountAsset($accountRef, 'match_coin')['ok'], "{$label} concurrent ledger chain must verify");
    } finally {
        unset($service, $runner, $database);
        foreach (glob($tempDir . '/*') ?: [] as $path) @unlink($path);
        @rmdir($tempDir);
        $cleanup();
    }
}

fwrite(STDOUT, "LedgerConcurrentSpendDatabaseIntegrationTest: {$assertions} assertions passed\n");
