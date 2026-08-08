<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$apiPath = $root . '/api.php';
$source = file_get_contents($apiPath);
if (!is_string($source)) {
    throw new RuntimeException('Primary API source is unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($source, '$gameActions = new GameActionService($gameCatalog, $games);'),
    'Primary API must own one shared GameActionService instance.'
);
$assert(
    substr_count($source, '$gameActions->apply(') === 2,
    'game_action and legacy make_move must be the only primary API GameActionService entry points.'
);
$assert(
    !str_contains($source, '$games->makeMove('),
    'Primary API must not retain a direct legacy makeMove bypass.'
);

$legacyStart = strpos($source, "case 'make_move':");
$legacyEnd = strpos($source, "case 'leave_game':", $legacyStart === false ? 0 : $legacyStart);
$assert(
    $legacyStart !== false && $legacyEnd !== false && $legacyStart < $legacyEnd,
    'Legacy make_move switch section must remain identifiable.'
);

$legacySection = substr($source, (int)$legacyStart, (int)$legacyEnd - (int)$legacyStart);
$assert(
    str_contains($legacySection, '$gameActions->apply($data, $user, $gameId, [')
        && str_contains($legacySection, "'type' => 'cell'")
        && str_contains($legacySection, "'cell' => $cell"),
    'Legacy make_move must translate to the canonical cell action without changing its payload meaning.'
);
$assert(
    str_contains($legacySection, '$sessions->assertCanPlay($user, $sessionId);')
        && str_contains($legacySection, '$sessions->touch($user, $sessionId);')
        && str_contains($legacySection, '$sessions->releaseIfCurrent($user, $sessionId);'),
    'Legacy make_move must preserve existing session ownership/release behavior.'
);

$gameActionStart = strpos($source, "case 'game_action':");
$assert(
    $gameActionStart !== false && $gameActionStart < $legacyStart,
    'Canonical game_action must remain the primary action path before its compatibility alias.'
);

fwrite(STDOUT, "PhaseBLegacyMakeMoveOwnerContractTest: {$assertions} assertions passed\n");
