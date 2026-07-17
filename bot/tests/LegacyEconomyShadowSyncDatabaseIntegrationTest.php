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
require $root . '/ledger/LegacyEconomyShadowSyncService.php';

final class DatabaseEconomyShadowStorage implements StorageAdapterInterface
{
    public function __construct(private array $data) {}
    public function replace(array $data): void { $this->data = $data; }
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
        fwrite(STDOUT, "LegacyEconomyShadowSyncDatabaseIntegrationTest: {$label} skipped.\n");
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
        $runner = new MigrationRunner($database, $root . '/database/migrations');
        $assertSame(5, $runner->migrate(false)['executed_count'], "{$label} must build all schemas");

        $mgwId = $label === 'MySQL' ? 'MGW-ECONMYSQL0001' : 'MGW-ECONMARIA0001';
        $now = '2026-07-17 16:30:00.000000';
        $database->execute(
            'INSERT INTO mgw_users (
                mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
             ) VALUES (
                :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
             )',
            [
                'mgw_id' => $mgwId,
                'status' => 'active',
                'display_name' => $label . ' Economy',
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
                'mgw_id' => $mgwId,
                'provider' => 'telegram',
                'provider_subject' => '5001',
                'linked_at' => $now,
                'authenticated_at' => $now,
            ]
        );

        $source = [
            'users' => [
                '5001' => ['id' => '5001', 'balance_match' => 90, 'balance_gold' => 15, 'last_seen_at' => '2026-07-17T16:00:00+00:00'],
                '5002' => ['id' => '5002', 'balance_match' => 20, 'balance_gold' => 5],
            ],
            'transactions' => [
                ['id' => 'db-economy-tx-1', 'type' => 'balance_change', 'user_id' => '5001', 'amount' => 50, 'room' => 'match'],
                ['id' => 'db-economy-tx-2', 'type' => 'balance_change', 'user_id' => '5002', 'amount' => 5, 'room' => 'gold'],
            ],
        ];
        $storage = new DatabaseEconomyShadowStorage($source);
        $service = new LegacyEconomyShadowSyncService($storage, $database);

        $preview = $service->preview();
        $assertSame(2, $preview['sections']['user_balances']['inserted_count'], "{$label} preview must detect users");
        $assertSame(2, $preview['sections']['transactions']['inserted_count'], "{$label} preview must detect transactions");
        $assertSame(0, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_legacy_realtime_shadow WHERE entity_type LIKE 'economy_%'"), "{$label} preview must not write");

        $first = $service->run();
        $assertSame(4, $first['shadow_integrity']['checked_count'], "{$label} run must verify all shadow rows");
        $assertSame(0, $first['shadow_integrity']['corrupted_count'], "{$label} fresh shadow must be healthy");
        $assertSame(1, $first['reconciliation']['mapped_identity_count'], "{$label} identity mapping must work");
        $assertSame(1, $first['reconciliation']['legacy_only_count'], "{$label} unmapped users must remain legacy-only");

        $repeat = $service->run();
        $assertSame(2, $repeat['sections']['user_balances']['unchanged_count'], "{$label} repeated users must be unchanged");
        $assertSame(2, $repeat['sections']['transactions']['unchanged_count'], "{$label} repeated transactions must be unchanged");
        $assertSame($first['source_fingerprint'], $repeat['source_fingerprint'], "{$label} fingerprint must be stable");

        $database->execute(
            "UPDATE mgw_legacy_realtime_shadow SET payload_sha256 = :hash
             WHERE entity_type = 'economy_user_balance' AND entity_key = '5001'",
            ['hash' => str_repeat('0', 64)]
        );
        $tampered = $service->preview();
        $assertSame(1, $tampered['sections']['user_balances']['repair_count'], "{$label} hash corruption must be detected");
        $service->run();
        $assertSame(0, $service->preview()['sections']['user_balances']['repair_count'], "{$label} corruption must be repaired");

        $changed = $source;
        $changed['users']['5001']['balance_match'] = 100;
        array_pop($changed['transactions']);
        $storage->replace($changed);
        $delta = $service->run();
        $assertSame(1, $delta['sections']['user_balances']['updated_count'], "{$label} changed balance must update");
        $assertSame(1, $delta['sections']['transactions']['deleted_count'], "{$label} removed transaction must prune");
        $assertSame(3, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_legacy_realtime_shadow WHERE entity_type LIKE 'economy_%'"), "{$label} shadow count must reconcile");
    } finally {
        $cleanup();
    }
}

fwrite(STDOUT, "LegacyEconomyShadowSyncDatabaseIntegrationTest: {$assertions} assertions passed\n");
