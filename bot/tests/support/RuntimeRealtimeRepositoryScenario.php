<?php
declare(strict_types=1);

$first = $repo->synchronize($data);
$assertSame(1, $first['games']['created_count'], 'Create match');
$assertSame(1, $first['queue']['created_count'], 'Create queue');
$assertSame(true, $first['parity'], 'Initial parity');

$match = $db->fetchAll(
    'SELECT state_version,public_state_json,server_state_json FROM mgw_matches WHERE match_id=:id',
    ['id' => 'game-1']
)[0];
$assertSame(1, (int)$match['state_version'], 'Initial version');
$assertSame(null, $match['public_state_json'], 'No legacy payload in public state');
$assertSame(
    '---------',
    json_decode((string)$match['server_state_json'], true, 512, JSON_THROW_ON_ERROR)['board'],
    'Server state preserved'
);
$assertSame(
    'user:1001',
    (string)$db->fetchAll('SELECT queue_id FROM mgw_match_queue')[0]['queue_id'],
    'Stable fallback queue ID'
);
$assertSame(
    1,
    (int)$db->fetchAll('SELECT COUNT(*) c FROM mgw_match_snapshots')[0]['c'],
    'One snapshot'
);

// A second HTTP request gets a fresh DB connection object; the repository's
// static synchronize cache is intentionally request-scoped.
$repeatDb = new RealtimeCountingDatabaseConnection($rawDb);
$repeatRepo = new RuntimeRealtimeRepository($config, new RuntimeStorageRouter($config), $repeatDb);
$repeat = $repeatRepo->synchronize($data);
$assertSame(1, $repeat['games']['unchanged_count'], 'Repeat match unchanged');
$assertSame(1, $repeat['queue']['unchanged_count'], 'Repeat queue unchanged');
$assertSame(true, $repeatRepo->auditParity($data)['ok'], 'Read-only audit');

// Reproduce a large retained-terminal history while preserving the exact
// fail-closed parity semantics. Query round-trips must not grow per match.
for ($index = 1; $index <= 40; $index++) {
    $retainedId = sprintf('retained-%03d', $index);
    $db->execute(
        'INSERT INTO mgw_matches (
            match_id,game_type,room,status,board_size,bet,match_source,invite_id,source_match_id,
            turn_player_ref,winner_player_ref,finish_reason,state_version,public_state_json,server_state_json,
            created_at_utc,started_at_utc,updated_at_utc,finished_at_utc
         )
         SELECT :match_id,game_type,room,:status,board_size,bet,match_source,invite_id,:source_match_id,
                turn_player_ref,winner_player_ref,:finish_reason,state_version,public_state_json,server_state_json,
                created_at_utc,started_at_utc,updated_at_utc,updated_at_utc
         FROM mgw_matches WHERE match_id = :source_id',
        [
            'match_id' => $retainedId,
            'status' => 'finished',
            'source_match_id' => $retainedId,
            'finish_reason' => 'normal_win',
            'source_id' => 'game-1',
        ]
    );
    $db->execute(
        'INSERT INTO mgw_match_players (
            match_id,seat,player_ref,mgw_id,legacy_user_id,player_type,symbol,display_name,result,
            joined_at_utc,updated_at_utc
         )
         SELECT :match_id,seat,player_ref,mgw_id,legacy_user_id,player_type,symbol,display_name,result,
                joined_at_utc,updated_at_utc
         FROM mgw_match_players WHERE match_id = :source_id',
        ['match_id' => $retainedId, 'source_id' => 'game-1']
    );
    $db->execute(
        'INSERT INTO mgw_match_snapshots (
            match_id,state_version,public_state_json,server_state_json,created_at_utc
         )
         SELECT :match_id,state_version,public_state_json,server_state_json,created_at_utc
         FROM mgw_match_snapshots
         WHERE match_id = :source_id AND state_version = 1',
        ['match_id' => $retainedId, 'source_id' => 'game-1']
    );
}

$scaledDb = new RealtimeCountingDatabaseConnection($rawDb);
$scaledRepo = new RuntimeRealtimeRepository($config, new RuntimeStorageRouter($config), $scaledDb);

$scaledDb->resetFetchAllCount();
$scaleAudit = $scaledRepo->auditParity($data);
$assertSame(true, $scaleAudit['ok'], 'Scaled retained terminal audit parity');
$assertSame(41, $scaleAudit['database_total_game_count'], 'Scaled audit keeps all DB matches in scope');
$assertSame(40, $scaleAudit['retained_terminal_game_count'], 'Scaled audit validates every retained terminal match');
$assertLessThanOrEqual(
    6,
    $scaledDb->fetchAllCount(),
    'Realtime audit DB reads must stay constant as retained terminal history grows'
);

$scaledDb->resetFetchAllCount();
$scaleSync = $scaledRepo->synchronize($data);
$assertSame(true, $scaleSync['parity'], 'Scaled synchronize parity');
$assertSame(40, $scaleSync['games']['retained_terminal_count'], 'Scaled synchronize retains terminal history');
$assertLessThanOrEqual(
    10,
    $scaledDb->fetchAllCount(),
    'Realtime synchronize DB reads must stay constant as retained terminal history grows'
);

$data['games']['game-1']['board'] = 'X--------';
$data['games']['game-1']['turn'] = 'bot_runtime_1';
$data['games']['game-1']['updated_at'] = '2026-07-18T19:21:00+00:00';
$changed = $repo->synchronize($data);
$assertSame(1, $changed['games']['updated_count'], 'Changed game updated');
$assertSame(
    2,
    (int)$db->fetchAll('SELECT state_version FROM mgw_matches WHERE match_id=:id', ['id' => 'game-1'])[0]['state_version'],
    'Version advanced'
);
$assertSame(
    42,
    (int)$db->fetchAll('SELECT COUNT(*) c FROM mgw_match_snapshots')[0]['c'],
    'Snapshot appended without altering retained terminal snapshots'
);

$data['queue'] = [];
$withoutQueue = $repo->synchronize($data);
$assertSame(1, $withoutQueue['queue']['deleted_count'], 'Stale queue deleted');
$assertSame([], $repo->auditParity($data)['blockers'], 'Final parity blockers');

$db->execute(
    'UPDATE mgw_match_players SET player_ref=:ref WHERE match_id=:id AND seat=0',
    ['ref' => 'altered-player', 'id' => 'game-1']
);
$alteredDb = new RealtimeCountingDatabaseConnection($rawDb);
$alteredRepo = new RuntimeRealtimeRepository($config, new RuntimeStorageRouter($config), $alteredDb);
$assertThrows(
    static fn() => $alteredRepo->synchronize($data),
    'immutable player identity',
    'Altered player must fail closed'
);

$disabled = $config;
$disabled['feature_flags']['database_runtime']['modules']['realtime'] = false;
$disabledRepo = new RuntimeRealtimeRepository($disabled, new RuntimeStorageRouter($disabled), $db);
$assertThrows(
    static fn() => $disabledRepo->auditParity($data),
    'requires accounts and realtime',
    'Disabled route must fail'
);
