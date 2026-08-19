<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/database/DatabaseConnectionInterface.php';
require $projectRoot . '/bot/database/PdoDatabaseConnection.php';
require $projectRoot . '/bot/database/DatabaseMigrationInterface.php';
require $projectRoot . '/bot/replay/MatchReplayReader.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('MatchReplayReaderTest requires PDO SQLite.');
}

$db = new PdoDatabaseConnection(new PDO('sqlite::memory:'));
$db->execute('CREATE TABLE mgw_matches (match_id TEXT PRIMARY KEY, game_type TEXT, status TEXT, board_size INTEGER, bet INTEGER, match_source TEXT, winner_player_ref TEXT, finish_reason TEXT, state_version INTEGER, created_at_utc TEXT, started_at_utc TEXT, updated_at_utc TEXT, finished_at_utc TEXT)');
$db->execute('CREATE TABLE mgw_match_players (match_id TEXT, player_ref TEXT, seat_index INTEGER, role TEXT, is_bot INTEGER, display_name TEXT)');
$db->execute('CREATE TABLE mgw_match_snapshots (snapshot_id INTEGER PRIMARY KEY AUTOINCREMENT, match_id TEXT, state_version INTEGER, public_state_json TEXT, server_state_json TEXT, created_at_utc TEXT)');
$db->execute('CREATE TABLE mgw_match_player_snapshots (match_id TEXT, state_version INTEGER, player_ref TEXT, private_state_json TEXT, created_at_utc TEXT)');
$migration = require $projectRoot . '/bot/database/migrations/20260819_0010_create_match_event_log.php';
$migration->up($db);

$db->execute("INSERT INTO mgw_matches VALUES ('match_replay_1','battleship','finished',10,100,'direct','u1','normal_win',3,'2026-08-19T10:00:00+00:00','2026-08-19T10:00:01+00:00','2026-08-19T10:00:09+00:00','2026-08-19T10:00:09+00:00')");
$db->execute("INSERT INTO mgw_match_players VALUES ('match_replay_1','u1',0,'player',0,'Alpha'),('match_replay_1','u2',1,'player',0,'Beta')");
$db->execute("INSERT INTO mgw_match_snapshots (match_id,state_version,public_state_json,server_state_json,created_at_utc) VALUES ('match_replay_1',1,'{\"phase\":\"start\"}','{\"turn\":\"u1\"}','2026-08-19T10:00:01+00:00'),('match_replay_1',2,'{\"phase\":\"active\"}','{\"turn\":\"u2\"}','2026-08-19T10:00:05+00:00'),('match_replay_1',3,'{\"phase\":\"finished\"}','{\"winner\":\"u1\"}','2026-08-19T10:00:09+00:00')");
$db->execute("INSERT INTO mgw_match_player_snapshots VALUES ('match_replay_1',2,'u1','{\"ships\":[1,2]}','2026-08-19T10:00:05+00:00'),('match_replay_1',2,'u2','{\"ships\":[3,4]}','2026-08-19T10:00:05+00:00')");

$insertEvent = static function (PdoDatabaseConnection $db, string $id, int $revision, int $ordinal, int $version, string $type, string $time, ?string $actor, string $payload): void {
    $db->execute(
        'INSERT INTO mgw_match_events (event_id,match_id,primary_revision,event_ordinal,snapshot_state_version,event_type,occurred_at_utc,actor_user_id,game_type,rules_version,engine_version,payload_json,before_state_sha256,after_state_sha256,retention_class,retain_until_utc,created_at_utc) VALUES (:id,:match_id,:revision,:ordinal,:version,:type,:time,:actor,:game_type,:rules,:engine,:payload,:before_hash,:after_hash,:retention,:retain_until,:created)',
        [
            'id' => $id,
            'match_id' => 'match_replay_1',
            'revision' => $revision,
            'ordinal' => $ordinal,
            'version' => $version,
            'type' => $type,
            'time' => $time,
            'actor' => $actor,
            'game_type' => 'battleship',
            'rules' => str_repeat('a', 64),
            'engine' => str_repeat('b', 64),
            'payload' => $payload,
            'before_hash' => $revision === 1 ? null : str_repeat('c', 64),
            'after_hash' => str_repeat('d', 64),
            'retention' => 'default',
            'retain_until' => null,
            'created' => $time,
        ]
    );
};
$insertEvent($db, 'e3', 3, 1, 3, 'result', '2026-08-19T10:00:09+00:00', 'u1', '{"winner":"u1"}');
$insertEvent($db, 'e1', 1, 0, 1, 'match_started', '2026-08-19T10:00:01+00:00', null, '{}');
$insertEvent($db, 'e2', 2, 0, 2, 'move', '2026-08-19T10:00:05+00:00', 'u1', '{"cell":"A1"}');

$reader = new MatchReplayReader($db);
$replay = $reader->load('match_replay_1');
$assertTrue(is_array($replay), 'Existing match must load');
$assertTrue(($replay['diagnostics']['replayable'] ?? false) === true, 'Complete event/snapshot chain must be replayable');
$assertTrue(($replay['diagnostics']['event_count'] ?? 0) === 3, 'Reader must expose every compact event');
$assertTrue(($replay['diagnostics']['snapshot_count'] ?? 0) === 3, 'Reader must expose every immutable snapshot');
$assertTrue(array_column($replay['timeline'], 'event_type') === ['match_started','move','result'], 'Timeline must follow primary revision and ordinal, never insertion order');
$assertTrue(array_column($replay['frames'], 'state_version') === [1,2,3], 'Frames must be ordered by state version');
$assertTrue(($replay['frames'][1]['events'][0]['event_type'] ?? '') === 'move', 'Snapshot frame must link its event');
$assertTrue(($replay['frames'][1]['private_states']['u1']['ships'][0] ?? null) === 1, 'Admin replay must include private player state for reconstruction');
$assertTrue(($replay['frames'][1]['private_states']['u2']['ships'][1] ?? null) === 4, 'Admin replay must include both players private state');
$assertTrue(($replay['match']['winner_player_ref'] ?? '') === 'u1', 'Reader must expose authoritative result metadata');
$assertTrue(($replay['timeline'][1]['rules_version'] ?? '') === str_repeat('a', 64), 'Reader must expose rules fingerprint');
$assertTrue(($replay['timeline'][1]['engine_version'] ?? '') === str_repeat('b', 64), 'Reader must expose engine fingerprint');

$db->execute('DELETE FROM mgw_match_snapshots WHERE match_id = :match_id AND state_version = 2', ['match_id' => 'match_replay_1']);
$broken = $reader->load('match_replay_1');
$assertTrue(($broken['diagnostics']['replayable'] ?? true) === false, 'Missing linked snapshot must make replay diagnostics fail closed');
$assertTrue(($broken['diagnostics']['missing_snapshot_versions'] ?? []) === [2], 'Diagnostics must identify missing snapshot version');
$assertTrue($reader->load('does-not-exist') === null, 'Unknown match must return null');

$invalidRejected = false;
try { $reader->load(str_repeat('x', 192)); } catch (InvalidArgumentException $error) { $invalidRejected = true; }
$assertTrue($invalidRejected, 'Oversized match ID must be rejected before query');

fwrite(STDOUT, "MatchReplayReaderTest passed: {$assertions} assertions.\n");
