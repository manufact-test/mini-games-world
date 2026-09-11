<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$tail = file_get_contents($root . '/app/assets/css/games/checkers/telegram-footer-scroll-tail-v1.css');
$wrapper = file_get_contents($root . '/app/assets/css/production-v108-profile-entry-preview-live-owner-checkers-fit.css');
$manifest = file_get_contents($root . '/app/runtime/client/version-manifest.php');

if (!is_string($tail) || !is_string($wrapper) || !is_string($manifest)) {
    throw new RuntimeException('Checkers footer-scroll contract sources are unavailable.');
}

foreach ([
    '.game-board-screen[data-game-type="checkers"] .content',
    'height:auto!important',
    'flex:1 1 auto!important',
    'min-height:0!important',
    'overflow-y:auto!important',
    'overflow-x:hidden!important',
    'touch-action:pan-y',
    '56px',
    'env(safe-area-inset-bottom, 0px)',
    'var(--tg-content-safe-area-inset-bottom, 0px)',
    'scroll-padding-bottom',
] as $token) {
    if (!str_contains($tail, $token)) {
        throw new RuntimeException('Checkers real content-scroll owner is missing: ' . $token);
    }
}

$tailRules = preg_replace('~/\*.*?\*/~s', '', $tail);
if (!is_string($tailRules)) {
    throw new RuntimeException('Checkers footer-scroll CSS cannot be normalized.');
}
if (str_contains($tailRules, '.board.checkers-surface') || preg_match('/#leaveGame\s*\{/u', $tailRules) === 1) {
    throw new RuntimeException('Real content-scroll corrective must not resize the board or reposition the leave control.');
}
if (!str_contains($wrapper, "./games/checkers/telegram-footer-scroll-tail-v1.css?v=2&checkers=real-content-scroll-v2")) {
    throw new RuntimeException('Checkers real content-scroll owner is not published after the accepted historical sizing owner.');
}
if (!str_contains($manifest, "production-v108-profile-entry-preview-live-owner-checkers-fit.css?v=7&checkers=real-content-scroll-v2")) {
    throw new RuntimeException('Checkers real content-scroll consistency asset is not cache-busted.');
}

echo "checkers-real-content-scroll=ok\n";
