<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$css = file_get_contents($root . '/app/assets/css/games/checkers/game.css');
$renderer = file_get_contents($root . '/app/assets/js/games/checkers/renderer.js');
$wrapper = file_get_contents($root . '/app/assets/css/production-v108-profile-entry-preview-live-owner-checkers-fit.css');
$manifest = file_get_contents($root . '/app/runtime/client/version-manifest.php');

if (!is_string($css) || !is_string($renderer) || !is_string($wrapper) || !is_string($manifest)) {
    throw new RuntimeException('Checkers MVP-16.7 restore contract sources are unavailable.');
}

$gitBlobSha = static fn(string $content): string => sha1('blob ' . strlen($content) . "\0" . $content);

if ($gitBlobSha($css) !== 'c25a30a386ce27c73030035b966f9a0907d9afcc') {
    throw new RuntimeException('Checkers CSS must be byte-identical to accepted MVP-16.7 SHA 3f6277cb3abfe7dd11c58a90c783afa07b1f6839.');
}
if ($gitBlobSha($renderer) !== 'e362239b1388a1f752d2d0e67ae69a7cc9207926') {
    throw new RuntimeException('Checkers renderer must remain byte-identical to accepted MVP-16.7.');
}

foreach ([
    '.checkers-surface{',
    'width:100%;',
    '.game-board-screen[data-game-type="checkers"] .board.checkers-surface{',
    'max-width:100%;',
    'margin:0;',
    '.game-board-screen[data-game-type="checkers"] .content{padding:8px 8px 14px}',
    '.game-board-screen[data-game-type="checkers"] .board-wrap{padding:3px;margin:4px auto 8px;border-radius:10px;overflow:visible}',
] as $token) {
    if (!str_contains($css, $token)) {
        throw new RuntimeException('Accepted Checkers presentation token is missing: ' . $token);
    }
}

if (!str_contains($wrapper, "./games/checkers/game.css?v=63&checkers=mvp16-7-accepted-restore-v1")) {
    throw new RuntimeException('Accepted MVP-16.7 Checkers CSS is not the active Checkers presentation owner.');
}
foreach ([
    'historical-sizing-owner-v1.css',
    'historical-outer-spacing-v1.css',
    'telegram-footer-scroll-tail-v1.css',
] as $rejectedOwner) {
    if (str_contains($wrapper, $rejectedOwner)) {
        throw new RuntimeException('Rejected recent Checkers corrective remains active: ' . $rejectedOwner);
    }
}

if (!str_contains($manifest, "production-v108-profile-entry-preview-live-owner-checkers-fit.css?v=9&checkers=mvp16-7-accepted-restore-v1")) {
    throw new RuntimeException('Accepted MVP-16.7 Checkers restore is not cache-busted in the runtime manifest.');
}

foreach ([$css, $wrapper] as $presentationSource) {
    if (preg_match('/#leaveGame\s*\{/u', $presentationSource) === 1) {
        throw new RuntimeException('Checkers restore must not reposition the shared leave control.');
    }
    if (str_contains($presentationSource, 'overflow-y:auto!important') || str_contains($presentationSource, 'position:fixed')) {
        throw new RuntimeException('Checkers restore must not add a new scroll/fixed-position repair owner.');
    }
}

echo "checkers-mvp16-7-accepted-restore=ok\n";
