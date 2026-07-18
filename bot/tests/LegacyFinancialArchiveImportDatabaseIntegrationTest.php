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
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LegacyFinancialStatusNormalizer.php';
require $root . '/ledger/LegacyFinancialArchiveImportService.php';

final class LegacyFinancialArchiveDatabaseStorage implements StorageAdapterInterface
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
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};

$targets = [
    'MySQL' => [
        'dsn' => trim((string)(getenv('MGW_TEST_MYSQL_DSN') ?: '')),
        'user' => (string)(getenv('MGW_TEST_MYSQL_USER') ?: ''),
        'password' => (string)(getenv('MGW_TEST_MYSQL_PASSWORD') ?: ''),
        'driver' => 'mysql',
        'mgw_id' => 'MGW-ARCHMYSQL000001',
    ],
    'MariaDB' => [
        'dsn' => trim((string)(getenv('MGW_TEST_MARIADB_DSN') ?: '')),
        'user' => (string)(getenv('MGW_TEST_MARIADB_USER') ?: ''),
        'password' => (string)(getenv('MGW_TEST_MARIADB_PASSWORD') ?: ''),
        'driver' => 'mariadb',
        'mgw_id' => 'MGW-ARCHMARIA000001',
    ],
];

foreach ($targets as $label => $target) {
    if ($target['dsn'] === '') {
        fwrite(STDOUT, "LegacyFinancialArchiveImportDatabaseIntegrationTest: {$label} skipped.\n");
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
        $runner = new MigrationRunner($database, $root . '/database/migrations');
        $assertSame(7, $runner->migrate(false)['executed_count'], "{$label} must build all archive schemas");

        $now = '2026-07-17 22:00:00.000000';
        $database->execute(
            'INSERT INTO mgw_users (
                mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
             ) VALUES (
                :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
             )',
            [
                'mgw_id' => $target['mgw_id'],
                'status' => 'active',
                'display_name' => $label . ' Archive Import',
                'created_at' => $now,
                'updated_at' => $now,
                'last_seen_at' => $now,
            ]
        );
        $database->execute(
            'INSERT INTO mgw_identities (
                mgw_id, provider, provider_subject, linked_at_utc, last_authenticated_at_utc
             ) VALUES (
                :mgw_id, :provider, :provider_subject, :linked_at, :authenticated_at
             )',
            [
                'mgw_id' => $target['mgw_id'],
                'provider' => 'telegram',
                'provider_subject' => '9001',
                'linked_at' => $now,
                'authenticated_at' => $now,
            ]
        );

        $source = [
            'payments' => [[
                'id' => strtolower($label) . '_pay_1',
                'user_id' => '9001',
                'status' => 'paid',
                'room' => 'gold',
                'coins' => 40,
                'amount_rub' => 40,
                'currency' => 'RUB',
                'balance_applied' => true,
                'created_at' => '2026-07-17T21:00:00+00:00',
                'applied_at' => '2026-07-17T21:01:00+00:00',
            ]],
            'shop_orders' => [[
                'id' => strtolower($label) . '_order_1',
                'user_id' => '9001',
                'status' => 'done',
                'amount' => 25,
                'item_id' => 'legacy-certificate',
                'denomination_id' => 'legacy-25',
                'created_at' => '2026-07-17T21:02:00+00:00',
                'completed_at' => '2026-07-17T21:03:00+00:00',
            ]],
            'transactions' => [
                ['id' => strtolower($label) . '_tx_1', 'category' => 'payment_apply', 'payment_id' => strtolower($label) . '_pay_1', 'user_id' => '9001', 'room' => 'gold', 'amount' => 40, 'amount_rub' => 40, 'currency' => 'RUB', 'created_at' => '2026-07-17T21:01:00+00:00'],
                ['id' => strtolower($label) . '_tx_2', 'category' => 'shop_order', 'order_id' => strtolower($label) . '_order_1', 'user_id' => '9001', 'room' => 'gold', 'amount' => -25, 'created_at' => '2026-07-17T21:02:00+00:00'],
                ['id' => strtolower($label) . '_tx_3', 'category' => 'shop_order_done', 'order_id' => strtolower($label) . '_order_1', 'user_id' => '9001', 'room' => 'gold', 'amount' => 0, 'created_at' => '2026-07-17T21:03:00+00:00'],
                ['id' => strtolower($label) . '_unrelated', 'category' => 'match_settlement', 'user_id' => '9001', 'room' => 'match', 'amount' => 10, 'created_at' => '2026-07-17T21:04:00+00:00'],
            ],
        ];

        $service = new LegacyFinancialArchiveImportService(
            new LegacyFinancialArchiveDatabaseStorage($source),
            $database,
            new LegacyFinancialStatusNormalizer()
        );
        $preview = $service->preview();
        $assertSame(true, $preview['ready'], "{$label} archive preview must be ready");
        $assertSame(['payments' => 1, 'shop_orders' => 1, 'transactions' => 3], $preview['planned_create_counts'], "{$label} preview must plan exact archive rows");
        $assertSame(1, $preview['skipped_transaction_count'], "{$label} preview must skip unrelated transaction");

        $first = $service->run();
        $assertSame(['payments' => 1, 'shop_orders' => 1, 'transactions' => 3], $first['created_counts'], "{$label} first run must insert all planned rows");
        $assertSame(true, $first['verification']['ok'], "{$label} archive verification must pass");
        $assertSame('gold_coin', (string)$database->fetchValue(
            'SELECT asset_code FROM mgw_legacy_payments WHERE legacy_payment_id = :id',
            ['id' => strtolower($label) . '_pay_1']
        ), "{$label} must preserve Gold asset identity");
        $assertSame(4000, (int)$database->fetchValue(
            'SELECT fiat_amount_minor FROM mgw_legacy_payments WHERE legacy_payment_id = :id',
            ['id' => strtolower($label) . '_pay_1']
        ), "{$label} must store fiat amount in minor units");

        $repeat = $service->run();
        $assertSame(['payments' => 0, 'shop_orders' => 0, 'transactions' => 0], $repeat['created_counts'], "{$label} repeat must not duplicate rows");
        $assertSame(['payments' => 1, 'shop_orders' => 1, 'transactions' => 3], $repeat['unchanged_counts'], "{$label} repeat must verify every row");
        $assertSame(3, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_legacy_financial_transactions'), "{$label} repeat must preserve exact transaction count");
    } finally {
        $cleanup();
    }
}

fwrite(STDOUT, "LegacyFinancialArchiveImportDatabaseIntegrationTest passed: {$assertions} assertions.\n");
