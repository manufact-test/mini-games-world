<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/database/DatabaseConnectionInterface.php';
require $projectRoot . '/bot/database/PdoDatabaseConnection.php';
require $projectRoot . '/bot/database/DatabaseMigrationInterface.php';
require $projectRoot . '/bot/runtime/RuntimeMatchEventContext.php';
require $projectRoot . '/bot/runtime/RuntimeMatchVersionResolver.php';
require $projectRoot . '/bot/runtime/RuntimeMatchEventLogWriter.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('RuntimeMatchEventLogWriterTest requires PDO SQLite.');
}

$database = new PdoDatabaseConnection(new PDO('sqlite::memory:'));
$migration = require $projectRoot . '/bot/database/migrations/20260819_0010_create_match_event_log.php';
$assertTrue($migration instanceof DatabaseMigrationInterface, 'Event log migration must implement managed migration contract');
$migration->up($database);
$migration->up($database);

$database->execute(
    'CREATE TABLE mgw_matches (match_id TEXT PRIMARY KEY, state_version INTEGER NOT NULL)'
);
$database->execute(
    'INSERT INTO mgw_matches (match_id, state_version) VALUES (:match_id, :state_version)',
    ['match_id' => 'match_1', 'state_version' => 7]
);
$database->execute(
    'INSERT INTO mgw_matches (match_id, state_version) VALUES (:match_id, :state_version)',
    ['match_id' => 'match_2', 'state_version' => 2]
);

$writer = new RuntimeMatchEventLogWriter($projectRoot);

$before = [
    'games' => [
        'match_1' => [
            'id' => 'match_1',
            'game_type' => 'tictactoe',
            'status' => 'active',
            'player_ids' => ['100', '200'],
            'turn' => '100',
            'board_size' => 3,
            'board' => '--------X',
            'updated_at' => '2026-08-19T10:00:00+00:00',
        ],
    ],
];
$after = $before;
$after['games']['match_1']['board'] = '----X---X';
$after['games']['match_1']['status'] = 'finished';
$after['games']['match_1']['winner_id'] = '100';
$after['games']['match_1']['finish_reason'] = 'normal_win';
$after['games']['match_1']['finished_at'] = '2026-08-19T10:00:03+00:00';
$after['games']['match_1']['updated_at'] = '2026-08-19T10:00:03+00:00';

$moveResult = $writer->appendTransition(
    $database,
    42,
    $before,
    $after,
    [
        'api_action' => 'game_action',
        'game_id' => 'match_1',
        'occurred_at_utc' => '2026-08-19T10:00:02+00:00',
        'game_action' => [
            'type' => 'cell',
            'cell' => 4,
            'initData' => 'must-not-persist',
            'sessionId' => 'must-not-persist',
        ],
    ]
);
$assertTrue(($moveResult['created_count'] ?? 0) === 2, 'Finishing move must create move and result events');

$rows = $database->fetchAll(
    'SELECT * FROM mgw_match_events WHERE match_id = :match_id ORDER BY primary_revision, event_ordinal',
    ['match_id' => 'match_1']
);
$assertTrue(count($rows) === 2, 'Match event log must contain exactly two rows for finishing move');
$assertTrue(($rows[0]['event_type'] ?? '') === 'move', 'First finishing event must be move');
$assertTrue(($rows[1]['event_type'] ?? '') === 'result', 'Second finishing event must be result');
$assertTrue((int)($rows[0]['snapshot_state_version'] ?? 0) === 8, 'Move must point at next immutable snapshot version');
$assertTrue((int)($rows[1]['snapshot_state_version'] ?? 0) === 8, 'Result must point at same terminal snapshot version');
$assertTrue(($rows[0]['occurred_at_utc'] ?? '') === '2026-08-19T10:00:02+00:00', 'Move must preserve exact request time');
$assertTrue(($rows[1]['occurred_at_utc'] ?? '') === '2026-08-19T10:00:03+00:00', 'Result must preserve authoritative finish time');
$assertTrue(preg_match('/^[a-f0-9]{64}$/', (string)($rows[0]['rules_version'] ?? '')) === 1, 'Rules version must be deterministic SHA-256');
$assertTrue(preg_match('/^[a-f0-9]{64}$/', (string)($rows[0]['engine_version'] ?? '')) === 1, 'Engine version must be deterministic SHA-256');
$assertTrue(!str_contains((string)($rows[0]['payload_json'] ?? ''), 'initData'), 'Event payload must not persist Telegram init data');
$assertTrue(!str_contains((string)($rows[0]['payload_json'] ?? ''), 'sessionId'), 'Event payload must not persist session identifiers');
$assertTrue(($rows[0]['retention_class'] ?? '') === 'default', 'Event must carry a retention class without inventing a TTL');
$assertTrue(($rows[0]['retain_until_utc'] ?? null) === null, 'Default retention class must not invent retain-until time');

$beforeDisconnect = [
    'games' => [
        'match_2' => [
            'id' => 'match_2',
            'game_type' => 'tictactoe',
            'status' => 'active',
            'player_ids' => ['100', '200'],
            'turn' => '200',
            'board_size' => 3,
            'board' => '---------',
            'updated_at' => '2026-08-19T10:01:00+00:00',
        ],
    ],
];
$afterDisconnect = $beforeDisconnect;
$afterDisconnect['games']['match_2']['reconnect_v2'] = [
    'version' => 2,
    'paused' => true,
    'paused_at_ms' => 1787133660000,
    'players' => [
        '200' => [
            'disconnected_at_ms' => 1787133660000,
            'disconnected_at' => '2026-08-19T10:01:00+00:00',
            'deadline_ms' => 1787133720000,
            'deadline_at' => '2026-08-19T10:02:00+00:00',
        ],
    ],
];
$afterDisconnect['games']['match_2']['updated_at'] = '2026-08-19T10:01:00+00:00';

$disconnectResult = $writer->appendTransition(
    $database,
    43,
    $beforeDisconnect,
    $afterDisconnect,
    [
        'api_action' => 'game_state',
        'game_id' => 'match_2',
        'occurred_at_utc' => '2026-08-19T10:01:01+00:00',
        'game_action' => [],
    ]
);
$assertTrue(($disconnectResult['created_count'] ?? 0) === 1, 'Reconnect pause must create one disconnect event');
$disconnectRows = $database->fetchAll(
    'SELECT * FROM mgw_match_events WHERE match_id = :match_id ORDER BY primary_revision, event_ordinal',
    ['match_id' => 'match_2']
);
$assertTrue(($disconnectRows[0]['event_type'] ?? '') === 'disconnect', 'Reconnect lifecycle must preserve disconnect event');
$assertTrue(($disconnectRows[0]['actor_user_id'] ?? '') === '200', 'Disconnect event must identify disconnected player');
$assertTrue((int)($disconnectRows[0]['snapshot_state_version'] ?? 0) === 3, 'Disconnect must point at next immutable snapshot version');
$assertTrue(($disconnectRows[0]['occurred_at_utc'] ?? '') === '2026-08-19T10:01:00+00:00', 'Disconnect must use authoritative disconnect time');

$afterReconnect = $afterDisconnect;
unset($afterReconnect['games']['match_2']['reconnect_v2']);
$afterReconnect['games']['match_2']['updated_at'] = '2026-08-19T10:01:15+00:00';
$database->execute(
    'UPDATE mgw_matches SET state_version = :state_version WHERE match_id = :match_id',
    ['state_version' => 3, 'match_id' => 'match_2']
);
$reconnectResult = $writer->appendTransition(
    $database,
    44,
    $afterDisconnect,
    $afterReconnect,
    [
        'api_action' => 'game_state',
        'game_id' => 'match_2',
        'occurred_at_utc' => '2026-08-19T10:01:15+00:00',
        'game_action' => [],
    ]
);
$assertTrue(($reconnectResult['created_count'] ?? 0) === 1, 'Reconnect restore must create one reconnect event');
$reconnectRows = $database->fetchAll(
    'SELECT * FROM mgw_match_events WHERE match_id = :match_id AND primary_revision = 44',
    ['match_id' => 'match_2']
);
$assertTrue(($reconnectRows[0]['event_type'] ?? '') === 'reconnect', 'Reconnect restore must preserve reconnect event');
$assertTrue(($reconnectRows[0]['actor_user_id'] ?? '') === '200', 'Reconnect event must identify restored player');
$assertTrue((int)($reconnectRows[0]['snapshot_state_version'] ?? 0) === 4, 'Reconnect must point at next immutable snapshot version');

$newState = [
    'games' => [
        'match_3' => [
            'id' => 'match_3',
            'game_type' => 'tictactoe',
            'status' => 'active',
            'player_ids' => ['300', '400'],
            'turn' => '300',
            'board_size' => 3,
            'board' => '---------',
            'created_at' => '2026-08-19T10:03:00+00:00',
            'updated_at' => '2026-08-19T10:03:00+00:00',
        ],
    ],
];
$newResult = $writer->appendTransition(
    $database,
    45,
    ['games' => []],
    $newState,
    [
        'api_action' => 'start_search',
        'game_id' => '',
        'occurred_at_utc' => '2026-08-19T10:03:00+00:00',
        'game_action' => [],
    ]
);
$assertTrue(($newResult['created_count'] ?? 0) === 1, 'New match must create match_started event');
$newRows = $database->fetchAll('SELECT * FROM mgw_match_events WHERE match_id = :match_id', ['match_id' => 'match_3']);
$assertTrue(($newRows[0]['event_type'] ?? '') === 'match_started', 'New match event type must be match_started');
$assertTrue((int)($newRows[0]['snapshot_state_version'] ?? 0) === 1, 'New match must point at first immutable snapshot');

$unchanged = $writer->appendTransition(
    $database,
    46,
    $newState,
    $newState,
    [
        'api_action' => 'game_state',
        'game_id' => 'match_3',
        'occurred_at_utc' => '2026-08-19T10:03:02+00:00',
        'game_action' => [],
    ]
);
$assertTrue(($unchanged['created_count'] ?? -1) === 0, 'Unchanged match state must not create an event');

$resolver = new RuntimeMatchVersionResolver($projectRoot);
foreach (['tictactoe', 'four_in_a_row', 'battleship', 'checkers', 'reversi', 'chess', 'go', 'domino'] as $gameType) {
    $versions = $resolver->resolve($gameType);
    $assertTrue(preg_match('/^[a-f0-9]{64}$/', (string)$versions['rules_version']) === 1, $gameType . ' rules fingerprint must be valid');
    $assertTrue(preg_match('/^[a-f0-9]{64}$/', (string)$versions['engine_version']) === 1, $gameType . ' engine fingerprint must be valid');
}

fwrite(STDOUT, "RuntimeMatchEventLogWriterTest passed: {$assertions} assertions.\n");
