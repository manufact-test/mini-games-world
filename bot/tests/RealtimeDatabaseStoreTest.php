<?php
declare(strict_types=1);

$databaseDir = dirname(__DIR__) . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
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
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($contains))) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('RealtimeDatabaseStoreTest requires pdo_sqlite.');
}

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$migrated = $runner->migrate(false);
$assertSame(3, $migrated['executed_count'], 'Realtime store test must create all three schemas');

$now = '2026-07-16T20:00:00+00:00';
foreach ([
    ['mgw_a', 'Player A'],
    ['mgw_b', 'Player B'],
] as [$mgwId, $name]) {
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
            'created_at' => '2026-07-16 20:00:00.000000',
            'updated_at' => '2026-07-16 20:00:00.000000',
            'last_seen_at' => '2026-07-16 20:00:00.000000',
        ]
    );
}

$store = new RealtimeDatabaseStore($database);
$playerA = RealtimeDatabaseStore::playerReference('mgw_a', '1001');
$playerB = RealtimeDatabaseStore::playerReference('mgw_b', '1002');
$assertSame('mgw:mgw_a', $playerA, 'MGW identity must have priority over legacy identity');
$assertSame('legacy:1003', RealtimeDatabaseStore::playerReference(null, '1003'), 'Legacy identity must remain representable');
$assertSame('bot:bot-1', RealtimeDatabaseStore::playerReference(null, null, 'bot-1'), 'Bot identity must remain representable');

$match = [
    'match_id' => 'game_hidden_1',
    'game_type' => 'battleship',
    'room' => 'match',
    'status' => 'active',
    'board_size' => 10,
    'bet' => 10,
    'match_source' => 'matchmaking',
    'turn_player_ref' => $playerA,
    'state_version' => 1,
    'public_state' => ['turn' => $playerA, 'shots' => []],
    'server_state' => ['rng_seed' => 'server-only', 'phase' => 'playing'],
    'created_at_utc' => $now,
    'started_at_utc' => $now,
    'updated_at_utc' => $now,
];
$players = [
    [
        'seat' => 0,
        'player_ref' => $playerA,
        'mgw_id' => 'mgw_a',
        'legacy_user_id' => '1001',
        'display_name' => 'Player A',
        'symbol' => 'A',
        'joined_at_utc' => $now,
    ],
    [
        'seat' => 1,
        'player_ref' => $playerB,
        'mgw_id' => 'mgw_b',
        'legacy_user_id' => '1002',
        'display_name' => 'Player B',
        'symbol' => 'B',
        'joined_at_utc' => $now,
    ],
];
$privateStates = [
    $playerA => ['ships' => ['A1', 'A2'], 'hand' => ['private-a']],
    $playerB => ['ships' => ['J9', 'J10'], 'hand' => ['private-b']],
];

$saved = $store->saveMatchSnapshot($match, $players, $privateStates);
$assertSame(1, $saved['state_version'], 'Initial match snapshot must persist version 1');
$assertSame(2, $saved['private_state_count'], 'Both private player states must persist separately');

$viewA = $store->loadMatchForPlayer('game_hidden_1', $playerA);
$assertTrue(is_array($viewA), 'Player view must load the match');
$assertSame(['ships' => ['A1', 'A2'], 'hand' => ['private-a']], $viewA['private_state'], 'Player A must receive only their private state');
$assertTrue(!array_key_exists('server_state', $viewA['match']), 'Player view must never include server-only state');
$assertTrue(!str_contains(json_encode($viewA, JSON_THROW_ON_ERROR), 'private-b'), 'Player A response must not contain Player B hidden state');

$viewB = $store->loadMatchForPlayer('game_hidden_1', $playerB);
$assertSame(['ships' => ['J9', 'J10'], 'hand' => ['private-b']], $viewB['private_state'], 'Player B must receive only their private state');
$assertTrue(!str_contains(json_encode($viewB, JSON_THROW_ON_ERROR), 'private-a'), 'Player B response must not contain Player A hidden state');

$server = $store->loadServerMatch('game_hidden_1');
$assertSame('server-only', $server['server_state']['rng_seed'], 'Trusted server load must include server-only state');
$assertSame(2, count($server['private_states']), 'Trusted server load must include both private states');

$store->saveMatchSnapshot($match, $players, $privateStates);
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_match_snapshots'), 'Repeated identical snapshot must remain idempotent');
$assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_match_player_snapshots'), 'Repeated private snapshots must remain idempotent');

$conflicting = $match;
$conflicting['public_state'] = ['turn' => $playerB, 'shots' => ['changed']];
$assertThrows(
    static fn() => $store->saveMatchSnapshot($conflicting, $players, $privateStates),
    'reused with different state',
    'A state version may not be overwritten with different content'
);
$afterConflict = $store->loadMatchForPlayer('game_hidden_1', $playerA);
$assertSame($playerA, $afterConflict['match']['public_state']['turn'], 'Conflicting snapshot transaction must roll back the current row');

$versionTwo = $match;
$versionTwo['state_version'] = 2;
$versionTwo['turn_player_ref'] = $playerB;
$versionTwo['public_state'] = ['turn' => $playerB, 'shots' => ['A1']];
$versionTwo['updated_at_utc'] = '2026-07-16T20:01:00+00:00';
$store->saveMatchSnapshot($versionTwo, $players, [
    $playerA => ['ships' => ['A1'], 'hand' => ['private-a-2']],
    $playerB => ['ships' => ['J9'], 'hand' => ['private-b-2']],
]);
$assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_match_snapshots'), 'A new state version must append a snapshot');
$assertSame('private-a-2', $store->loadMatchForPlayer('game_hidden_1', $playerA)['private_state']['hand'][0], 'Player view must follow the current version');

$queue = $store->upsertQueueEntry([
    'queue_id' => 'queue-a',
    'player_ref' => $playerA,
    'mgw_id' => 'mgw_a',
    'legacy_user_id' => '1001',
    'game_type' => 'chess',
    'room' => 'match',
    'bet' => 10,
    'board_size' => 8,
    'created_at_utc' => $now,
    'updated_at_utc' => $now,
]);
$assertSame('queue-a', $queue['queue_id'], 'Queue insert must preserve its ID');
$queueUpdated = $store->upsertQueueEntry([
    'queue_id' => 'ignored-new-id',
    'player_ref' => $playerA,
    'mgw_id' => 'mgw_a',
    'legacy_user_id' => '1001',
    'game_type' => 'go',
    'room' => 'match',
    'bet' => 10,
    'board_size' => 9,
    'updated_at_utc' => '2026-07-16T20:02:00+00:00',
]);
$assertSame('queue-a', $queueUpdated['queue_id'], 'One player must keep one queue row');
$assertSame('go', $queueUpdated['game_type'], 'Queue parameters must update atomically');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_match_queue'), 'Queue uniqueness must prevent duplicate player rows');
$assertSame(1, $store->removeQueueEntry($playerA), 'Queue removal must delete the active row');
$assertSame(null, $store->findQueueEntry($playerA), 'Removed queue row must not be returned');

$invite = $store->upsertInvite([
    'invite_id' => 'invite-1',
    'token' => 'token-1',
    'status' => 'pending',
    'source' => 'direct',
    'inviter_ref' => $playerA,
    'inviter_mgw_id' => 'mgw_a',
    'inviter_legacy_user_id' => '1001',
    'inviter_name' => 'Player A',
    'invitee_ref' => $playerB,
    'invitee_mgw_id' => 'mgw_b',
    'invitee_legacy_user_id' => '1002',
    'invitee_name' => 'Player B',
    'game_type' => 'chess',
    'game_title' => 'Шахматы',
    'room' => 'match',
    'bet' => 10,
    'board_size' => 8,
    'created_at_utc' => $now,
    'updated_at_utc' => $now,
    'expires_at_utc' => '2026-07-16T20:15:00+00:00',
]);
$assertSame(1, (int)$invite['version'], 'New invite must start at version 1');
$invite['status'] = 'accepted';
$invite['updated_at_utc'] = '2026-07-16T20:03:00+00:00';
$updatedInvite = $store->upsertInvite($invite);
$assertSame(2, (int)$updatedInvite['version'], 'Invite updates must increment the optimistic version');
$assertSame('accepted', $updatedInvite['status'], 'Invite status must update');

$assertTrue($store->appendInviteEvent('invite-1', 'accepted:1002', 'accepted', $playerB, ['source' => 'test'], $now), 'First invite event must insert');
$assertSame(false, $store->appendInviteEvent('invite-1', 'accepted:1002', 'accepted', $playerB, ['source' => 'test'], $now), 'Repeated identical invite event must be idempotent');
$assertThrows(
    static fn() => $store->appendInviteEvent('invite-1', 'accepted:1002', 'declined', $playerB, ['source' => 'test'], $now),
    'reused with different content',
    'An invite event key may not be reused for another event'
);
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_invite_events'), 'Invite event dedupe must keep one row');

$notification = $store->addNotification([
    'notification_id' => 'notification-1',
    'event_key' => 'invite:invite-1:received:1002',
    'recipient_ref' => $playerB,
    'mgw_id' => 'mgw_b',
    'legacy_user_id' => '1002',
    'type' => 'invite_received',
    'title' => 'Вас пригласили сыграть',
    'message' => 'Player A приглашает вас в «Шахматы».',
    'tone' => 'info',
    'invite_token' => 'token-1',
    'payload' => ['invite_id' => 'invite-1'],
    'created_at_utc' => $now,
]);
$duplicateNotification = $store->addNotification([
    'notification_id' => 'notification-duplicate',
    'event_key' => 'invite:invite-1:received:1002',
    'recipient_ref' => $playerB,
    'mgw_id' => 'mgw_b',
    'legacy_user_id' => '1002',
    'type' => 'invite_received',
    'title' => 'Duplicate ignored',
    'message' => 'Duplicate ignored',
    'created_at_utc' => $now,
]);
$assertSame($notification['notification_id'], $duplicateNotification['notification_id'], 'Notification event key must be idempotent');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_notifications'), 'Notification dedupe must keep one row');
$assertTrue($store->markNotificationRead('notification-1', $playerB, $now), 'Unread notification must be marked read once');
$assertSame(false, $store->markNotificationRead('notification-1', $playerB, $now), 'Already read notification must not mutate twice');
$feed = $store->listNotifications($playerB);
$assertSame('invite-1', $feed[0]['payload']['invite_id'], 'Notification payload must round-trip as JSON');

fwrite(STDOUT, "RealtimeDatabaseStoreTest: {$assertions} assertions passed\n");
