<?php
declare(strict_types=1);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$db = new PdoDatabaseConnection($pdo);
$assertSame(7, (new MigrationRunner($db, $root . '/database/migrations'))->migrate(false)['executed_count'], 'All migrations');

$now = '2026-07-18 19:20:00.000000';
$db->execute(
    'INSERT INTO mgw_users (mgw_id,status,display_name,created_at_utc,updated_at_utc,last_seen_at_utc)
     VALUES (:mgw_id,:status,:display_name,:created_at,:updated_at,:last_seen_at)',
    [
        'mgw_id' => 'MGW-REALTIME000001',
        'status' => 'active',
        'display_name' => 'Player',
        'created_at' => $now,
        'updated_at' => $now,
        'last_seen_at' => $now,
    ]
);
$db->execute(
    'INSERT INTO mgw_account_ownership
     (account_ref,mgw_id,legacy_user_id,ownership_status,source_type,source_ref,source_sha256,created_at_utc,verified_at_utc)
     VALUES (:account_ref,:mgw_id,:legacy_user_id,:ownership_status,:source_type,:source_ref,:source_sha256,:created_at,:verified_at)',
    [
        'account_ref' => 'legacy:1001',
        'mgw_id' => 'MGW-REALTIME000001',
        'legacy_user_id' => '1001',
        'ownership_status' => 'active',
        'source_type' => 'legacy_json',
        'source_ref' => 'users.json:1001',
        'source_sha256' => hash('sha256', 'owner-1001'),
        'created_at' => $now,
        'verified_at' => $now,
    ]
);

$config = [
    'environment' => 'staging',
    'storage_driver' => 'json',
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'mgw_test',
        'user' => 'mgw_test',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'feature_flags' => [
        'database_runtime' => [
            'enabled' => true,
            'modules' => ['accounts' => true, 'realtime' => true],
        ],
    ],
];
$repo = new RuntimeRealtimeRepository($config, new RuntimeStorageRouter($config), $db);
$data = [
    'games' => [
        'game-1' => [
            'id' => 'game-1',
            'game_type' => 'tictactoe',
            'room' => 'match',
            'status' => 'active',
            'board_size' => 3,
            'bet' => 10,
            'player_ids' => ['1001', 'bot_runtime_1'],
            'player_names' => ['1001' => 'Player', 'bot_runtime_1' => 'Milo'],
            'symbols' => ['1001' => 'X', 'bot_runtime_1' => 'O'],
            'turn' => '1001',
            'board' => '---------',
            'created_at' => '2026-07-18T19:20:00+00:00',
            'started_at' => '2026-07-18T19:20:00+00:00',
            'updated_at' => '2026-07-18T19:20:00+00:00',
            'is_bot_game' => true,
            'bot_id' => 'bot_runtime_1',
        ],
    ],
    'queue' => [[
        'user_id' => '1001',
        'game_type' => 'tictactoe',
        'room' => 'match',
        'bet' => 10,
        'board_size' => 3,
        'status' => 'waiting',
        'created_at' => '2026-07-18T19:20:00+00:00',
        'updated_at' => '2026-07-18T19:20:00+00:00',
    ]],
];
