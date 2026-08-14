<?php
declare(strict_types=1);

$indexPath = __DIR__ . '/index.html';
$html = file_get_contents($indexPath);
if (!is_string($html)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mini Games World entrypoint is unavailable.';
    exit;
}

$headClose = '</head>';
$importMap = <<<'HTML'
<script type="importmap">
{
  "imports": {
    "./assets/js/api/client.js?v=34": "./assets/js/api/client.js?v=1132&profile=mgw-canonical",
    "./assets/js/api/client.js?v=38": "./assets/js/api/client.js?v=1132&profile=mgw-canonical",
    "./assets/js/api/client.js?v=46": "./assets/js/api/client.js?v=1132&profile=mgw-canonical",
    "./assets/js/api/client.js?v=47": "./assets/js/api/client.js?v=1132&profile=mgw-canonical",
    "./assets/js/ui.js?v=89": "./assets/js/ui.js?v=90&profile=single-owner",
    "./assets/js/session.js?v=21": "./assets/js/session.js?v=1131",
    "./assets/js/session.js?v=27": "./assets/js/session.js?v=1131",
    "./assets/js/screens/search-screen-v102.js?v=103": "./assets/js/screens/search-screen-v102.js?v=106&search=post-game-release-barrier",
    "./assets/js/screens/game-screen-v102-safe.js?v=102": "./assets/js/screens/game-screen-v102-safe.js?v=103&result=terminal-watch-priority",
    "./assets/js/screens/game-screen-v102.js?v=102": "./assets/js/screens/game-screen-v102.js?v=104&clock=phase-b-single-writer&battleship=leave-guard",
    "./assets/js/screens/profile-screen-v110.js?v=1108": "./assets/js/screens/profile-screen-v110.js?v=1109&profile=mgw-canonical",
    "./assets/js/production-v100-optimistic-models.js?v=102": "./assets/js/production-v100-optimistic-models.js?v=104&clock=ttt-fresh60&battleship=registered-owner",
    "./assets/js/production-v102-battleship-models.js?v=102": "./assets/js/production-v102-battleship-models.js?v=103&ready=authoritative-reset",
    "./assets/js/production-v110-readonly-game-sync.js?v=1107&b=bc9d7b435f1a": "./assets/js/production-v110-readonly-game-sync.js?v=1112&terminal=nonblocking-watch",
    "./assets/js/production-v110-targeted-interactions.js?v=1102": "./assets/js/production-v110-targeted-interactions.js?v=1104&rematch=single-owner",
    "./assets/js/production-v110-presence.js?v=1121&b=f5a28b030c69": "./assets/js/production-v110-presence.js?v=1122&room=presence-owner",
    "./assets/js/games/game-invites-v110.js?v=1137&ux=1": "./assets/js/games/game-invites-v110.js?v=1139&share=prepared-owner-rematch-clean",
    "./assets/js/games/tictactoe/renderer.js?v=53": "./assets/js/games/tictactoe/renderer.js?v=54&mark=full-size-nought",
    "./assets/js/games/battleship/renderer.js?v=56": "./assets/js/games/battleship/renderer.js?v=60&shot=miss-no-impact",
    "./assets/js/production-v110-acceptance-runtime.js?v=110": "./assets/js/production-v110-acceptance-runtime.js?v=130&clock=battleship-setup-single-owner",
    "./assets/js/components/shield-king-visuals.js?v=125&sk=2": "./assets/js/components/shield-king-visuals.js?v=126&sk=3&icons=c1efd5af",
    "./assets/js/components/preloader.js?v=42": "./assets/js/components/preloader.js?v=44&intro=v1141",
    "./assets/js/games/game-card-copy.js?v=81&sk=2": "./assets/js/games/game-card-copy.js?v=83&sk=5&icons=c1efd5af&delivery=static"
  }
}
</script>
HTML;

if (!str_contains($html, $headClose)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mini Games World import-map anchor is unavailable.';
    exit;
}

$html = str_replace($headClose, "  " . $importMap . "\n" . $headClose, $html);
$html = str_replace(
    './assets/css/main.css?v=92',
    './assets/css/main.css?v=152&sk=3&icons=c1efd5af&render=28&palette=notification-semantic&battleship=authoritative-shot-only',
    $html
);
$html = str_replace(
    './assets/css/production-v95-consistency.css?v=95',
    './assets/css/production-v95-consistency.css?v=96&battleship=pending-lock-only',
    $html
);
$html = str_replace(
    '<p>Готовим игровую комнату</p>',
    '<p>Те самые игры. То самое чувство.</p>',
    $html
);
$html = str_replace(
    './assets/js/production-regression-fix-entry.js?v=102',
    './assets/js/production-clean-entry-v110.js?v=1124&clock=single-writer&release=battleship-action-quarantine',
    $html
);
$html = str_replace(
    './assets/js/main.js?v=98.3',
    './assets/js/main-v110.js?v=1138&ux=1&sk=3&icons=c1efd5af&render=5',
    $html
);
$html = str_replace(
    'data-hotfix-build="v98-mvp14-notification-canonical-owner"',
    'data-hotfix-build="v110-mvp15-unified-profile-v1157"',
    $html
);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-MGW-Api-Session-Graph: v1132-profile-canonical');
header('X-MGW-Profile-Graph: mvp15-2-canonical-mgw-id-avatar');
header('X-MGW-Notification-Graph: v1138-shield-semantic-tone');
header('X-MGW-Notification-Palette: shield-king-v1-semantic');
header('X-MGW-Invite-Graph: v1143-prepared-share-owner');
header('X-MGW-Search-Graph: v106-post-game-release-barrier');
header('X-MGW-TTT-Clock: authoritative-turn-clock-v7-handoff-state-retained');
header('X-MGW-TTT-Terminal: v6-terminal-clock-stops-on-finish');
header('X-MGW-Rematch-UX: v3-single-owner-no-busy-state');
header('X-MGW-Launch-Presentation: v128-ready-before-first-turn');
header('X-MGW-Presence: v1122-room-occupancy-owner');
header('X-MGW-Phase-B-Presentation: v124-v110-player-copy-stable-frame');
header('X-MGW-App-Entry-Presentation: shield-king-v1141-nostalgic-entry-copy');
header('X-MGW-Design-System: shield-king-v2-light-metallic');
header('X-MGW-Icon-Pack: c1efd5afbf0125a090b1755fed2b40cb2cc6f2e1');
header('X-MGW-Icon-Render: accepted-v1145-more-optical-center');
header('X-MGW-Battleship-Setup: v102-registered-optimistic-owner');
header('X-MGW-Battleship-Leave: v110-action-quarantine');
header('X-MGW-Game-Timer-Frame: shared-80px-13px');
header('X-MGW-Battleship-Setup-Clock: dedicated-setup-timer-single-owner');
header('X-MGW-Battleship-Player-Cards: desktop-secondary-labels-visible');
header('X-MGW-Battleship-Ready: authoritative-reset-after-edit');
header('X-MGW-Battleship-Miss-Handoff: 900ms');
header('X-MGW-Battleship-Shot-Feedback: hit-sunk-impact-miss-static');
header('X-MGW-Battleship-Pending-Paint: none-legacy-owner-removed');
echo $html;
