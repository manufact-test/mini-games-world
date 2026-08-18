<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/api.php');
$configLoader = file_get_contents($root . '/core/RuntimeConfigLoader.php');
$gameService = file_get_contents($root . '/services/GameService.php');
$baseRuntime = file_get_contents($root . '/services/GameRuntimeService.php');
$specialRuntime = file_get_contents($root . '/services/ChessRuntimeService.php');
$queue = file_get_contents($root . '/services/MatchmakingQueue.php');

if (!is_string($api)
    || !is_string($configLoader)
    || !is_string($gameService)
    || !is_string($baseRuntime)
    || !is_string($specialRuntime)
    || !is_string($queue)) {
    throw new RuntimeException('MVP-17.2 runtime sources are unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($configLoader, "\$config['match_bot_after_sec'] = 8;"),
    'Eight-second human-priority gate must be normalized by the server runtime config owner.'
);
$assert(
    !str_contains($api, 'mgw_prepare_match_bot_fallback')
        && !preg_match("/created_at'\]\s*=\s*gmdate\('c',\s*time\(\)\s*-/", $api),
    'API must never fake queue age to accelerate fallback.'
);
$assert(
    str_contains($api, 'mgw_observe_matchmaking_source')
        && str_contains($api, 'matchmaking_bot_match_total')
        && str_contains($api, 'matchmaking_human_match_total'),
    'Human-vs-bot source must be recorded in the authoritative matchmaking transaction.'
);

$gatePos = strpos($gameService, 'if (time() - $created < $this->botAfterSec())');
$humanPos = strpos($gameService, '$opponentIndex = $this->findHumanOpponentIndex');
$botPos = strpos($gameService, '$game = $this->createBotGame');
$assert(
    $gatePos !== false && $humanPos !== false && $botPos !== false && $gatePos < $humanPos && $humanPos < $botPos,
    'Legacy bot owner must enforce the server gate, retry a human, and only then create a bot.'
);
$assert(
    substr_count($gameService, 'private function createBotGame(') === 1
        && !str_contains($queue, 'function createBotGame(')
        && !str_contains($baseRuntime, 'function createBotGame(')
        && !str_contains($specialRuntime, 'function createBotGame('),
    'GameService must remain the single bot-game creation owner.'
);
$assert(
    str_contains($baseRuntime, '$this->matchmaking->matchesKey(')
        && str_contains($specialRuntime, '$this->matchmaking->matchesKey('),
    'Base and Chess/Go/Domino isolation must share MatchmakingQueue compatibility policy.'
);
$assert(
    str_contains($specialRuntime, "\$item['skill_band'] = \$this->matchmaking->normalizeSkillBand")
        && !str_contains($specialRuntime, 'bot_fallback_5s'),
    'Special-game queue rows must carry the same skill band and no legacy accelerated fallback state.'
);
$assert(
    str_contains($queue, 'public const HUMAN_PRIORITY_SEC = 8;')
        && str_contains($queue, 'allowedSkillDistanceForWait')
        && str_contains($queue, 'MAX_SKILL_BAND_DISTANCE'),
    'Progressive skill widening must remain bounded by the platform-neutral queue policy.'
);
$assert(
    str_contains($gameService, 'public function leaveSearch(')
        && str_contains($gameService, "fn(\$item) => (string)(\$item['user_id'] ?? '') !== \$userId"),
    'Cancellation must continue removing the searching user from the authoritative queue.'
);

fwrite(STDOUT, "Mvp17_2MatchmakingIntegrationContractTest: {$assertions} assertions passed\n");
