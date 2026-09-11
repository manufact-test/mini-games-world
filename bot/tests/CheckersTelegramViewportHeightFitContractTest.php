<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$fitCss = file_get_contents($root . '/app/assets/css/games/checkers/telegram-height-fit-v1.css');
$legacyCss = file_get_contents($root . '/app/assets/js/games/checkers/renderer.js');
$renderer = file_get_contents($root . '/app/assets/js/games/checkers/renderer.js');
$v110 = file_get_contents($root . '/app/v110.php');

if (!is_string($fitCss) || !is_string($legacyCss) || !is_string($renderer) || !is_string($v110)) {
    throw new RuntimeException('Checkers Telegram viewport corrective sources are unavailable.');
}

foreach ([
    '.game-board-screen[data-game-type="checkers"]{',
    'min-height:0!important;',
    'overflow:hidden!important;',
    '.game-board-screen[data-game-type="checkers"] .content{',
    'box-sizing:border-box!important;',
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
        throw new RuntimeException('Checkers Telegram C14 token is missing: ' . $token);
    }
}

$mediaOffset = strpos($fitCss, '@media (max-height:680px)');
$contentOffset = strpos($fitCss, '.game-board-screen[data-game-type="checkers"] .content{');
if ($mediaOffset === false || $contentOffset === false || $contentOffset > $mediaOffset) {
    throw new RuntimeException('C14 scroll ownership must apply above the <=680px geometry media query.');
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

$checkersCss = file_get_contents($root . '/app/assets/css/games/checkers/game.css');
if (!is_string($checkersCss)) {
    throw new RuntimeException('Accepted Checkers skin is unavailable.');
}
$gitBlobSha = static fn(string $content): string => sha1('blob ' . strlen($content) . "\0" . $content);
if ($gitBlobSha($checkersCss) !== '12d2f211c48c1f49793744315c004fd33afc5bcf') {
    throw new RuntimeException('Accepted pre-b94d Checkers skin must remain byte-identical.');
}
if ($gitBlobSha($renderer) !== 'e362239b1388a1f752d2d0e67ae69a7cc9207926') {
    throw new RuntimeException('Checkers renderer/game logic must remain untouched.');
}

foreach ([
    './assets/css/games/checkers/telegram-height-fit-v1.css?v=4&checkers=telegram-scroll-c14',
    "'checkers_telegram_height_fit' => \$checkersTelegramHeightFitTarget",
    'STG A1 · v110 · C14',
    'stg-a1-v110-c14',
] as $runtimeToken) {
    if (!str_contains($v110, $runtimeToken)) {
        throw new RuntimeException('v110 does not wire the Checkers Telegram C14 corrective: ' . $runtimeToken);
    }
}

if (!str_contains($v110, 'is_file($checkersTelegramHeightFitPath)')) {
    throw new RuntimeException('v110 must fail closed when the Telegram height-fit asset is missing.');
}

echo "checkers-telegram-viewport-scroll-c14=ok\n";
