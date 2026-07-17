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
require dirname(__DIR__) . '/realtime/RealtimeDatabaseStore.php';

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
        fwrite(STDOUT, "RealtimeDatabaseStoreDatabaseIntegrationTest: {$label} skipped.\n");
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
        $assertSame(5, $runner->migrate(false)['executed_count'], "{$label} must build the realtime schema");

        foreach ([['mgw_rt_a', 'A'], ['mgw_rt_b', 'B']] as [$mgwId, $name]) {
            $database->execute(
                'INSERT INTO mgw_users (
                    mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
                 ) VALUES (
                    :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
                 )',
                [
                    'mgw_id' => $mgwId,
                    'status' => 'active',
                    'display_name' => $name,
                    'created_at' => '2026-07-16 20:30:00.000000',
                    'updated_at' => '2026-07-16 20:30:00.000000',
                    'last_seen_at' => '2026-07-16 20:30:00.000000',
                ]
            );
        }

        $store = new RealtimeDatabaseStore($database);
        $playerA = RealtimeDatabaseStore::playerReference('mgw_rt_a', '3001');
        $playerB = RealtimeDatabaseStore::playerReference('mgw_rt_b', '3002');
        $now = '2026-07-16T20:30:00+00:00';
        $match = [
            'match_id' => 'db-hidden-match',
            'game_type' => 'domino',
            'room' => 'match',
            'status' => 'active',
            'board_size' => 2,
            'bet' => 10,
            'turn_player_ref' => $playerA,
            'state_version' => 1,
            'public_state' => ['board' => [['6', '6']], 'turn' => $playerA],
            'server_state' => ['bag' => [['0', '0']], 'audit' => 'server-only'],
            'created_at_utc' => $now,
            'started_at_utc' => $now,
            'updated_at_utc' => $now,
        ];
        $players = [
            ['seat' => 0, 'player_ref' => $playerA, 'mgw_id' => 'mgw_rt_a', 'legacy_user_id' => '3001', 'display_name' => 'A'],
            ['seat' => 1, 'player_ref' => $playerB, 'mgw_id' => 'mgw_rt_b', 'legacy_user_id' => '3002', 'display_name' => 'B'],
        ];
        $private = [
            $playerA => ['hand' => [['1', '2']], 'sentinel' => 'player-a-secret'],
            $playerB => ['hand' => [['3', '4']], 'sentinel' => 'opponent-private-secret'],
        ];

        $store->saveMatchSnapshot($match, $players, $private);
        $store->saveMatchSnapshot($match, $players, $private);
        $assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_match_snapshots'), "{$label} repeated snapshots must be idempotent");
        $viewA = $store->loadMatchForPlayer('db-hidden-match', $playerA);
        $assertSame([['1', '2']], $viewA['private_state']['hand'], "{$label} must return only Player A hidden state");
        $assertTrue(!str_contains(json_encode($viewA, JSON_THROW_ON_ERROR), 'opponent-private-secret'), "{$label} Player A view must not leak Player B hidden state");
        $assertTrue(!array_key_exists('server_state', $viewA['match']), "{$label} player view must not expose server state");
        $assertSame('server-only', $store->loadServerMatch('db-hidden-match')['server_state']['audit'], "{$label} trusted server load must include server state");

        $queue = $store->upsertQueueEntry([
            'queue_id' => 'db-queue-a',
            'player_ref' => $playerA,
            'mgw_id' => 'mgw_rt_a',
            'legacy_user_id' => '3001',
            'game_type' => 'domino',
            'room' => 'match',
            'bet' => 10,
            'board_size' => 2,
            'created_at_utc' => $now,
            'updated_at_utc' => $now,
        ]);
        $assertSame('db-queue-a', $queue['queue_id'], "{$label} queue row must persist");
        $assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_match_queue'), "{$label} queue must contain one row");

        $invite = $store->upsertInvite([
            'invite_id' => 'db-invite-a',
            'token' => 'db-token-a',
            'status' => 'pending',
            'source' => 'direct',
            'inviter_ref' => $playerA,
            'inviter_mgw_id' => 'mgw_rt_a',
            'inviter_legacy_user_id' => '3001',
            'inviter_name' => 'A',
            'invitee_ref' => $playerB,
            'invitee_mgw_id' => 'mgw_rt_b',
            'invitee_legacy_user_id' => '3002',
            'invitee_name' => 'B',
            'game_type' => 'domino',
            'game_title' => 'Домино',
            'room' => 'match',
            'bet' => 10,
            'board_size' => 2,
            'created_at_utc' => $now,
            'updated_at_utc' => $now,
        ]);
        $assertSame(1, (int)$invite['version'], "{$label} invite must start at version 1");
        $assertTrue($store->appendInviteEvent('db-invite-a', 'created', 'created', $playerA, ['token' => 'db-token-a'], $now), "{$label} invite event must insert");
        $assertSame(false, $store->appendInviteEvent('db-invite-a', 'created', 'created', $playerA, ['token' => 'db-token-a'], $now), "{$label} invite event must dedupe");

        $firstNotification = $store->addNotification([
            'notification_id' => 'db-notification-a',
            'event_key' => 'invite:db-invite-a:received:3002',
            'recipient_ref' => $playerB,
            'mgw_id' => 'mgw_rt_b',
            'legacy_user_id' => '3002',
            'type' => 'invite_received',
            'title' => 'Вас пригласили сыграть',
            'message' => 'A приглашает вас в «Домино».',
            'invite_token' => 'db-token-a',
            'payload' => ['invite_id' => 'db-invite-a'],
            'created_at_utc' => $now,
        ]);
        $duplicateNotification = $store->addNotification([
            'notification_id' => 'db-notification-duplicate',
            'event_key' => 'invite:db-invite-a:received:3002',
            'recipient_ref' => $playerB,
            'type' => 'invite_received',
            'title' => 'Ignored',
            'message' => 'Ignored',
            'created_at_utc' => $now,
        ]);
        $assertSame($firstNotification['notification_id'], $duplicateNotification['notification_id'], "{$label} notification event key must dedupe");
        $assertSame('db-invite-a', $store->listNotifications($playerB)[0]['payload']['invite_id'], "{$label} notification JSON must round-trip");
    } finally {
        $cleanup();
    }
}

fwrite(STDOUT, "RealtimeDatabaseStoreDatabaseIntegrationTest: {$assertions} assertions passed\n");
