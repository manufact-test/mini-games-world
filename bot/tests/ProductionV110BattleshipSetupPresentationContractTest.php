<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read Battleship setup presentation source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$acceptance = $read('app/assets/js/production-v110-acceptance-runtime.js');
$renderer = $read('app/assets/js/games/battleship/renderer.js');
$gameCss = $read('app/assets/css/screens/game.css');
$mainCss = $read('app/assets/css/main.css');
$v110 = $read('app/v110.php');

$assert(
    str_contains($acceptance, 'function headerClockOwnsGame(game)')
        && str_contains($acceptance, "return !(type === 'battleship' && String(game?.phase || '') === 'setup');")
        && str_contains($acceptance, "String(activeGame?.status || '') !== 'active' || !headerClockOwnsGame(activeGame)")
        && str_contains($acceptance, 'if (!headerClockOwnsGame(game)) return;'),
    'The shared projected header clock must explicitly release ownership during Battleship setup.'
);

$assert(
    str_contains($renderer, 'class="battleship-setup-time"')
        && str_contains($renderer, 'game?.setup_time_left ?? game?.time_left ?? 120'),
    'Battleship setup must keep its dedicated setup_time_left renderer as the sole visible setup countdown.'
);

$assert(
    str_contains($gameCss, '[data-game-type="battleship"][data-game-phase="setup"] .timer{display:none}')
        && !str_contains($gameCss, '[data-game-type="battleship"] .timer{display:none}'),
    'Only the redundant header timer may be hidden during Battleship setup; the battle header timer must remain available.'
);

$assert(
    !str_contains($gameCss, '[data-game-type="battleship"] .game-player .mark{display:none}')
        && str_contains($gameCss, '[data-game-type="battleship"] .game-player .mark{font-size:10px;margin-top:2px')
        && str_contains($gameCss, '[data-game-type="battleship"] .game-player{padding:7px 8px;border-radius:14px;min-height:40px}')
        && str_contains($gameCss, '[data-game-type="battleship"] .players-row{gap:4px}'),
    'Battleship player cards must keep their secondary fleet labels visible and slightly roomier in compact desktop windows.'
);

$assert(
    str_contains($mainCss, "./screens/game.css?v=62&timer=battleship-setup-single-owner")
        && str_contains($v110, 'production-v110-acceptance-runtime.js?v=130&clock=battleship-setup-single-owner')
        && str_contains($v110, 'main.css?v=148&sk=3&icons=c1efd5af&render=24&palette=notification-semantic')
        && str_contains($v110, 'X-MGW-Battleship-Setup-Clock: dedicated-setup-timer-single-owner')
        && str_contains($v110, 'X-MGW-Battleship-Player-Cards: desktop-secondary-labels-visible'),
    'Canonical Telegram v110 must publish the new Battleship setup clock owner and player-card presentation with fresh identities.'
);

fwrite(STDOUT, "ProductionV110BattleshipSetupPresentationContractTest: {$assertions} assertions passed\n");
