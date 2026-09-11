<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$spacing = file_get_contents($root . '/app/assets/css/games/checkers/historical-outer-spacing-v1.css');
$wrapper = file_get_contents($root . '/app/assets/css/production-v108-profile-entry-preview-live-owner-checkers-fit.css');
$manifest = file_get_contents($root . '/app/runtime/client/version-manifest.php');

if (!is_string($spacing) || !is_string($wrapper) || !is_string($manifest)) {
    throw new RuntimeException('Checkers historical outer-spacing contract sources are unavailable.');
}

foreach ([
    '.game-board-screen[data-game-type="checkers"] .content',
    'padding:14px 14px 20px',
    '.game-board-screen[data-game-type="checkers"] .board-wrap',
    'padding:6px',
    'margin-top:4px',
    'border-radius:20px',
    'overflow:hidden',
    '@media (max-height:720px)',
    'padding-top:10px',
    'padding-bottom:14px',
    '@media (max-height:700px)',
    'padding-top:8px',
    '@media (max-width:380px)',
    'padding-left:10px',
    'padding-right:10px',
] as $token) {
    if (!str_contains($spacing, $token)) {
        throw new RuntimeException('Checkers historical outer-spacing owner is missing: ' . $token);
    }
}

$rules = preg_replace('~/\*.*?\*/~s', '', $spacing);
if (!is_string($rules)) {
    throw new RuntimeException('Checkers historical outer-spacing CSS cannot be normalized.');
}
foreach ([
    '#leaveGame',
    '.board.checkers-surface',
    'position:absolute',
    'position:sticky',
    'overflow-y:auto!important',
] as $forbidden) {
    if (str_contains($rules, $forbidden)) {
        throw new RuntimeException('Historical outer-spacing layer must not own exit/board/scroll mechanics: ' . $forbidden);
    }
}

if (!str_contains($wrapper, "./games/checkers/historical-outer-spacing-v1.css?v=1&checkers=pre-b94d-outer-spacing-v1")) {
    throw new RuntimeException('Historical outer-spacing owner is not published after the accepted historical sizing owner.');
}
if (str_contains($wrapper, 'telegram-footer-scroll-tail-v1.css')) {
    throw new RuntimeException('Rejected Checkers footer-scroll layer must not remain in the active consistency chain.');
}
if (!str_contains($manifest, "production-v108-profile-entry-preview-live-owner-checkers-fit.css?v=8&checkers=pre-b94d-outer-spacing-v1")) {
    throw new RuntimeException('Historical outer-spacing consistency asset is not cache-busted.');
}

echo "checkers-historical-outer-spacing=ok\n";
