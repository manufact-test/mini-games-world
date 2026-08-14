<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$renderer = file_get_contents($root . '/app/assets/js/games/battleship/renderer.js');
$v110 = file_get_contents($root . '/app/v110.php');
if (!is_string($renderer) || !is_string($v110)) {
    throw new RuntimeException('Cannot read Battleship miss handoff sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($renderer, "const delay = result === 'miss' ? 900 : 1250;")
        && !str_contains($renderer, "const delay = result === 'miss' ? 1450 : 1250;"),
    'Battleship miss handoff must keep a readable but faster 900ms transition.'
);

$assert(
    str_contains($v110, 'games/battleship/renderer.js?v=58&miss=900ms')
        && str_contains($v110, 'X-MGW-Battleship-Miss-Handoff: 900ms')
        && str_contains($v110, 'v110-mvp14-battleship-miss-handoff-v1150'),
    'Canonical Telegram v110 must publish the fresh 900ms Battleship miss handoff identity.'
);

fwrite(STDOUT, "ProductionV110BattleshipMissHandoffContractTest: {$assertions} assertions passed\n");
