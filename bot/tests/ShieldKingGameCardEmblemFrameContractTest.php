<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$css = file_get_contents($root . '/app/assets/css/shield-king-icons-v7.css');
$main = file_get_contents($root . '/app/assets/css/main.css');
$entry = file_get_contents($root . '/app/v110.php');
$runtimeFiles = file_get_contents($root . '/bot/helpers/staging-e2e-runtime-files.txt');
foreach ([$css, $main, $entry, $runtimeFiles] as $source) {
    if (!is_string($source)) throw new RuntimeException('Missing Shield King V9 source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($main, "@import url('./shield-king-icons-v7.css?v=130&icons=c1efd5af&frame=emblem-cut');"),
    'V9 frame CSS must be the final Shield King visual layer.');
$assert(str_contains($entry, 'main.css?v=129&sk=3&icons=c1efd5af&render=9&frame=emblem-cut')
    && str_contains($entry, "X-MGW-Icon-Render: accepted-v9-emblem-cut-frame"),
    'The real /start v110 entry must own a fresh V9 cache/render identity.');
$assert(str_contains($runtimeFiles, 'app/assets/css/shield-king-icons-v7.css'),
    'The exact Hostinger fingerprint must include the V9 frame CSS.');

foreach ([
    '--sk-game-emblem-w:108px',
    '--sk-game-emblem-h:144px',
    '--sk-game-emblem-gap:10px',
    '--sk-game-frame-top-start:118px',
    '--sk-game-frame-left-start:141px',
    'margin-left:-15px !important',
    'margin-right:15px !important',
    'transform:translateY(calc(var(--sk-game-emblem-rise) * -1)) !important',
] as $needle) {
    $assert(str_contains($css, $needle), 'Missing emblem geometry contract: ' . $needle);
}

$assert(str_contains($css, 'transparent 0 var(--sk-game-frame-top-start)')
    && str_contains($css, 'transparent 0 var(--sk-game-frame-left-start)'),
    'The frame must have explicit top/right-side and bottom/left-side air around the emblem.');
$assert(str_contains($css, 'border:0 !important')
    && str_contains($css, 'border-radius:4px 20px 4px 14px !important'),
    'The old rounded card border must yield to the asymmetric metallic frame.');
$assert(str_contains($css, 'place-items:start !important')
    && str_contains($css, 'background:transparent !important')
    && str_contains($css, 'box-shadow:none !important'),
    'The old game-icon tile must not remain underneath the rich emblem.');

$assert(!str_contains($css, '.mgw-phase-b-launch-card')
    && !str_contains($css, '#notificationsOpen')
    && !str_contains($css, '#moreMenuOpen')
    && !str_contains($css, '.game-rules-button'),
    'V9 must be isolated to game-card framing and must not reopen accepted loader/header/rules work.');

fwrite(STDOUT, "ShieldKingGameCardEmblemFrameContractTest: {$assertions} assertions passed\n");
