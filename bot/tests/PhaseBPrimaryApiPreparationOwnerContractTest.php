<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$apiPath = $root . '/bot/api.php';
$servicePath = $root . '/bot/services/MatchPreparationRuntimeService.php';
$api = file_get_contents($apiPath);
$service = file_get_contents($servicePath);
if (!is_string($api) || !is_string($service)) {
    throw new RuntimeException('Phase B primary owner sources are unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($api, "require_once __DIR__ . '/services/MatchPreparationRuntimeService.php';") === 1,
    'api.php must load the preparation runtime owner exactly once.');
$assert(substr_count($api, 'new MatchPreparationRuntimeService($config)') === 1,
    'api.php must construct exactly one preparation runtime owner.');
$assert(substr_count($api, "\$deviceId = clean_string(\$payload['deviceId'] ?? '', 120);") === 1,
    'api.php must accept the existing deviceId readiness identity material.');

$gameStateStart = strpos($api, "case 'game_state':");
$gameStateEnd = $gameStateStart === false ? false : strpos($api, "case 'game_action':", $gameStateStart);
$assert($gameStateStart !== false && $gameStateEnd !== false,
    'Canonical game_state case must remain present.');
$gameState = ($gameStateStart !== false && $gameStateEnd !== false)
    ? substr($api, $gameStateStart, $gameStateEnd - $gameStateStart)
    : '';

$requestedPos = strpos($gameState, "\$requestedGameId = clean_string(\$payload['gameId'] ?? '', 80);");
$assertCanPlayPos = strpos($gameState, '$sessions->assertCanPlay($user, $sessionId);');
$cleanupPos = strpos($gameState, 'mgw_cleanup_games_if_due($data, $games, false);');
$refreshPos = strpos($gameState, '$games->refreshSearch($data, $user);');
$botPos = strpos($gameState, '$games->maybeCreateBotGameForSearchingUser($data, $user);');
$assert($requestedPos !== false && $assertCanPlayPos !== false && $cleanupPos !== false
    && $refreshPos !== false && $botPos !== false
    && $requestedPos < $assertCanPlayPos
    && $assertCanPlayPos < $cleanupPos
    && $cleanupPos < $refreshPos
    && $refreshPos < $botPos,
    'game_state must resolve request identity and session ownership before cleanup/search/bot mutations.');

$runtimeCall = '$matchPreparationRuntime->synchronizeCurrentGame(';
$assert(substr_count($gameState, $runtimeCall) === 1,
    'game_state must delegate Phase B readiness/advance/settlement to one runtime owner exactly once.');
$assert(!str_contains($gameState, '->markReady(')
    && !str_contains($gameState, '->advance(')
    && !str_contains($gameState, '->cancelPreparation(')
    && !str_contains($gameState, '->synchronizeObservedTurn('),
    'api.php must orchestrate, not duplicate the Phase B state machine.');
$assert(str_contains($gameState, '$requestedGameId,')
    && str_contains($gameState, '$sessionId,')
    && str_contains($gameState, '$deviceId'),
    'The single runtime call must receive explicit requested game, session and device identity.');

$assert(substr_count($service, '$this->clock->markReady(') === 1,
    'Preparation runtime service must own one readiness write call.');
$assert(substr_count($service, '$this->clock->advance(') === 1,
    'Preparation runtime service must own one lifecycle advance call.');
$assert(substr_count($service, '$this->settlement->cancelPreparation(') === 1,
    'Preparation runtime service must own one preparation-timeout settlement call.');
$assert(substr_count($service, '$this->clock->synchronizeObservedTurn(') === 1,
    'Preparation runtime service must own one observed-turn synchronization call.');
$assert(str_contains($service, "\$readyIntent = \$requestedGameId !== '' && \$requestedGameId === \$gameId;"),
    'Readiness intent must require an explicit requested current game id.');
$assert(str_contains($service, "(string)(\$user['current_game_id'] ?? '') === \$gameId"),
    'Lifecycle writes must require the participant still to own this exact current game.');
$assert(str_contains($service, "if (!array_key_exists('launch_phase', \$game))"),
    'Legacy games without launch_phase must remain outside the Phase B writer.');
$assert(!str_contains($api, 'sleep(') && !str_contains($api, 'usleep(')
    && !str_contains($service, 'sleep(') && !str_contains($service, 'usleep('),
    'Primary Phase B ownership must not introduce timing patches.');

fwrite(STDOUT, "PhaseBPrimaryApiPreparationOwnerContractTest: {$assertions} assertions passed\n");
