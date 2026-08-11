<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$css = file_get_contents($root . '/app/assets/css/shield-king-icons-v10.css');
$main = file_get_contents($root . '/app/assets/css/main.css');
$entry = file_get_contents($root . '/app/v110.php');
$runtimeFiles = file_get_contents($root . '/bot/helpers/staging-e2e-runtime-files.txt');
foreach ([$css, $main, $entry, $runtimeFiles] as $source) {
    if (!is_string($source)) throw new RuntimeException('Missing Shield King V113 source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($main, "@import url('./shield-king-icons-v10.css?v=133&icons=c1efd5af&proof=painted-geometry');"),
    'V113 must be the final Shield King CSS layer.');
$assert(str_contains($entry, 'main.css?v=132&sk=3&icons=c1efd5af&render=12&proof=painted-geometry')
    && str_contains($entry, 'X-MGW-App-Entry-Presentation: shield-king-v113-fullscreen-assembly')
    && str_contains($entry, 'X-MGW-Icon-Render: accepted-v113-painted-geometry'),
    'v110 must expose the exact V113 render identity.');
$assert(str_contains($runtimeFiles, 'app/assets/css/shield-king-icons-v10.css'),
    'Exact staging fingerprint must include V113 CSS.');

foreach ([
    'left:-52px !important',
    'top:-117px !important',
    '.section-title + .game-card{margin-top:80px !important}',
    '.game-card + .game-card{margin-top:80px !important}',
    'transform:none !important',
    "background:url('../icons/shield-king/mgw-mark.svg') center/contain no-repeat !important",
    'animation:skV113PlateLeft 1s .14s',
    'animation:skV113PlateRight 1s .14s',
    'animation:skV113Crown 1s .14s',
    'animation:skV113MarkLock .46s .88s',
] as $needle) {
    $assert(str_contains($css, $needle), 'Missing V113 measured visual contract: ' . $needle);
}

$assert(!str_contains($css, '.mgw-phase-b-'),
    'V113 must not modify game-entry Phase B.');
$assert(!str_contains($css, 'clip-path:'),
    'V113 must not reintroduce clipped card geometry.');
$assert(!str_contains($css, "url('../../icons/shield-king/mgw-mark.svg')"),
    'V113 must not use the invalid /app/icons loader path.');

fwrite(STDOUT, "ShieldKingV113PaintedGeometryContractTest: {$assertions} assertions passed\n");
