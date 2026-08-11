<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$css = file_get_contents($root . '/app/assets/css/shield-king-icons-v8.css');
$main = file_get_contents($root . '/app/assets/css/main.css');
$entry = file_get_contents($root . '/app/v110.php');
$runtimeFiles = file_get_contents($root . '/bot/helpers/staging-e2e-runtime-files.txt');
foreach ([$css, $main, $entry, $runtimeFiles] as $source) {
    if (!is_string($source)) throw new RuntimeException('Missing Shield King V111 source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($main, "@import url('./shield-king-icons-v8.css?v=131&icons=c1efd5af&review=consolidated');"),
    'Consolidated V111 CSS must be the final Shield King layer.');
$assert(str_contains($entry, 'main.css?v=130&sk=3&icons=c1efd5af&render=10&review=consolidated')
    && str_contains($entry, 'X-MGW-Icon-Render: accepted-v111-consolidated-review')
    && str_contains($entry, 'X-MGW-App-Entry-Presentation: shield-king-v111-borderless-assembly'),
    'Real v110 entry must own the V111 cache and presentation identity.');
$assert(str_contains($runtimeFiles, 'app/assets/css/shield-king-icons-v8.css'),
    'Hostinger fingerprint must include the consolidated V111 CSS.');

foreach ([
    '--sk-v111-emblem-w:152px',
    '--sk-v111-emblem-h:202px',
    '--sk-v111-emblem-rise:64px',
    'border-radius:18px !important',
    'overflow:visible !important',
    '.game-card + .game-card',
    'margin-top:34px !important',
    'transform:translate(2px,-3px) !important',
    '.preloader .load-card',
    'border:0 !important',
    'shieldKingV111ScatterLeft',
    'shieldKingV111ScatterRight',
    'shieldKingV111ScatterCrown',
] as $needle) {
    $assert(str_contains($css, $needle), 'Missing V111 review contract: ' . $needle);
}

$assert(str_contains($css, 'transparent 0 var(--sk-v111-frame-top-start)')
    && str_contains($css, 'transparent 0 var(--sk-v111-frame-left-start)'),
    'Shared frame must leave deliberate air to the emblem right/bottom edges.');
$assert(str_contains($css, '.game-rules-button > img[data-sk-asset="ui/actions/rules.webp"]')
    && str_contains($css, '.turn-actions .game-rules-button > img[data-sk-asset="ui/actions/rules.webp"]'),
    'Rules/book correction must target both card-side and in-game rules image boxes.');
$assert(!str_contains($css, '.mgw-phase-b-'),
    'V111 app-entry animation must not touch the accepted game-entry/Phase B animation.');
$assert(!str_contains($css, 'TOKENS') && !str_contains($css, 'ICON MAP'),
    'V111 override must not reopen frozen Design System owners.');

fwrite(STDOUT, "ShieldKingConsolidatedUiFixesContractTest: {$assertions} assertions passed\n");
