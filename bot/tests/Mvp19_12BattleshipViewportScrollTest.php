<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$fitCss = file_get_contents($root . '/app/assets/css/games/battleship/live-exit-fit-v1.css');
$gameCss = file_get_contents($root . '/app/assets/css/games/battleship/game.css');
$renderer = file_get_contents($root . '/app/assets/js/games/battleship/renderer.js');
$v110 = file_get_contents($root . '/app/v110.php');
$launch = file_get_contents($root . '/bot/helpers/WebAppLaunchUrl.php');
$readonlySync = file_get_contents($root . '/app/assets/js/production-v110-readonly-game-sync.js');
$manifest = require $root . '/app/runtime/client/version-manifest.php';

if (!is_string($fitCss) || !is_string($gameCss) || !is_string($renderer) || !is_string($v110) || !is_string($launch) || !is_string($readonlySync)) {
    throw new RuntimeException('Battleship viewport corrective sources are unavailable.');
}

foreach ([
    '.game-board-screen[data-game-type="battleship"]{',
    'height:100dvh!important;',
    'max-height:100dvh!important;',
    'min-height:0!important;',
    'overflow:hidden!important;',
    '.game-board-screen[data-game-type="battleship"] .content{',
    'height:100%!important;',
    'max-height:100%!important;',
    'flex:1 1 0%!important;',
    'overflow-y:auto!important;',
    'overflow-x:hidden!important;',
    'touch-action:pan-y;',
    '56px + env(safe-area-inset-bottom, 0px) + var(--tg-content-safe-area-inset-bottom, 0px)',
    '.game-board-screen[data-game-type="battleship"] #leaveGame{',
    'position:static!important;',
    'visibility:visible!important;',
] as $token) {
    if (!str_contains($fitCss, $token)) {
        throw new RuntimeException('Battleship viewport token is missing: ' . $token);
    }
}

foreach ([
    'position:fixed',
    'position:sticky',
] as $rejected) {
    if (str_contains($fitCss, $rejected)) {
        throw new RuntimeException('Battleship viewport owner must not pin shared controls: ' . $rejected);
    }
}

foreach ([
    "onAction?.({ type:'randomize_fleet'",
    "onAction?.({ type:'clear_fleet'",
    "onAction?.({ type:'ready'",
    "onAction?.({ type:'fire'",
] as $gameplayToken) {
    if (!str_contains($renderer, $gameplayToken)) {
        throw new RuntimeException('Accepted Battleship gameplay action changed or disappeared: ' . $gameplayToken);
    }
}

foreach ([
    '.battleship-coordinate-board{',
    'aspect-ratio:1;',
    '.battleship-setup-actions{',
] as $geometryToken) {
    if (!str_contains($gameCss, $geometryToken)) {
        throw new RuntimeException('Accepted Battleship game geometry must remain owned by game.css: ' . $geometryToken);
    }
}

foreach ([
    './assets/css/games/battleship/live-exit-fit-v1.css?v=1&mvp19_12=bounded-screen-scroll-v1',
    "'battleship_viewport_scroll' => $battleshipExitFitTarget",
] as $runtimeToken) {
    if (!str_contains($v110, $runtimeToken)) {
        throw new RuntimeException('v110 does not publish the Battleship viewport corrective: ' . $runtimeToken);
    }
}
if (!str_contains($v110, 'is_file($battleshipExitFitPath)')) {
    throw new RuntimeException('v110 must fail closed when the Battleship viewport CSS is missing.');
}
if (!str_contains($launch, 'battleship_viewport=scroll-v1')) {
    throw new RuntimeException('Telegram launch must publish Battleship viewport scroll identity.');
}
if (!str_contains($readonlySync, "if (document.getElementById('confirmLeaveGame')) return false;")) {
    throw new RuntimeException('Readonly game watch must pause while the leave confirmation sheet is open.');
}
$readonlyTarget = (string)($manifest['imports']['./assets/js/production-v110-readonly-game-sync.js?v=1107&b=bc9d7b435f1a'] ?? '');
if (!str_contains($readonlyTarget, 'v=1117') || !str_contains($readonlyTarget, 'leave_confirm=preserve-v1')) {
    throw new RuntimeException('Active runtime graph must publish the leave-confirm preservation revision.');
}
if (!str_contains($launch, 'battleship_leave_sheet=readonly-guard-v1')) {
    throw new RuntimeException('Telegram launch must publish leave-sheet preservation identity.');
}

echo "battleship-bounded-screen-scroll-v1=ok\n";
