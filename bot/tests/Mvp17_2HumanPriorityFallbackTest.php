<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/MatchmakingQueue.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$queue = new MatchmakingQueue();
$now = time();

$atSeven = [
    'user_id' => 'u2',
    'game_type' => 'chess',
    'requested_board_size' => 8,
    'skill_band' => 'band:10',
    'created_at' => gmdate('c', $now - 7),
    'updated_at' => gmdate('c', $now),
];
$atEight = $atSeven;
$atEight['created_at'] = gmdate('c', $now - 8);

$assert(!$queue->botFallbackAllowed($atSeven, $now), 'Bot fallback must remain forbidden before eight real seconds.');
$assert($queue->botFallbackAllowed($atEight, $now), 'Bot fallback may become eligible at eight real seconds.');
$assert($queue->queueWaitSeconds($atSeven, $now) === 7, 'Queue wait must use immutable created_at rather than refreshed updated_at.');

$exact = $atSeven;
$exact['created_at'] = gmdate('c', $now);
$assert($queue->matchesKey($exact, 'chess', 8, 'band:10'), 'Exact ordinal skill band must match immediately.');
$assert(!$queue->matchesKey($exact, 'chess', 8, 'band:11'), 'Adjacent ordinal band must not match at zero wait.');

$stepOne = $atSeven;
$stepOne['created_at'] = gmdate('c', $now - 2);
$assert($queue->matchesKey($stepOne, 'chess', 8, 'band:11'), 'Two seconds of wait must widen by one ordinal band.');
$assert(!$queue->matchesKey($stepOne, 'chess', 8, 'band:12'), 'First widening step must remain bounded to one band.');

$stepTwo = $atSeven;
$stepTwo['created_at'] = gmdate('c', $now - 4);
$assert($queue->matchesKey($stepTwo, 'chess', 8, 'band:12'), 'Four seconds of wait must widen by two ordinal bands.');

$maxStep = $atSeven;
$maxStep['created_at'] = gmdate('c', $now - 7);
$assert($queue->matchesKey($maxStep, 'chess', 8, 'band:13'), 'Human-priority window may widen to the server maximum before fallback.');
$assert(!$queue->matchesKey($maxStep, 'chess', 8, 'band:14'), 'Server extreme limit must cap widening at three ordinal bands.');

$named = $atSeven;
$named['skill_band'] = 'gold';
$assert(!$queue->matchesKey($named, 'chess', 8, 'silver'), 'Unknown named bands must remain exact-only until the hidden-skill owner defines ordering.');
$assert(!$queue->matchesKey($atSeven, 'chess', 8, 'unrated'), 'Unrated must not be treated as an invented ordinal neighbor.');

$db = [
    'users' => [
        'u1' => ['id' => 'u1', 'status' => 'searching'],
        'u2' => ['id' => 'u2', 'status' => 'searching'],
    ],
    'queue' => [$stepOne],
    'games' => [],
    'system' => [],
];
$candidate = $queue->firstCandidate($db, 'u1', 'chess', 8, 'band:11');
$assert(is_array($candidate) && ($candidate['user_id'] ?? '') === 'u2', 'Progressively widened human candidate must remain eligible through firstCandidate.');

$telemetry = $queue->telemetry($db);
$assert(array_key_exists('matchmaking_human_match_total', $telemetry), 'Human match source telemetry key must exist.');
$assert(array_key_exists('matchmaking_bot_match_total', $telemetry), 'Bot match source telemetry key must exist.');

fwrite(STDOUT, "Mvp17_2HumanPriorityFallbackTest: {$assertions} assertions passed\n");
