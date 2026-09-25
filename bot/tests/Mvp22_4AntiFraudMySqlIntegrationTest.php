<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/DatabaseConnectionInterface.php';
require_once __DIR__ . '/../database/DatabaseExceptionClassifier.php';
require_once __DIR__ . '/../database/PdoDatabaseConnection.php';
require_once __DIR__ . '/../database/DatabaseMigrationInterface.php';
require_once __DIR__ . '/../replay/MatchReplayReader.php';
require_once __DIR__ . '/../antifraud/AntiFraudCaseService.php';

$dsn = trim((string)getenv('MGW_ANTIFRAUD_MYSQL_DSN'));
$user = (string)getenv('MGW_ANTIFRAUD_MYSQL_USER');
$pass = (string)getenv('MGW_ANTIFRAUD_MYSQL_PASS');
if ($dsn === '') {
    fwrite(STDOUT, "Mvp22_4AntiFraudMySqlIntegrationTest skipped: no DSN.\n");
    return;
}

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$db = new PdoDatabaseConnection($pdo);

$tables = [
    'mgw_antifraud_case_events',
    'mgw_antifraud_case_signals',
    'mgw_antifraud_cases',
    'mgw_match_events',
    'mgw_match_player_snapshots',
    'mgw_match_snapshots',
    'mgw_game_rating_outcomes',
    'mgw_leaderboard_control',
    'mgw_sessions',
    'mgw_devices',
    'mgw_match_players',
    'mgw_matches',
    'mgw_users',
];
$db->execute('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) $db->execute('DROP TABLE IF EXISTS ' . $table);
$db->execute('SET FOREIGN_KEY_CHECKS=1');

$db->execute(<<<'SQL'
CREATE TABLE mgw_users (
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    display_name VARCHAR(80) NOT NULL,
    status VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_matches (
    match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    game_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    room VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    board_size SMALLINT UNSIGNED NOT NULL,
    bet BIGINT UNSIGNED NOT NULL DEFAULT 0,
    match_source VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    invite_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    turn_player_ref VARCHAR(255) COLLATE utf8mb4_bin NULL,
    winner_player_ref VARCHAR(255) COLLATE utf8mb4_bin NULL,
    finish_reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    state_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    public_state_json JSON NULL,
    server_state_json JSON NULL,
    created_at_utc DATETIME(6) NOT NULL,
    started_at_utc DATETIME(6) NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    finished_at_utc DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_match_players (
    match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    seat SMALLINT UNSIGNED NOT NULL,
    player_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    player_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'human',
    symbol VARCHAR(32) NULL,
    display_name VARCHAR(80) NULL,
    result VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    joined_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (match_id, seat),
    UNIQUE KEY uq_test_match_player_ref (match_id, player_ref),
    CONSTRAINT fk_test_match_player_match FOREIGN KEY (match_id)
        REFERENCES mgw_matches (match_id) ON DELETE CASCADE,
    CONSTRAINT fk_test_match_player_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_match_snapshots (
    snapshot_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state_version BIGINT UNSIGNED NOT NULL,
    public_state_json JSON NULL,
    server_state_json JSON NULL,
    created_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_test_match_snapshot_version (match_id, state_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_match_player_snapshots (
    match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state_version BIGINT UNSIGNED NOT NULL,
    player_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    private_state_json JSON NULL,
    created_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (match_id, state_version, player_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_devices (
    device_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    device_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    platform VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    first_seen_at_utc DATETIME(6) NOT NULL,
    last_seen_at_utc DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_sessions (
    session_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    device_id BIGINT UNSIGNED NULL,
    provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    issued_at_utc DATETIME(6) NOT NULL,
    last_seen_at_utc DATETIME(6) NOT NULL,
    expires_at_utc DATETIME(6) NOT NULL,
    revoked_at_utc DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_game_rating_outcomes (
    match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    anti_farming_limited TINYINT(1) NOT NULL DEFAULT 0,
    points_requested SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    points_delta SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    outcome_code VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_leaderboard_control (
    control_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    max_credited_wins_same_opponent_day SMALLINT UNSIGNED NOT NULL DEFAULT 3
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

(require __DIR__ . '/../database/migrations/20260819_0010_create_match_event_log.php')->up($db);
(require __DIR__ . '/../database/migrations/20260925_0064_create_antifraud_cases.php')->up($db);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$db->execute("INSERT INTO mgw_users VALUES ('MGW-A','Alpha','active'),('MGW-B','Beta','active')");
$db->execute("INSERT INTO mgw_leaderboard_control VALUES ('global',3)");

$insertMatch = static function (PdoDatabaseConnection $db, string $id, string $start, string $finish): void {
    $db->execute(
        'INSERT INTO mgw_matches (
            match_id,game_type,room,status,board_size,bet,match_source,invite_id,source_match_id,
            turn_player_ref,winner_player_ref,finish_reason,state_version,public_state_json,server_state_json,
            created_at_utc,started_at_utc,updated_at_utc,finished_at_utc
         ) VALUES (
            :id,:game,:room,:status,:board,:bet,:source,NULL,NULL,NULL,:winner,:reason,:version,:public,:server,
            :created,:started,:updated,:finished
         )',
        [
            'id'=>$id,'game'=>'chess','room'=>'TEST','status'=>'finished','board'=>8,'bet'=>100,
            'source'=>'matchmaking','winner'=>'player-a','reason'=>'normal_win',
            'version'=>$id === 'mysql_target' ? 2 : 1,'public'=>'{}','server'=>'{}',
            'created'=>$start,'started'=>$start,'updated'=>$finish,'finished'=>$finish,
        ]
    );
    $db->execute(
        "INSERT INTO mgw_match_players (
            match_id,seat,player_ref,mgw_id,legacy_user_id,player_type,symbol,display_name,result,joined_at_utc,updated_at_utc
         ) VALUES
            (:id_a,0,'player-a','MGW-A',NULL,'human','white','Alpha','win',:start_a,:finish_a),
            (:id_b,1,'player-b','MGW-B',NULL,'human','black','Beta','loss',:start_b,:finish_b)",
        [
            'id_a'=>$id,'start_a'=>$start,'finish_a'=>$finish,
            'id_b'=>$id,'start_b'=>$start,'finish_b'=>$finish,
        ]
    );
};

$insertMatch($db, 'mysql_pair_1', '2026-09-25 09:00:00.000000', '2026-09-25 09:10:00.000000');
$insertMatch($db, 'mysql_pair_2', '2026-09-25 11:00:00.000000', '2026-09-25 11:10:00.000000');
$insertMatch($db, 'mysql_pair_3', '2026-09-25 13:00:00.000000', '2026-09-25 13:10:00.000000');
$insertMatch($db, 'mysql_target', '2026-09-25 15:00:00.000000', '2026-09-25 15:10:00.000000');

$db->execute("INSERT INTO mgw_game_rating_outcomes VALUES ('mysql_target',1,10,0,'rated_normal_win')");
$db->execute("INSERT INTO mgw_match_snapshots (match_id,state_version,public_state_json,server_state_json,created_at_utc) VALUES
    ('mysql_target',1,'{\"phase\":\"start\"}','{\"turn\":\"player-a\"}','2026-09-25 15:00:00.000000'),
    ('mysql_target',2,'{\"phase\":\"finished\"}','{\"winner\":\"player-a\"}','2026-09-25 15:10:00.000000')");
$db->execute("INSERT INTO mgw_match_player_snapshots VALUES
    ('mysql_target',2,'player-a','{\"private\":1}','2026-09-25 15:10:00.000000'),
    ('mysql_target',2,'player-b','{\"private\":2}','2026-09-25 15:10:00.000000')");

$event = static function (PdoDatabaseConnection $db, string $id, int $revision, int $version, string $type, string $time, ?string $actor): void {
    $db->execute(
        'INSERT INTO mgw_match_events (
            event_id,match_id,primary_revision,event_ordinal,snapshot_state_version,event_type,occurred_at_utc,
            actor_user_id,game_type,rules_version,engine_version,payload_json,before_state_sha256,after_state_sha256,
            retention_class,retain_until_utc,created_at_utc
         ) VALUES (
            :id,:match,:revision,0,:version,:type,:time,:actor,:game,:rules,:engine,:payload,:before,:after,:retention,NULL,:created
         )',
        [
            'id'=>$id,'match'=>'mysql_target','revision'=>$revision,'version'=>$version,'type'=>$type,'time'=>$time,'actor'=>$actor,
            'game'=>'chess','rules'=>str_repeat('a',64),'engine'=>str_repeat('b',64),'payload'=>'{}',
            'before'=>$revision === 1 ? null : str_repeat('c',64),'after'=>str_repeat('d',64),'retention'=>'default','created'=>$time,
        ]
    );
};
$event($db,'mysql_e1',1,1,'match_started','2026-09-25 15:00:00.000000',null);
$event($db,'mysql_e2',2,2,'result','2026-09-25 15:10:00.000000','player-a');

$sharedHash = str_repeat('f',64);
$db->execute(
    "INSERT INTO mgw_devices (mgw_id,device_key_hash,platform,first_seen_at_utc,last_seen_at_utc) VALUES
     ('MGW-A',:hash_a,'telegram','2026-09-20 00:00:00.000000','2026-09-25 15:09:00.000000'),
     ('MGW-B',:hash_b,'telegram','2026-09-20 00:00:00.000000','2026-09-25 15:09:30.000000')",
    ['hash_a'=>$sharedHash,'hash_b'=>$sharedHash]
);
$devices = $db->fetchAll('SELECT device_id,mgw_id FROM mgw_devices ORDER BY device_id');
$db->execute(
    "INSERT INTO mgw_sessions (
        session_key_hash,mgw_id,device_id,provider,issued_at_utc,last_seen_at_utc,expires_at_utc,revoked_at_utc
     ) VALUES
        (:s1,'MGW-A',:d1,'telegram','2026-09-25 14:00:00.000000','2026-09-25 15:09:00.000000','2026-09-25 18:00:00.000000',NULL),
        (:s2,'MGW-B',:d2,'telegram','2026-09-25 14:20:00.000000','2026-09-25 15:09:30.000000','2026-09-25 18:20:00.000000',NULL)",
    [
        's1'=>str_repeat('1',64),'d1'=>(int)$devices[0]['device_id'],
        's2'=>str_repeat('2',64),'d2'=>(int)$devices[1]['device_id'],
    ]
);

$service = new AntiFraudCaseService($db, new MatchReplayReader($db));
$review = $service->review('mysql_target');
$codes = array_column($review['signals'], 'code');
$assert(($review['policy']['auto_ban'] ?? true) === false, 'MySQL anti-fraud review must never auto-ban.');
$assert(in_array('shared_device_match_window',$codes,true), 'MySQL shared-device match-window signal must be detected.');
$assert(in_array('repeat_pair_24h',$codes,true), 'MySQL repeat-pair signal must be detected.');
$assert(in_array('rating_antifarming_limited',$codes,true), 'MySQL rating anti-farming signal must be surfaced.');
$assert(count($review['pair_history']) === 4, 'MySQL pair history must include target and previous pair matches.');
$assert(($review['replay']['players'][0]['mgw_id'] ?? '') === 'MGW-A', 'Replay must expose canonical MGW player identity on MySQL.');

$case = $service->createCase('mysql_target','telegram:mysql-admin-one');
$assert(($case['status'] ?? '') === 'open', 'MySQL case must start open.');
$reviewing = $service->takeInReview((string)$case['case_id'],'telegram:mysql-admin-two');
$assert(($reviewing['status'] ?? '') === 'reviewing', 'MySQL case must advance to reviewing.');

$monitoring = $service->resolve((string)$case['case_id'],'monitor','Watch for repeated pair activity.','telegram:mysql-admin-two');
$assert(($monitoring['status'] ?? '') === 'monitoring', 'MySQL monitor decision must remain active.');
$assert(empty($monitoring['closed_at']), 'MySQL monitoring case must not have closed_at.');

$resumed = $service->takeInReview((string)$case['case_id'],'telegram:mysql-admin-three');
$assert(($resumed['status'] ?? '') === 'reviewing', 'MySQL monitoring case must resume to reviewing.');

$closed = $service->resolve((string)$case['case_id'],'cleared','Signals reviewed; no violation confirmed.','telegram:mysql-admin-three');
$assert(($closed['status'] ?? '') === 'closed', 'MySQL case resolution must persist terminal state.');
$assert(($closed['decision'] ?? '') === 'cleared', 'MySQL decision must persist.');
$assert(!empty($closed['closed_at']), 'MySQL closed case must persist closed_at.');

$active = $service->snapshot(['mode'=>'active']);
$processed = $service->snapshot(['mode'=>'closed']);
$assert(count($active['cases']) === 0, 'MySQL closed case must leave active queue.');
$assert(count($processed['cases']) === 1, 'MySQL closed case must remain in processed archive.');
$assert(count($processed['recent_matches']) >= 4, 'MySQL anti-fraud snapshot must expose recent matches for manual selection.');
$assert($db->fetchValue("SHOW TABLES LIKE 'mgw_moderation_actions'") === null, 'Anti-fraud case workflow must not require or create moderation sanctions.');

$db->execute('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) $db->execute('DROP TABLE IF EXISTS ' . $table);
$db->execute('SET FOREIGN_KEY_CHECKS=1');

fwrite(STDOUT, "MVP-22.4 anti-fraud MySQL 8.4 integration OK ({$assertions} assertions).\n");
