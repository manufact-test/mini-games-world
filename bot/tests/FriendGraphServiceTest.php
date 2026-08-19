<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$databaseDir = $root . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';
require $root . '/social/FriendGraphService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('FriendGraphServiceTest requires pdo_sqlite.');
}

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
$assertReason = static function (callable $callback, string $reason, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (FriendGraphException $error) {
        if ($error->reason !== $reason) {
            throw new RuntimeException($message . ': expected reason ' . $reason . ', got ' . $error->reason, 0, $error);
        }
        return;
    }
    throw new RuntimeException($message . ': expected FriendGraphException.');
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$assertSame(12, $runner->migrate(false)['executed_count'], 'Social graph test must apply all current migrations');

$now = '2026-08-19 15:00:00.000000';
$users = [
    'a' => ['id' => 'MGW-000000000000000A', 'nickname' => 'Alpha'],
    'b' => ['id' => 'MGW-000000000000000B', 'nickname' => 'Bravo'],
    'c' => ['id' => 'MGW-000000000000000C', 'nickname' => 'Charlie'],
    'd' => ['id' => 'MGW-000000000000000D', 'nickname' => 'Delta'],
];
foreach ($users as $user) {
    $database->execute(
        'INSERT INTO mgw_users (
            mgw_id, status, nickname, display_name, username,
            equipped_avatar_item_id, created_at_utc, updated_at_utc, last_seen_at_utc
         ) VALUES (
            :mgw_id, :status, :nickname, :display_name, NULL,
            :avatar, :created_at, :updated_at, :last_seen_at
         )',
        [
            'mgw_id' => $user['id'],
            'status' => 'active',
            'nickname' => $user['nickname'],
            'display_name' => 'Provider ' . $user['nickname'],
            'avatar' => 'starter-default-01',
            'created_at' => $now,
            'updated_at' => $now,
            'last_seen_at' => $now,
        ]
    );
}

$service = new FriendGraphService($database);

$lookupByNickname = $service->lookupExact($users['a']['id'], 'Bravo');
$assertSame($users['b']['id'], $lookupByNickname['mgw_id'] ?? null, 'Exact nickname lookup must resolve canonical MGW account');
$assertSame('Bravo', $lookupByNickname['display_name'] ?? null, 'Lookup must expose canonical nickname, not provider display name');
$assertTrue(!array_key_exists('username', $lookupByNickname ?? []), 'Lookup must not expose provider username');

$lookupByPublicId = $service->lookupExact($users['a']['id'], MgwIdGenerator::toPublic($users['b']['id']));
$assertSame($users['b']['id'], $lookupByPublicId['mgw_id'] ?? null, 'Public MGW-ID lookup must resolve exact canonical account');
$assertSame(null, $service->lookupExact($users['a']['id'], 'bravo'), 'Nickname lookup must remain exact/case-sensitive');

$request = $service->requestFriend($users['a']['id'], $users['b']['id']);
$assertSame('outgoing', $request['status'], 'First friend request must become outgoing');
$assertSame(true, $request['changed'], 'First friend request must mutate pair state');
$duplicate = $service->requestFriend($users['a']['id'], $users['b']['id']);
$assertSame(false, $duplicate['changed'], 'Same-direction duplicate request must be idempotent');
$assertReason(
    static fn() => $service->requestFriend($users['b']['id'], $users['a']['id']),
    'incoming_request_exists',
    'Reverse duplicate request must not create a second pending relation'
);
$pairCount = (int)$database->fetchValue(
    'SELECT COUNT(*) FROM mgw_social_relations WHERE user_low_mgw_id = :low AND user_high_mgw_id = :high',
    ['low' => $users['a']['id'], 'high' => $users['b']['id']]
);
$assertSame(1, $pairCount, 'One unordered pair row must own both request directions');

$aSnapshot = $service->snapshot($users['a']['id']);
$bSnapshot = $service->snapshot($users['b']['id']);
$assertSame(1, count($aSnapshot['outgoing']), 'Requester must see one outgoing request');
$assertSame(1, count($bSnapshot['incoming']), 'Target must see one incoming request');

$accepted = $service->acceptFriendRequest($users['b']['id'], $users['a']['id']);
$assertSame('friends', $accepted['status'], 'Incoming request acceptance must create friendship');
$assertSame(1, count($service->snapshot($users['a']['id'])['friends']), 'Friendship must project symmetrically to requester');
$assertSame(1, count($service->snapshot($users['b']['id'])['friends']), 'Friendship must project symmetrically to accepter');

$blocked = $service->block($users['a']['id'], $users['b']['id']);
$assertSame('blocked', $blocked['status'], 'Block must become actor-owned blocked state');
$assertSame(0, count($service->snapshot($users['a']['id'])['friends']), 'Blocking must clear friendship');
$assertSame(1, count($service->snapshot($users['a']['id'])['blocked']), 'Blocker must see target in blocked list');
$assertSame(0, count($service->snapshot($users['b']['id'])['friends']), 'Blocked target must not retain friendship projection');
$assertSame(null, $service->lookupExact($users['b']['id'], 'Alpha'), 'Blocked pair must be hidden from exact social lookup');
$assertReason(
    static fn() => $service->requestFriend($users['b']['id'], $users['a']['id']),
    'request_unavailable',
    'Blocked target must not be able to request blocker'
);
$assertReason(
    static fn() => $service->requestFriend($users['a']['id'], $users['b']['id']),
    'request_unavailable',
    'Blocker must not create a friend request until unblock'
);

$unblocked = $service->unblock($users['a']['id'], $users['b']['id']);
$assertSame(true, $unblocked['changed'], 'Unblock must clear actor block bit');
$afterUnblock = $service->requestFriend($users['b']['id'], $users['a']['id']);
$assertSame('outgoing', $afterUnblock['status'], 'Friend request must work again after unblock');
$service->cancelFriendRequest($users['b']['id'], $users['a']['id']);

$insertMatch = static function (
    DatabaseConnectionInterface $database,
    string $matchId,
    string $actorMgwId,
    string $opponentRef,
    ?string $opponentMgwId,
    string $opponentType,
    string $finishedAt
): void {
    $database->execute(
        'INSERT INTO mgw_matches (
            match_id, game_type, room, status, board_size, bet, state_version,
            created_at_utc, started_at_utc, updated_at_utc, finished_at_utc
         ) VALUES (
            :match_id, :game_type, :room, :status, :board_size, 0, 1,
            :created_at, :started_at, :updated_at, :finished_at
         )',
        [
            'match_id' => $matchId,
            'game_type' => 'tictactoe',
            'room' => 'normal',
            'status' => 'finished',
            'board_size' => 3,
            'created_at' => $finishedAt,
            'started_at' => $finishedAt,
            'updated_at' => $finishedAt,
            'finished_at' => $finishedAt,
        ]
    );
    $database->execute(
        'INSERT INTO mgw_match_players (
            match_id, seat, player_ref, mgw_id, player_type, display_name,
            joined_at_utc, updated_at_utc
         ) VALUES (
            :match_id, 1, :player_ref, :mgw_id, :player_type, :display_name,
            :joined_at, :updated_at
         )',
        [
            'match_id' => $matchId,
            'player_ref' => 'mgw:' . $actorMgwId,
            'mgw_id' => $actorMgwId,
            'player_type' => 'human',
            'display_name' => 'ignored-provider-name',
            'joined_at' => $finishedAt,
            'updated_at' => $finishedAt,
        ]
    );
    $database->execute(
        'INSERT INTO mgw_match_players (
            match_id, seat, player_ref, mgw_id, player_type, display_name,
            joined_at_utc, updated_at_utc
         ) VALUES (
            :match_id, 2, :player_ref, :mgw_id, :player_type, :display_name,
            :joined_at, :updated_at
         )',
        [
            'match_id' => $matchId,
            'player_ref' => $opponentRef,
            'mgw_id' => $opponentMgwId,
            'player_type' => $opponentType,
            'display_name' => 'ignored-opponent-name',
            'joined_at' => $finishedAt,
            'updated_at' => $finishedAt,
        ]
    );
};

$insertMatch($database, 'social_recent_human', $users['a']['id'], 'mgw:' . $users['c']['id'], $users['c']['id'], 'human', '2026-08-19 15:10:00.000000');
$insertMatch($database, 'social_recent_bot', $users['a']['id'], 'bot:test-social', null, 'bot', '2026-08-19 15:20:00.000000');
$recent = $service->snapshot($users['a']['id'])['recent_opponents'];
$assertSame(1, count($recent), 'Recent opponents must include only canonical human opponents');
$assertSame($users['c']['id'], $recent[0]['mgw_id'] ?? null, 'Recent opponent must come from existing match/player owner');
$assertSame('Charlie', $recent[0]['nickname'] ?? null, 'Recent opponent must use canonical MGW nickname');

$service->block($users['a']['id'], $users['c']['id']);
$assertSame(0, count($service->snapshot($users['a']['id'])['recent_opponents']), 'Blocked pair must be suppressed from recent opponents');
$assertReason(
    static fn() => $service->requestFriend($users['a']['id'], $users['a']['id']),
    'self_relation',
    'Self friend request must fail closed'
);

fwrite(STDOUT, 'PASS: ' . $assertions . " assertions\n");
