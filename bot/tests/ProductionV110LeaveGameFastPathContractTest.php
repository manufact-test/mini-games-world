<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$api = file_get_contents($root . '/bot/api.php');
$lifecycle = file_get_contents($root . '/app/assets/js/production-v110-match-lifecycle.js');
if (!is_string($api) || !is_string($lifecycle)) {
    throw new RuntimeException('Cannot read v110 leave-game sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($api, "\$forceCleanup = in_array(\$action, ['start_search', 'leave_search', 'game_action', 'make_move'], true);")
        && !str_contains($api, "['start_search', 'leave_search', 'game_action', 'make_move', 'leave_game']"),
    'Manual surrender must use the normal bounded cleanup cadence instead of forcing a global game scan.'
);

$surrender = strpos($api, '$game = $games->surrenderGame($data, $user, $gameId);');
$release = strpos($api, '$sessions->releaseIfCurrent($user, $sessionId);', $surrender ?: 0);
$returnGame = strpos($api, "'game' => \$games->publicGame(\$game, \$userId)", $surrender ?: 0);
$assert(
    $surrender !== false
        && $release !== false
        && $returnGame !== false
        && $surrender < $release
        && $release < $returnGame,
    'leave_game must finish the known game and release the same session in one response.'
);

$paint = strpos($lifecycle, 'renderPendingResult(optimistic, viewer);');
$request = strpos($lifecycle, 'const result = await api.leaveGame(String(snapshot.id));');
$assert(
    $paint !== false && $request !== false && $paint < $request,
    'The visible result must render before the bounded server request starts.'
);

fwrite(STDOUT, "ProductionV110LeaveGameFastPathContractTest: {$assertions} assertions passed\n");
