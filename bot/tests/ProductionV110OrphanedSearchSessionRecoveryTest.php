<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/services/SearchSessionRecoveryService.php';
require_once $root . '/bot/services/SessionService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$sessions = new SessionService(['active_session_timeout_sec' => 180]);

$orphanedUser = [
    'id' => '42',
    'status' => 'searching',
    'current_game_id' => null,
    'active_session_id' => 'old-session',
    'active_session_at' => gmdate('c'),
];

$lockedBeforeRepair = false;
try {
    $sessions->assertCanPlay($orphanedUser, 'new-session');
} catch (RuntimeException $error) {
    $lockedBeforeRepair = true;
}
$assert($lockedBeforeRepair, 'Fresh foreign-session searching state must be locked before orphan repair.');

$repaired = SearchSessionRecoveryService::repairOrphanedSearch(['queue' => []], $orphanedUser);
$assert($repaired === true, 'Searching user without a queue row must be repaired.');
$assert(($orphanedUser['status'] ?? null) === 'idle', 'Orphaned search repair must return the user to idle.');
$assert(array_key_exists('current_game_id', $orphanedUser) && $orphanedUser['current_game_id'] === null,
    'Orphaned search repair must clear current_game_id.');
$sessions->assertCanPlay($orphanedUser, 'new-session');
$assert(true, 'Repaired orphaned search must allow the new session to start matchmaking.');

$validQueuedUser = [
    'id' => '42',
    'status' => 'searching',
    'current_game_id' => null,
    'active_session_id' => 'old-session',
    'active_session_at' => gmdate('c'),
];
$validQueue = [[
    'id' => 'queue-42',
    'user_id' => '42',
    'created_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
]];
$assert(SearchSessionRecoveryService::repairOrphanedSearch(['queue' => $validQueue], $validQueuedUser) === false,
    'A real queue row must never be treated as an orphan.');
$assert(($validQueuedUser['status'] ?? null) === 'searching', 'Valid queued search must remain searching.');

$stillLocked = false;
try {
    $sessions->assertCanPlay($validQueuedUser, 'new-session');
} catch (RuntimeException $error) {
    $stillLocked = true;
}
$assert($stillLocked, 'Valid search owned by another live session must remain locked.');

$playingUser = [
    'id' => '42',
    'status' => 'playing',
    'current_game_id' => 'game-1',
];
$assert(SearchSessionRecoveryService::repairOrphanedSearch(['queue' => []], $playingUser) === false,
    'Playing state must never be modified by orphaned-search recovery.');
$assert(($playingUser['status'] ?? null) === 'playing' && ($playingUser['current_game_id'] ?? null) === 'game-1',
    'Playing state must remain byte-for-byte meaningful after recovery check.');

$apiSource = file_get_contents($root . '/bot/api.php');
if (!is_string($apiSource)) {
    throw new RuntimeException('Cannot read bot/api.php.');
}
$assert(str_contains($apiSource, "require_once __DIR__ . '/services/SearchSessionRecoveryService.php';"),
    'api.php must load SearchSessionRecoveryService.');
$startCase = strpos($apiSource, "case 'start_search':");
$repairCall = $startCase === false ? false : strpos($apiSource, 'SearchSessionRecoveryService::repairOrphanedSearch($data, $user);', $startCase);
$sessionAssert = $startCase === false ? false : strpos($apiSource, '$sessions->assertCanPlay($user, $sessionId);', $startCase);
$assert($startCase !== false && $repairCall !== false && $sessionAssert !== false,
    'start_search must contain both orphan repair and session ownership assertion.');
$assert($repairCall < $sessionAssert,
    'start_search must repair only impossible orphaned-search state before enforcing session ownership.');

fwrite(STDOUT, "ProductionV110OrphanedSearchSessionRecoveryTest: {$assertions} assertions passed\n");
