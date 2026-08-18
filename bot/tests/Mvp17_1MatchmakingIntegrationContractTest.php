<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$runtime = file_get_contents($root . '/services/GameRuntimeService.php');
$queue = file_get_contents($root . '/services/MatchmakingQueue.php');
$api = file_get_contents($root . '/api.php');

if (!is_string($runtime) || !is_string($queue) || !is_string($api)) {
    throw new RuntimeException('MVP-17.1 matchmaking integration sources are unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($runtime, "require_once __DIR__ . '/MatchmakingQueue.php';")
        && str_contains($runtime, 'private MatchmakingQueue $matchmaking;'),
    'GameRuntimeService must own the platform-neutral MatchmakingQueue abstraction.'
);
$assert(
    str_contains($runtime, '?string $skillBand = null')
        && str_contains($runtime, "['skill_band']"),
    'Runtime queue identity must carry a skill band without forcing callers to provide one.'
);
$assert(
    str_contains($runtime, 'matchesKey($item, $gameType, $boardSize, $skillBand)'),
    'Legacy queue isolation must be constrained by game + board size + skill band.'
);
$assert(
    str_contains($runtime, 'activeGameForUser($db, $userId)')
        && str_contains($runtime, 'preventDuplicateMatch($db, $userId)'),
    'Matchmaking must guard a user that already owns an active game.'
);
$assert(
    str_contains($runtime, 'purgeActiveGameQueueEntries($db)'),
    'Stale queue rows for active-game owners must be purged before matching.'
);
$assert(
    str_contains($queue, "'matchmaking_queue_depth'")
        && str_contains($queue, "'matchmaking_wait_ms'")
        && str_contains($queue, "'matchmaking_duplicate_match_prevented_total'"),
    'All MVP-17.1 matchmaking telemetry metrics must exist.'
);
$assert(
    str_contains($api, '$result = $db->transaction(function (array &$data)')
        && str_contains($api, "case 'start_search':")
        && str_contains($api, '$games->startSearch($data, $user, $room, $bet, $boardSize, $gameType);'),
    'start_search must remain inside the authoritative storage transaction for concurrent double-match safety.'
);
$assert(
    !preg_match('/telegram|initdata|chat_id/i', $queue),
    'MatchmakingQueue must not depend on Telegram transport identity.'
);

fwrite(STDOUT, "Mvp17_1MatchmakingIntegrationContractTest: {$assertions} assertions passed\n");
