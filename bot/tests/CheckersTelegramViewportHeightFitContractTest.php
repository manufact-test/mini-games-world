<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$fitCss = file_get_contents($root . '/app/assets/css/games/checkers/telegram-height-fit-v1.css');
$legacyCss = file_get_contents($root . '/app/assets/css/games/checkers/game.css');
$renderer = file_get_contents($root . '/app/assets/js/games/checkers/renderer.js');
$v110 = file_get_contents($root . '/app/v110.php');

if (!is_string($fitCss) || !is_string($legacyCss) || !is_string($renderer) || !is_string($v110)) {
    throw new RuntimeException('Checkers Telegram viewport height-fit sources are unavailable.');
}

foreach ([
    '@media (max-height:680px)',
    '.game-board-screen[data-game-type="checkers"] .board.checkers-surface{',
    'width:min(100%,clamp(240px,calc(100dvh - 315px),330px));',
] as $token) {
    if (!str_contains($fitCss, $token)) {
        throw new RuntimeException('Checkers Telegram height-fit token is missing: ' . $token);
    }
}

foreach ([
    '#leaveGame',
    'position:fixed',
    'position:sticky',
    'overflow-y:auto!important',
    'transform:',
] as $rejectedToken) {
    if (str_contains($fitCss, $rejectedToken)) {
        throw new RuntimeException('Checkers Telegram height fit must not own shared controls or DOM repair: ' . $rejectedToken);
    }
}

$gitBlobSha = static fn(string $content): string => sha1('blob ' . strlen($content) . "\0" . $content);
if ($gitBlobSha($legacyCss) !== '12d2f211c48c1f49793744315c004fd33afc5bcf') {
    throw new RuntimeException('Accepted pre-b94d Checkers skin must remain byte-identical.');
}
if ($gitBlobSha($renderer) !== 'e362239b1388a1f752d2d0e67ae69a7cc9207926') {
    throw new RuntimeException('Checkers renderer/game logic must remain untouched.');
}

foreach ([
    "./assets/css/games/checkers/telegram-height-fit-v1.css?v=1&checkers=telegram-height-fit-v1",
    "'checkers_telegram_height_fit' => $checkersTelegramHeightFitTarget",
    'STG A1 · v110 · C11',
    'stg-a1-v110-c11',
] as $runtimeToken) {
    if (!str_contains($v110, $runtimeToken)) {
        throw new RuntimeException('v110 does not wire the Checkers Telegram height fit: ' . $runtimeToken);
    }
}

if (!str_contains($v110, "is_file($checkersTelegramHeightFitPath)")) {
    throw new RuntimeException('v110 must fail closed when the Telegram height-fit asset is missing.');
}

echo "checkers-telegram-viewport-height-fit=ok\n";
