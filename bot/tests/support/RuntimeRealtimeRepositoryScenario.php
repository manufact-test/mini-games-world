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

$repeat = $repo->synchronize($data);
$assertSame(1, $repeat['games']['unchanged_count'], 'Repeat match unchanged');
$assertSame(1, $repeat['queue']['unchanged_count'], 'Repeat queue unchanged');
$assertSame(true, $repo->auditParity($data)['ok'], 'Read-only audit');

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
    2,
    (int)$db->fetchAll('SELECT COUNT(*) c FROM mgw_match_snapshots')[0]['c'],
    'Snapshot appended'
);

$data['queue'] = [];
$withoutQueue = $repo->synchronize($data);
$assertSame(1, $withoutQueue['queue']['deleted_count'], 'Stale queue deleted');
$assertSame([], $repo->auditParity($data)['blockers'], 'Final parity blockers');

$db->execute(
    'UPDATE mgw_match_players SET player_ref=:ref WHERE match_id=:id AND seat=0',
    ['ref' => 'altered-player', 'id' => 'game-1']
);
$assertThrows(
    static fn() => $repo->synchronize($data),
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
