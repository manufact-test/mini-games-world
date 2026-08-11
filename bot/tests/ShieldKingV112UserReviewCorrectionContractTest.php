<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$css = file_get_contents($root . '/app/assets/css/shield-king-icons-v9.css');
$main = file_get_contents($root . '/app/assets/css/main.css');
$entry = file_get_contents($root . '/app/v110.php');
$runtimeFiles = file_get_contents($root . '/bot/helpers/staging-e2e-runtime-files.txt');
foreach ([$css, $main, $entry, $runtimeFiles] as $source) {
    if (!is_string($source)) throw new RuntimeException('Missing Shield King V112 source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($main, "@import url('./shield-king-icons-v9.css?v=132&icons=c1efd5af&review=user-correction');"),
    'V112 correction must be the final Shield King CSS layer.');
$assert(str_contains($entry, 'main.css?v=131&sk=3&icons=c1efd5af&render=11&review=user-correction')
    && str_contains($entry, 'X-MGW-App-Entry-Presentation: shield-king-v112-assembled-mark')
    && str_contains($entry, 'X-MGW-Icon-Render: accepted-v112-user-correction'),
    'v110 must expose a fresh V112 render identity.');
$assert(str_contains($runtimeFiles, 'app/assets/css/shield-king-icons-v9.css'),
    'Exact staging fingerprint must include the V112 layer.');

foreach ([
    'border:1px solid rgba(230,232,239,.34) !important',
    'border-radius:20px !important',
    'background:none !important',
    'left:-40px !important',
    'top:-92px !important',
    'width:190px !important',
    'height:253px !important',
    'padding:8px 49px 0 112px !important',
    '.section-title + .game-card{margin-top:54px !important}',
    '.game-card + .game-card{margin-top:64px !important}',
    'transform:translate(-3px,-2px) !important',
    'background:url(\'../../icons/shield-king/mgw-mark.svg\') center/contain no-repeat !important',
    'animation:skV112MarkLock .58s .72s',
    'animation:skV112PlateLeft 1.05s',
    'animation:skV112PlateRight 1.05s',
    'animation:skV112Crown 1.05s',
] as $needle) {
    $assert(str_contains($css, $needle), 'Missing V112 visual contract: ' . $needle);
}

$assert(!str_contains($css, '.mgw-phase-b-'),
    'V112 must not select or modify the accepted game-entry Phase B owner.');
$assert(!str_contains($css, 'clip-path:'),
    'V112 card correction must not use clipped card corners.');
$assert(!str_contains($css, 'animation:skV112PlateLeft 1.05s cubic-bezier(.16,1,.3,1) infinite'),
    'App-entry fragment assembly must be one-shot, not a perpetual jump loop.');

fwrite(STDOUT, "ShieldKingV112UserReviewCorrectionContractTest: {$assertions} assertions passed\n");
