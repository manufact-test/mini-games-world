<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/bot/database/DatabaseConnectionInterface.php';
require $root . '/bot/database/PdoDatabaseConnection.php';
require $root . '/bot/database/DatabaseMigrationInterface.php';
require $root . '/bot/replay/MatchReplayReader.php';
require $root . '/bot/antifraud/AntiFraudCaseService.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('MVP-22.4 anti-fraud test requires PDO SQLite.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$db = new PdoDatabaseConnection(new PDO('sqlite::memory:'));
$db->execute('PRAGMA foreign_keys = ON');

$db->execute('CREATE TABLE mgw_users (mgw_id TEXT PRIMARY KEY, display_name TEXT, status TEXT)');
$db->execute('CREATE TABLE mgw_matches (
    match_id TEXT PRIMARY KEY, game_type TEXT, room TEXT, status TEXT, board_size INTEGER, bet INTEGER,
    match_source TEXT, invite_id TEXT, source_match_id TEXT, turn_player_ref TEXT, winner_player_ref TEXT,
    finish_reason TEXT, state_version INTEGER, public_state_json TEXT, server_state_json TEXT,
    created_at_utc TEXT, started_at_utc TEXT, updated_at_utc TEXT, finished_at_utc TEXT
)');
$db->execute('CREATE TABLE mgw_match_players (
    match_id TEXT, seat INTEGER, player_ref TEXT, mgw_id TEXT, legacy_user_id TEXT,
    player_type TEXT, symbol TEXT, display_name TEXT, result TEXT, joined_at_utc TEXT, updated_at_utc TEXT,
    PRIMARY KEY (match_id, seat),
    FOREIGN KEY (match_id) REFERENCES mgw_matches(match_id),
    FOREIGN KEY (mgw_id) REFERENCES mgw_users(mgw_id)
)');
$db->execute('CREATE TABLE mgw_match_snapshots (
    snapshot_id INTEGER PRIMARY KEY AUTOINCREMENT, match_id TEXT, state_version INTEGER,
    public_state_json TEXT, server_state_json TEXT, created_at_utc TEXT,
    UNIQUE(match_id, state_version)
)');
$db->execute('CREATE TABLE mgw_match_player_snapshots (
    match_id TEXT, state_version INTEGER, player_ref TEXT, private_state_json TEXT, created_at_utc TEXT,
    PRIMARY KEY(match_id, state_version, player_ref)
)');
$db->execute('CREATE TABLE mgw_devices (
    device_id INTEGER PRIMARY KEY AUTOINCREMENT, mgw_id TEXT, device_key_hash TEXT, platform TEXT,
    first_seen_at_utc TEXT, last_seen_at_utc TEXT
)');
$db->execute('CREATE TABLE mgw_sessions (
    session_key_hash TEXT PRIMARY KEY, mgw_id TEXT, device_id INTEGER, provider TEXT,
    issued_at_utc TEXT, last_seen_at_utc TEXT, expires_at_utc TEXT, revoked_at_utc TEXT
)');
$db->execute('CREATE TABLE mgw_game_rating_outcomes (
    match_id TEXT PRIMARY KEY, anti_farming_limited INTEGER, points_requested INTEGER,
    points_delta INTEGER, outcome_code TEXT
)');
$db->execute('CREATE TABLE mgw_leaderboard_control (
    control_key TEXT PRIMARY KEY, max_credited_wins_same_opponent_day INTEGER
)');
$db->execute('CREATE TABLE mgw_moderation_actions (action_id TEXT PRIMARY KEY)');

(require $root . '/bot/database/migrations/20260819_0010_create_match_event_log.php')->up($db);
(require $root . '/bot/database/migrations/20260925_0064_create_antifraud_cases.php')->up($db);

$db->execute("INSERT INTO mgw_users VALUES ('MGW-A','Alpha','active'),('MGW-B','Beta','active')");
$db->execute("INSERT INTO mgw_leaderboard_control VALUES ('global',3)");
$db->execute("INSERT INTO mgw_game_rating_outcomes VALUES ('match_target',1,10,0,'rated_normal_win')");

$insertMatch = static function (PdoDatabaseConnection $db, string $id, string $start, string $finish, string $winner = 'player-a'): void {
    $db->execute(
        'INSERT INTO mgw_matches (
            match_id, game_type, room, status, board_size, bet, match_source, invite_id, source_match_id,
            turn_player_ref, winner_player_ref, finish_reason, state_version, public_state_json, server_state_json,
            created_at_utc, started_at_utc, updated_at_utc, finished_at_utc
         ) VALUES (
            :id, :game, :room, :status, :board, :bet, :source, NULL, NULL,
            NULL, :winner, :reason, :version, :public_state, :server_state,
            :created_at, :started_at, :updated_at, :finished_at
         )',
        [
            'id' => $id,
            'game' => 'chess',
            'room' => 'TEST',
            'status' => 'finished',
            'board' => 8,
            'bet' => 100,
            'source' => 'matchmaking',
            'winner' => $winner,
            'reason' => 'normal_win',
            'version' => $id === 'match_target' ? 3 : 1,
            'public_state' => '{}',
            'server_state' => '{}',
            'created_at' => $start,
            'started_at' => $start,
            'updated_at' => $finish,
            'finished_at' => $finish,
        ]
    );
    $db->execute(
        "INSERT INTO mgw_match_players VALUES
         (:id,0,'player-a','MGW-A',NULL,'human','white','Alpha','win',:start,:finish),
         (:id,1,'player-b','MGW-B',NULL,'human','black','Beta','loss',:start,:finish)",
        ['id' => $id, 'start' => $start, 'finish' => $finish]
    );
};

$insertMatch($db, 'pair_1', '2026-09-25 09:00:00.000000', '2026-09-25 09:10:00.000000');
$insertMatch($db, 'pair_2', '2026-09-25 11:00:00.000000', '2026-09-25 11:10:00.000000');
$insertMatch($db, 'pair_3', '2026-09-25 13:00:00.000000', '2026-09-25 13:10:00.000000');
$insertMatch($db, 'match_target', '2026-09-25 15:00:00.000000', '2026-09-25 15:10:00.000000');

$db->execute("INSERT INTO mgw_match_snapshots (match_id,state_version,public_state_json,server_state_json,created_at_utc) VALUES
    ('match_target',1,'{\"phase\":\"start\"}','{\"turn\":\"player-a\"}','2026-09-25 15:00:00.000000'),
    ('match_target',2,'{\"phase\":\"active\"}','{\"turn\":\"player-b\"}','2026-09-25 15:05:00.000000'),
    ('match_target',3,'{\"phase\":\"finished\"}','{\"winner\":\"player-a\"}','2026-09-25 15:10:00.000000')");
$db->execute("INSERT INTO mgw_match_player_snapshots VALUES
    ('match_target',2,'player-a','{\"hand\":[1]}','2026-09-25 15:05:00.000000'),
    ('match_target',2,'player-b','{\"hand\":[2]}','2026-09-25 15:05:00.000000')");

$insertEvent = static function (PdoDatabaseConnection $db, string $eventId, int $revision, int $version, string $type, string $time, ?string $actor): void {
    $db->execute(
        'INSERT INTO mgw_match_events (
            event_id,match_id,primary_revision,event_ordinal,snapshot_state_version,event_type,
            occurred_at_utc,actor_user_id,game_type,rules_version,engine_version,payload_json,
            before_state_sha256,after_state_sha256,retention_class,retain_until_utc,created_at_utc
         ) VALUES (
            :event_id,:match_id,:revision,0,:version,:type,:time,:actor,:game,
            :rules,:engine,:payload,:before_hash,:after_hash,:retention,NULL,:created
         )',
        [
            'event_id' => $eventId,
            'match_id' => 'match_target',
            'revision' => $revision,
            'version' => $version,
            'type' => $type,
            'time' => $time,
            'actor' => $actor,
            'game' => 'chess',
            'rules' => str_repeat('a', 64),
            'engine' => str_repeat('b', 64),
            'payload' => '{}',
            'before_hash' => $revision === 1 ? null : str_repeat('c', 64),
            'after_hash' => str_repeat('d', 64),
            'retention' => 'default',
            'created' => $time,
        ]
    );
};
$insertEvent($db, 'af_e1', 1, 1, 'match_started', '2026-09-25 15:00:00.000000', null);
$insertEvent($db, 'af_e2', 2, 2, 'move', '2026-09-25 15:05:00.000000', 'player-a');
$insertEvent($db, 'af_e3', 3, 3, 'result', '2026-09-25 15:10:00.000000', 'player-a');

$sharedHash = str_repeat('f', 64);
$db->execute("INSERT INTO mgw_devices (mgw_id,device_key_hash,platform,first_seen_at_utc,last_seen_at_utc) VALUES
    ('MGW-A',:hash,'telegram','2026-09-20 10:00:00.000000','2026-09-25 15:09:00.000000'),
    ('MGW-B',:hash,'telegram','2026-09-21 10:00:00.000000','2026-09-25 15:09:30.000000')", ['hash' => $sharedHash]);
$devices = $db->fetchAll('SELECT device_id,mgw_id FROM mgw_devices ORDER BY device_id');
$deviceA = (int)$devices[0]['device_id'];
$deviceB = (int)$devices[1]['device_id'];
$db->execute("INSERT INTO mgw_sessions VALUES
    (:s1,'MGW-A',:d1,'telegram','2026-09-25 14:00:00.000000','2026-09-25 15:09:00.000000','2026-09-25 18:00:00.000000',NULL),
    (:s2,'MGW-B',:d2,'telegram','2026-09-25 14:30:00.000000','2026-09-25 15:09:30.000000','2026-09-25 18:30:00.000000',NULL)",
    ['s1' => str_repeat('1',64), 'd1' => $deviceA, 's2' => str_repeat('2',64), 'd2' => $deviceB]
);

$reader = new MatchReplayReader($db);
$service = new AntiFraudCaseService($db, $reader);
$review = $service->review('match_target');

$assert(($review['policy']['auto_ban'] ?? true) === false, 'Anti-fraud signals must never auto-ban.');
$assert(($review['policy']['single_signal_sanction'] ?? true) === false, 'One signal must never create a sanction.');
$assert(count($review['pair_history'] ?? []) === 4, 'Pair history must include all four pair matches.');

$signalCodes = array_column($review['signals'] ?? [], 'code');
$assert(in_array('shared_device_match_window', $signalCodes, true), 'Shared device in match window must be detected.');
$assert(in_array('repeat_pair_24h', $signalCodes, true), 'Frequent pair matches must be detected.');
$assert(in_array('rating_antifarming_limited', $signalCodes, true), 'Existing rating anti-farming signal must be surfaced.');
$assert(($review['replay']['diagnostics']['replayable'] ?? false) === true, 'Replay chain must remain reconstructable.');
$assert(($review['timing']['player-a']['action_count'] ?? 0) === 2, 'Timing summary must be informational and actor-specific.');

$case = $service->createCase('match_target', 'telegram:admin-1');
$assert(($case['status'] ?? '') === 'open', 'Created anti-fraud case must start open.');
$assert(($case['signal_count'] ?? 0) >= 0, 'Case normalization must be stable.');
$assert(count($case['signals'] ?? []) >= 3, 'Detected signals must be frozen into the case.');
$caseAgain = $service->createCase('match_target', 'telegram:admin-1');
$assert(($caseAgain['case_id'] ?? '') === ($case['case_id'] ?? ''), 'One match must own one durable anti-fraud case.');

$reviewing = $service->takeInReview((string)$case['case_id'], 'telegram:admin-2');
$assert(($reviewing['status'] ?? '') === 'reviewing', 'Take in review must advance the case one-way.');
$assert(($reviewing['owner_ref'] ?? '') === 'telegram:admin-2', 'Reviewer must be persisted.');

$closed = $service->resolve((string)$case['case_id'], 'monitor', 'Повторные игры требуют наблюдения.', 'telegram:admin-2');
$assert(($closed['status'] ?? '') === 'closed', 'Decision must close the case.');
$assert(($closed['decision'] ?? '') === 'monitor', 'Decision must be durable.');
$assert(!empty($closed['closed_at']), 'Closed anti-fraud case must have closed_at.');

$active = $service->snapshot(['mode' => 'active']);
$closedQueue = $service->snapshot(['mode' => 'closed']);
$assert(count($active['cases'] ?? []) === 0, 'Closed case must leave active queue.');
$assert(count($closedQueue['cases'] ?? []) === 1, 'Closed case must remain in reviewed archive.');
$assert((int)$db->fetchValue('SELECT COUNT(*) FROM mgw_moderation_actions') === 0, 'Anti-fraud review must not create moderation sanctions automatically.');

$blockedBackwards = false;
try {
    $service->takeInReview((string)$case['case_id'], 'telegram:admin-3');
} catch (AntiFraudCaseException $error) {
    $blockedBackwards = $error->reason === 'case_terminal';
}
$assert($blockedBackwards, 'Closed case must be read-only and cannot return to review.');

fwrite(STDOUT, "MVP-22.4 AntiFraudWorkflowTest OK: {$assertions} assertions.\n");
