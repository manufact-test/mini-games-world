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
require $root . '/realtime/LegacyRealtimeShadowSyncService.php';

final class DatabaseRealtimeShadowStorage implements StorageAdapterInterface
{
    public function __construct(private array $data) {}

    public function replace(array $data): void
    {
        $this->data = $data;
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this->data);
    }

    public function readOnly(callable $callback): mixed
    {
        $snapshot = $this->data;
        return $callback($snapshot);
    }

    public function driver(): string
    {
        return 'json';
    }
}

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
        fwrite(STDOUT, "LegacyRealtimeShadowSyncDatabaseIntegrationTest: {$label} skipped.\n");
        continue;
    }

    $dsnValue = static function (string $key) use ($target): string {
        return preg_match('/(?:^|[:;])' . preg_quote($key, '/') . '=([^;]+)/', $target['dsn'], $matches) === 1
            ? trim((string)$matches[1])
            : '';
    };
    $databaseConfig = DatabaseConfig::fromApplicationConfig([
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
    $database = PdoConnectionFactory::create($databaseConfig);
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
        $assertSame(7, $runner->migrate(false)['executed_count'], "{$label} must build all shadow schemas");

        $source = [
            'games' => ['db-game-1' => [
                'id' => 'db-game-1',
                'status' => 'active',
                'server_state' => ['hidden' => 'secret'],
                'updated_at' => '2026-07-17T10:00:00+00:00',
            ]],
            'queue' => [[
                'id' => 'db-queue-1',
                'user_id' => '5001',
                'game_type' => 'battleship',
                'updated_at' => '2026-07-17T10:00:01+00:00',
            ]],
            'invites' => [[
                'id' => 'db-invite-1',
                'token' => 'db-token-1',
                'status' => 'pending',
                'updated_at' => '2026-07-17T10:00:02+00:00',
            ]],
            'notifications' => [[
                'id' => 'db-notification-1',
                'event_key' => 'invite:db-invite-1:received',
                'user_id' => '5002',
                'message' => 'Database integration',
                'created_at' => '2026-07-17T10:00:03+00:00',
            ]],
        ];

        $storage = new DatabaseRealtimeShadowStorage($source);
        $service = new LegacyRealtimeShadowSyncService($storage, $database);

        $preview = $service->preview();
        $assertSame(1, $preview['sections']['games']['inserted_count'], "{$label} preview must detect game insert");
        $assertSame(0, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_legacy_realtime_shadow'), "{$label} preview must not write");

        $first = $service->run();
        $assertSame(4, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_legacy_realtime_shadow'), "{$label} run must copy every section");
        $assertSame(1, $first['sections']['notifications']['inserted_count'], "{$label} notification must insert");
        $assertSame(0, $first['sections']['games']['repair_count'], "{$label} healthy insert must not be a repair");

        $repeat = $service->run();
        $assertSame(1, $repeat['sections']['games']['unchanged_count'], "{$label} repeated game must be unchanged");
        $assertSame(0, $repeat['sections']['games']['updated_count'], "{$label} repeated game must not update");
        $assertSame($first['source_fingerprint'], $repeat['source_fingerprint'], "{$label} fingerprint must be stable");

        $database->execute(
            'UPDATE mgw_legacy_realtime_shadow SET payload_json = :payload_json
             WHERE entity_type = :entity_type AND entity_key = :entity_key',
            [
                'payload_json' => '{"id":"db-game-1","status":"tampered"}',
                'entity_type' => 'games',
                'entity_key' => 'db-game-1',
            ]
        );
        $repairPreview = $service->preview();
        $assertSame(1, $repairPreview['sections']['games']['repair_count'], "{$label} must detect payload/hash corruption");
        $repair = $service->run();
        $assertSame(1, $repair['sections']['games']['updated_count'], "{$label} must repair the corrupted payload");
        $storedGame = json_decode((string)$database->fetchValue(
            'SELECT payload_json FROM mgw_legacy_realtime_shadow
             WHERE entity_type = :entity_type AND entity_key = :entity_key',
            ['entity_type' => 'games', 'entity_key' => 'db-game-1']
        ), true, 512, JSON_THROW_ON_ERROR);
        $assertSame('active', $storedGame['status'] ?? null, "{$label} repaired payload must match source");
        $assertTrue(str_contains(json_encode($storedGame, JSON_THROW_ON_ERROR), 'secret'), "{$label} exact server state must remain in server-only shadow");

        $changed = $source;
        $changed['queue'] = [];
        $changed['notifications'][0]['message'] = 'Updated database integration';
        $storage->replace($changed);
        $delta = $service->run();
        $assertSame(1, $delta['sections']['queue']['deleted_count'], "{$label} removed queue row must be pruned");
        $assertSame(1, $delta['sections']['notifications']['updated_count'], "{$label} changed notification must update");
        $assertSame(3, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_legacy_realtime_shadow'), "{$label} row count must reconcile after delete");
    } finally {
        $cleanup();
    }
}

fwrite(STDOUT, "LegacyRealtimeShadowSyncDatabaseIntegrationTest: {$assertions} assertions passed\n");
