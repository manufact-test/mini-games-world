<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/PdoConnectionFactory.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/database/MigrationRepository.php';
require $root . '/database/MigrationRunner.php';
require $root . '/accounts/MgwIdGenerator.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/ledger/LedgerIntegrityVerifier.php';
require $root . '/ledger/LegacyEconomyShadowSyncService.php';
require $root . '/ledger/LegacyOpeningBalanceImportService.php';
require $root . '/accounts/LegacyAccountImportService.php';

final class LegacyAccountImportDatabaseStorage implements StorageAdapterInterface
{
    public function __construct(private array $data) {}
    public function transaction(callable $callback): mixed { return $callback($this->data); }
    public function readOnly(callable $callback): mixed { $snapshot = $this->data; return $callback($snapshot); }
    public function driver(): string { return 'json'; }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$targets = [
    'MySQL' => [
        'dsn' => trim((string)(getenv('MGW_TEST_MYSQL_DSN') ?: '')),
        'user' => (string)(getenv('MGW_TEST_MYSQL_USER') ?: ''),
        'password' => (string)(getenv('MGW_TEST_MYSQL_PASSWORD') ?: ''),
        'driver' => 'mysql',
        'legacy_id' => '71001',
    ],
    'MariaDB' => [
        'dsn' => trim((string)(getenv('MGW_TEST_MARIADB_DSN') ?: '')),
        'user' => (string)(getenv('MGW_TEST_MARIADB_USER') ?: ''),
        'password' => (string)(getenv('MGW_TEST_MARIADB_PASSWORD') ?: ''),
        'driver' => 'mariadb',
        'legacy_id' => '72001',
    ],
];

foreach ($targets as $label => $target) {
    if ($target['dsn'] === '') {
        fwrite(STDOUT, "LegacyAccountImportDatabaseIntegrationTest: {$label} skipped.\n");
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
            'mgw_legacy_financial_transactions', 'mgw_legacy_shop_orders', 'mgw_legacy_payments',
            'mgw_reservation_events', 'mgw_ledger_entries', 'mgw_reservations', 'mgw_idempotency_keys',
            'mgw_balances', 'mgw_legacy_realtime_shadow', 'mgw_notifications', 'mgw_invite_events',
            'mgw_invites', 'mgw_match_player_snapshots', 'mgw_match_snapshots', 'mgw_match_players',
            'mgw_match_queue', 'mgw_matches', 'mgw_sessions', 'mgw_devices', 'mgw_identities',
            'mgw_account_ownership', 'mgw_users', 'mgw_meta', 'mgw_schema_migrations',
        ] as $table) {
            $database->execute('DROP TABLE IF EXISTS `' . $table . '`');
        }
    };

    $cleanup();
    try {
        $runner = new MigrationRunner($database, $root . '/database/migrations');
        $assertSame(7, $runner->migrate(false)['executed_count'], "{$label} must build all schemas");

        $legacyId = $target['legacy_id'];
        $data = [
            'users' => [
                $legacyId => [
                    'id' => $legacyId,
                    'telegram_id' => $legacyId,
                    'first_name' => $label . ' Player',
                    'username' => strtolower($label) . '_legacy',
                    'balance_match' => 40,
                    'balance_gold' => 5,
                    'registered_at' => '2026-07-17T09:00:00+00:00',
                    'last_seen_at' => '2026-07-17T10:00:00+00:00',
                ],
            ],
            'transactions' => [],
            'games' => [], 'queue' => [], 'invites' => [], 'notifications' => [],
            'payments' => [], 'shop_orders' => [],
        ];
        $storage = new LegacyAccountImportDatabaseStorage($data);
        $economy = new LegacyEconomyShadowSyncService($storage, $database);
        $assertSame(true, $economy->run()['ok'], "{$label} economy shadow must sync");

        $opening = new LegacyOpeningBalanceImportService(
            $database,
            new LedgerWriteService($database),
            new LedgerIntegrityVerifier($database)
        );
        $assertSame('completed', $opening->run()['status'], "{$label} opening import must complete first");

        $service = new LegacyAccountImportService($storage, $database);
        $preview = $service->preview();
        $assertSame(true, $preview['ready'], "{$label} account preview must be ready");
        $assertSame(1, $preview['planned_user_create_count'], "{$label} must plan one user");

        $first = $service->run();
        $assertSame(1, $first['created_user_count'], "{$label} must create one user");
        $assertSame(1, $first['created_legacy_link_count'], "{$label} must create one legacy link");
        $assertSame(true, $first['verification']['ok'], "{$label} account verification must pass");

        $mgwId = (string)$database->fetchValue(
            'SELECT mgw_id FROM mgw_identities WHERE provider = :provider AND provider_subject = :subject',
            ['provider' => 'legacy_import', 'subject' => $legacyId]
        );
        $assertTrue(MgwIdGenerator::isValid($mgwId), "{$label} must allocate a valid MGW ID");
        $assertSame(0, (int)$database->fetchValue(
            "SELECT COUNT(*) FROM mgw_identities WHERE provider IN ('telegram', 'development')"
        ), "{$label} must not attach real provider identity yet");
        $assertSame(true, $opening->preview()['ready'], "{$label} account staging must preserve opening import readiness");

        $repeat = $service->run();
        $assertSame(0, $repeat['created_user_count'], "{$label} repeat must not create users");
        $assertSame(0, $repeat['created_legacy_link_count'], "{$label} repeat must not create links");
        $assertSame(1, $repeat['unchanged_user_count'], "{$label} repeat must verify one unchanged user");
        $assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_balances'), "{$label} must preserve separate Match and Gold balances");
    } finally {
        $cleanup();
    }
}

fwrite(STDOUT, "LegacyAccountImportDatabaseIntegrationTest passed: {$assertions} assertions.\n");
