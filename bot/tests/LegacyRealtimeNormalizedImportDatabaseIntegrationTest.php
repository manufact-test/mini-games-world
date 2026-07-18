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
require $root . '/accounts/LegacyAccountOwnershipLinkService.php';
require $root . '/realtime/LegacyRealtimeShadowSyncService.php';
require $root . '/migration/LegacyRealtimeNormalizedImportService.php';

final class NormalizedRealtimeDatabaseStorage implements StorageAdapterInterface
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
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
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
        'legacy_id' => '75001',
    ],
    'MariaDB' => [
        'dsn' => trim((string)(getenv('MGW_TEST_MARIADB_DSN') ?: '')),
        'user' => (string)(getenv('MGW_TEST_MARIADB_USER') ?: ''),
        'password' => (string)(getenv('MGW_TEST_MARIADB_PASSWORD') ?: ''),
        'driver' => 'mariadb',
        'legacy_id' => '76001',
    ],
];

foreach ($targets as $label => $target) {
    if ($target['dsn'] === '') {
        fwrite(STDOUT, "LegacyRealtimeNormalizedImportDatabaseIntegrationTest: {$label} skipped.\n");
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
            'mgw_account_ownership',
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
        $assertSame(7, $runner->migrate(false)['executed_count'], "{$label} must build all schemas");

        $legacyId = $target['legacy_id'];
        $botId = 'bot_' . strtolower($label);
        $gameId = 'game-' . strtolower($label) . '-normalized';
        $notificationId = 'notification-' . strtolower($label) . '-normalized';
        $data = [
            'users' => [
                $legacyId => [
                    'id' => $legacyId,
                    'telegram_id' => $legacyId,
                    'first_name' => $label . ' Player',
                    'username' => strtolower($label) . '_normalized',
                    'balance_match' => 40,
                    'balance_gold' => 5,
                    'registered_at' => '2026-07-18T08:00:00+00:00',
                    'last_seen_at' => '2026-07-18T09:00:00+00:00',
                ],
            ],
            'transactions' => [],
            'games' => [
                $gameId => [
                    'id' => $gameId,
                    'game_type' => 'reversi',
                    'room' => 'match',
                    'bet' => 10,
                    'board_size' => 8,
                    'player_ids' => [$legacyId, $botId],
                    'player_names' => [$legacyId => $label . ' Player', $botId => 'Bot'],
                    'status' => 'finished',
                    'winner_id' => $legacyId,
                    'loser_id' => $botId,
                    'finish_reason' => 'normal_win',
                    'is_bot_game' => true,
                    'bot_id' => $botId,
                    'server_secret' => ['must_remain_server_only' => true],
                    'created_at' => '2026-07-18T10:00:00+00:00',
                    'updated_at' => '2026-07-18T10:05:00+00:00',
                    'finished_at' => '2026-07-18T10:05:00+00:00',
                ],
            ],
            'queue' => [],
            'invites' => [],
            'notifications' => [[
                'id' => $notificationId,
                'event_key' => 'legacy-event:' . $legacyId,
                'user_id' => $legacyId,
                'type' => 'legacy_notice',
                'title' => $label . ' notice',
                'message' => 'Normalized import database integration.',
                'tone' => 'info',
                'created_at' => '2026-07-18T10:06:00+00:00',
            ]],
            'payments' => [],
            'shop_orders' => [],
        ];
        $storage = new NormalizedRealtimeDatabaseStorage($data);
        $assertSame(true, (new LegacyEconomyShadowSyncService($storage, $database))->run()['ok'], "{$label} economy shadow must sync");
        $verifier = new LedgerIntegrityVerifier($database);
        $opening = new LegacyOpeningBalanceImportService(
            $database,
            new LedgerWriteService($database),
            $verifier
        );
        $assertSame('completed', $opening->run()['status'], "{$label} opening import must complete");
        $assertSame('completed', (new LegacyAccountImportService($storage, $database))->run()['status'], "{$label} account import must complete");
        $assertSame(
            'completed',
            (new LegacyAccountOwnershipLinkService($storage, $database, $verifier))->run()['status'],
            "{$label} ownership link must complete"
        );

        $shadow = new LegacyRealtimeShadowSyncService($storage, $database);
        $assertSame(true, $shadow->run()['ok'], "{$label} realtime shadow must sync");
        $service = new LegacyRealtimeNormalizedImportService($storage, $database, $shadow);
        $preview = $service->preview();
        $assertSame(true, $preview['ready'], "{$label} normalized preview must be ready");
        $assertSame(['games' => 1, 'queue' => 0, 'invites' => 0, 'notifications' => 1], $preview['planned_create_counts'], "{$label} preview counts must be exact");

        $first = $service->run();
        $assertSame(['games' => 1, 'queue' => 0, 'invites' => 0, 'notifications' => 1], $first['created_counts'], "{$label} first run must create exact targets");
        $assertSame(true, $first['verification']['ok'], "{$label} final verification must pass");
        $assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_matches'), "{$label} must import one match");
        $assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_match_players'), "{$label} must import two players");
        $assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_match_snapshots'), "{$label} must import one snapshot");
        $assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_notifications'), "{$label} must import one notification");
        $assertSame(null, $database->fetchValue(
            'SELECT public_state_json FROM mgw_matches WHERE match_id = :match_id',
            ['match_id' => $gameId]
        ), "{$label} must not expose server state publicly");
        $assertTrue(str_contains((string)$database->fetchValue(
            'SELECT server_state_json FROM mgw_matches WHERE match_id = :match_id',
            ['match_id' => $gameId]
        ), 'must_remain_server_only'), "{$label} must preserve exact server state");
        $assertTrue(MgwIdGenerator::isValid((string)$database->fetchValue(
            'SELECT mgw_id FROM mgw_notifications WHERE notification_id = :notification_id',
            ['notification_id' => $notificationId]
        )), "{$label} notification must link to MGW ID");

        $repeat = $service->run();
        $assertSame(['games' => 0, 'queue' => 0, 'invites' => 0, 'notifications' => 0], $repeat['created_counts'], "{$label} repeat must create nothing");
        $assertSame(['games' => 1, 'queue' => 0, 'invites' => 0, 'notifications' => 1], $repeat['unchanged_counts'], "{$label} repeat must verify exact targets");
        $assertSame(true, $verifier->verifyAccountAsset('legacy:' . $legacyId, 'match_coin')['ok'], "{$label} Match ledger must remain valid");
        $assertSame(true, $verifier->verifyAccountAsset('legacy:' . $legacyId, 'gold_coin')['ok'], "{$label} Gold ledger must remain valid");
    } finally {
        $cleanup();
    }
}

fwrite(STDOUT, "LegacyRealtimeNormalizedImportDatabaseIntegrationTest passed: {$assertions} assertions.\n");
