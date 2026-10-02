<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$fitCss = file_get_contents($root . '/app/assets/css/games/checkers/telegram-height-fit-v1.css');
$checkersCss = file_get_contents($root . '/app/assets/css/games/checkers/game.css');
$renderer = file_get_contents($root . '/app/assets/js/games/checkers/renderer.js');
$v110 = file_get_contents($root . '/app/v110.php');
$manifest = file_get_contents($root . '/app/runtime/client/version-manifest.php');

if (!is_string($fitCss) || !is_string($checkersCss) || !is_string($renderer) || !is_string($v110) || !is_string($manifest)) {
    throw new RuntimeException('Checkers Telegram viewport corrective sources are unavailable.');
}

foreach ([
    '.game-board-screen[data-game-type="checkers"]{',
    'box-sizing:border-box!important;',
    'height:100dvh!important;',
    'max-height:100dvh!important;',
    'min-height:0!important;',
    'overflow:hidden!important;',
    '.game-board-screen[data-game-type="checkers"] .content{',
    'height:100%!important;',
    'max-height:100%!important;',
    'flex:1 1 0%!important;',
    'overflow-y:auto!important;',
    'overflow-x:hidden!important;',
    'touch-action:pan-y;',
    '56px + env(safe-area-inset-bottom, 0px) + var(--tg-content-safe-area-inset-bottom, 0px)',
    '@media (max-height:680px)',
    '.game-board-screen[data-game-type="checkers"] .board.checkers-surface{',
    'width:min(100%,clamp(300px,calc(100dvh - 245px),360px));',
    'margin-left:auto!important;',
    'margin-right:auto!important;',
    '.game-board-screen[data-game-type="checkers"] .board-wrap{',
    '.game-board-screen[data-game-type="checkers"] .checkers-panel{',
] as $token) {
    if (!str_contains($fitCss, $token)) {
        throw new RuntimeException('Checkers Telegram C15 token is missing: ' . $token);
    }
}

$mediaOffset = strpos($fitCss, '@media (max-height:680px)');
$contentOffset = strpos($fitCss, '.game-board-screen[data-game-type="checkers"] .content{');
$screenOffset = strpos($fitCss, '.game-board-screen[data-game-type="checkers"]{');
if ($mediaOffset === false || $contentOffset === false || $screenOffset === false
    || $screenOffset > $mediaOffset || $contentOffset > $mediaOffset) {
    throw new RuntimeException('C15 viewport/scroll ownership must apply above the <=680px geometry media query.');
}

if (preg_match('/#leaveGame\s*\{/u', $fitCss) === 1) {
    throw new RuntimeException('Checkers Telegram corrective must not own the shared leave control.');
}
foreach ([
    'position:fixed',
    'position:sticky',
    'transform:',
] as $rejectedToken) {
    if (str_contains($fitCss, $rejectedToken)) {
        throw new RuntimeException('Checkers Telegram corrective must not pin or transform shared layout: ' . $rejectedToken);
    }
}

$gitBlobSha = static fn(string $content): string => sha1('blob ' . strlen($content) . "\0" . $content);
if ($gitBlobSha($checkersCss) !== '12d2f211c48c1f49793744315c004fd33afc5bcf') {
    throw new RuntimeException('Accepted pre-b94d Checkers skin must remain byte-identical.');
}
if (!str_contains($renderer, "from '@mgw/i18n'")
    || preg_match('/[\x{0400}-\x{04FF}]/u', $renderer) === 1
    || !str_contains($renderer, "onAction?.({ type:'move', from, to });")
    || !str_contains($renderer, 'Array.from({ length:64 }')) {
    throw new RuntimeException('Checkers renderer must preserve move/board mechanics while player copy remains localization-owned.');
}

if (!str_contains($manifest, "'checkers_height_fit' => './assets/css/games/checkers/telegram-height-fit-v1.css?v=5&checkers=bounded-screen-scroll-c15'")) {
    throw new RuntimeException('Runtime manifest must preserve the accepted Checkers Telegram C15 asset.');
}

foreach ([
    "'checkers_height_fit'",
    "\$checkersTelegramHeightFitTarget = \$assets['checkers_height_fit'];",
    "'checkers_height_fit' => \$checkersTelegramHeightFitTarget",
    "'checkers_telegram_height_fit' => \$checkersTelegramHeightFitTarget",
] as $runtimeToken) {
    if (!str_contains($v110, $runtimeToken)) {
        throw new RuntimeException('v110 does not wire the current Checkers Telegram C15 owner: ' . $runtimeToken);
    }
}

if (!str_contains($v110, "str_starts_with(\$stylePath, './assets/css/')")
    || !str_contains($v110, "is_file(__DIR__ . '/' . substr(\$stylePath, 2))")) {
    throw new RuntimeException('v110 must fail closed when an accepted stylesheet asset is missing.');
}

echo "checkers-telegram-bounded-screen-scroll-c15=ok\n";
