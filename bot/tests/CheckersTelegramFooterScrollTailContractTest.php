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
    '42px',
    'env(safe-area-inset-bottom, 0px)',
    'var(--tg-content-safe-area-inset-bottom, 0px)',
    'scroll-padding-bottom',
] as $token) {
    if (!str_contains($tail, $token)) {
        throw new RuntimeException('Checkers footer-scroll tail is missing: ' . $token);
    }
}

if (str_contains($tail, '.board.checkers-surface') || str_contains($tail, '#leaveGame')) {
    throw new RuntimeException('Footer-scroll corrective must not resize the board or reposition the leave control.');
}
if (!str_contains($wrapper, "./games/checkers/telegram-footer-scroll-tail-v1.css?v=1&checkers=telegram-footer-scroll-tail-v1")) {
    throw new RuntimeException('Checkers footer-scroll tail is not published after the accepted historical sizing owner.');
}
if (!str_contains($manifest, "production-v108-profile-entry-preview-live-owner-checkers-fit.css?v=6&checkers=telegram-footer-scroll-tail-v1")) {
    throw new RuntimeException('Checkers footer-scroll consistency asset is not cache-busted.');
}

echo "checkers-telegram-footer-scroll-tail=ok\n";
