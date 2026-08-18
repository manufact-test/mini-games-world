<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/MatchmakingQueue.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$queue = new MatchmakingQueue();

$assert($queue->normalizeSkillBand(null) === 'unrated', 'Missing skill band must use the neutral unrated bucket.');
$assert($queue->normalizeSkillBand(' Gold-1 ') === 'gold-1', 'Skill band normalization must be deterministic.');

$base = [
    'user_id' => 'u2',
    'game_type' => 'reversi',
    'board_size' => 9,
    'requested_board_size' => 8,
    'skill_band' => 'unrated',
    'created_at' => gmdate('c', time() - 2),
];
$assert($queue->matchesKey($base, 'reversi', 8, 'unrated'), 'Exact game + requested board + skill band must match.');
$assert(!$queue->matchesKey($base, 'checkers', 8, 'unrated'), 'Different game types must never share a matchmaking key.');
$assert(!$queue->matchesKey($base, 'reversi', 10, 'unrated'), 'Different board sizes must never share a matchmaking key.');
$assert(!$queue->matchesKey($base, 'reversi', 8, 'rated'), 'Different skill bands must never share a matchmaking key in MVP-17.1.');

$db = [
    'users' => [
        'u1' => ['id' => 'u1', 'status' => 'searching'],
        'u2' => ['id' => 'u2', 'status' => 'searching'],
        'u3' => ['id' => 'u3', 'status' => 'playing', 'current_game_id' => 'g-active'],
    ],
    'queue' => [
        $base,
        [
            'user_id' => 'u3',
            'game_type' => 'reversi',
            'requested_board_size' => 8,
            'skill_band' => 'unrated',
            'created_at' => gmdate('c', time() - 1),
        ],
    ],
    'games' => [
        'g-active' => [
            'id' => 'g-active',
            'status' => 'active',
            'player_ids' => ['u3', 'u9'],
        ],
    ],
    'system' => [],
];

$candidate = $queue->firstCandidate($db, 'u1', 'reversi', 8, 'unrated');
$assert(is_array($candidate) && ($candidate['user_id'] ?? '') === 'u2', 'Eligible exact-key human candidate must be selected.');

$removed = $queue->purgeActiveGameQueueEntries($db);
$assert($removed === 1, 'Queue records for users already in an active game must be purged.');
$assert(count($db['queue']) === 1 && ($db['queue'][0]['user_id'] ?? '') === 'u2', 'Active-game purge must preserve eligible queue entries.');

$telemetry = $queue->telemetry($db);
$assert($telemetry['matchmaking_queue_depth'] === 1, 'Queue depth telemetry must track the current queue.');
$assert($telemetry['matchmaking_duplicate_match_prevented_total'] === 1, 'Active-game purge must increment duplicate prevention telemetry.');

$queue->observeWaitFromQueueItem($db, $base);
$telemetry = $queue->telemetry($db);
$assert($telemetry['matchmaking_wait_ms'] >= 1000, 'Matched queue wait telemetry must be recorded in milliseconds.');

$source = file_get_contents(dirname(__DIR__) . '/services/MatchmakingQueue.php');
$assert(is_string($source) && !preg_match('/telegram|initdata|chat_id/i', $source), 'MatchmakingQueue must remain transport-neutral.');

fwrite(STDOUT, "Mvp17_1MatchmakingQueueTest: {$assertions} assertions passed\n");
