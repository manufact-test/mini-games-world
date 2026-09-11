<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$css = file_get_contents($root . '/app/assets/css/games/checkers/game.css');
$renderer = file_get_contents($root . '/app/assets/js/games/checkers/renderer.js');
$wrapper = file_get_contents($root . '/app/assets/css/production-v108-profile-entry-preview-live-owner-checkers-fit.css');
$manifest = file_get_contents($root . '/app/runtime/client/version-manifest.php');

if (!is_string($css) || !is_string($renderer) || !is_string($wrapper) || !is_string($manifest)) {
    throw new RuntimeException('Checkers exact pre-b94d restore contract sources are unavailable.');
}

$gitBlobSha = static fn(string $content): string => sha1('blob ' . strlen($content) . "\0" . $content);

if ($gitBlobSha($css) !== '12d2f211c48c1f49793744315c004fd33afc5bcf') {
    throw new RuntimeException('Checkers CSS must be byte-identical to commit 7f6540a80b7c624f487dbc9b3a666ae062e70a39, immediately before b94d76060c0bfe8884270075516689272e928600.');
}
if ($gitBlobSha($renderer) !== 'e362239b1388a1f752d2d0e67ae69a7cc9207926') {
    throw new RuntimeException('Current Checkers renderer/game logic must remain untouched by this visual restore.');
}

foreach ([
    '.checkers-surface{',
    'width:100%;',
    'max-width:440px;',
    'margin:0 auto;',
    'border-radius:18px;',
    'background:linear-gradient(145deg,#d9c8a8,#bea884)',
    'background:linear-gradient(145deg,#5d4b58,#3c3343)',
    '.game-board-screen[data-game-type="checkers"] .board-wrap{padding:6px;margin-top:4px;border-radius:20px}',
] as $token) {
    if (!str_contains($css, $token)) {
        throw new RuntimeException('Exact pre-b94d Checkers presentation token is missing: ' . $token);
    }
}

foreach ([
    '.game-board-screen[data-game-type="checkers"] .board.checkers-surface',
    '.game-board-screen[data-game-type="checkers"] .content{padding:8px 8px 14px}',
] as $postB94dToken) {
    if (str_contains($css, $postB94dToken)) {
        throw new RuntimeException('Post-b94d Checkers layout token must not survive exact restore: ' . $postB94dToken);
    }
}

if (!str_contains($wrapper, "./games/checkers/game.css?v=64&checkers=pre-b94d-exact-restore-v1")) {
    throw new RuntimeException('Exact pre-b94d Checkers CSS is not the active Checkers presentation owner.');
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

if (!str_contains($manifest, "production-v108-profile-entry-preview-live-owner-checkers-fit.css?v=10&checkers=pre-b94d-exact-restore-v1")) {
    throw new RuntimeException('Exact pre-b94d Checkers restore is not cache-busted in the runtime manifest.');
}

foreach ([$css, $wrapper] as $presentationSource) {
    if (preg_match('/#leaveGame\s*\{/u', $presentationSource) === 1) {
        throw new RuntimeException('Checkers restore must not reposition the shared leave control.');
    }
    if (str_contains($presentationSource, 'overflow-y:auto!important') || str_contains($presentationSource, 'position:fixed')) {
        throw new RuntimeException('Checkers restore must not add a new scroll/fixed-position repair owner.');
    }
}

echo "checkers-pre-b94d-exact-restore=ok\n";
