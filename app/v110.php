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
$cssAnchor = './assets/css/main.css?v=93-wallet-15-3';
$mainAnchor = './assets/js/main.js?v=98.4-wallet-15-3';
$cleanEntryAnchor = './assets/js/production-regression-fix-entry.js?v=102';
$hotfixAnchor = 'data-hotfix-build="v98-mvp14-notification-canonical-owner"';

foreach ([
    'head' => $headClose,
    'css' => $cssAnchor,
    'main' => $mainAnchor,
    'clean_entry' => $cleanEntryAnchor,
    'hotfix_build' => $hotfixAnchor,
] as $anchorName => $anchor) {
    if (!str_contains($html, $anchor)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Mini Games World v110 source anchor is unavailable: ' . $anchorName . '.';
        exit;
    }
}

$importMap = <<<'HTML'
<script type="importmap">
{
  "imports": {
    "./assets/js/api/client.js?v=34": "./assets/js/api/client.js?v=1132&mvp15=unified-profile",
    "./assets/js/api/client.js?v=38": "./assets/js/api/client.js?v=1132&mvp15=unified-profile",
    "./assets/js/api/client.js?v=46": "./assets/js/api/client.js?v=1132&mvp15=unified-profile",
    "./assets/js/api/client.js?v=47": "./assets/js/api/client.js?v=1132&mvp15=unified-profile",
    "./assets/js/config.js?v=38": "./assets/js/config.js?v=39&mvp15=match-economy",
    "./assets/js/state.js?v=27": "./assets/js/state.js?v=29&mvp15=match-economy",
    "./assets/js/ui.js?v=89": "./assets/js/ui.js?v=92&mvp15=unified-zone",
    "./assets/js/screens/home-screen.js?v=74": "./assets/js/screens/home-screen.js?v=78&mvp15=weekly-bonus-wallet",
    "./assets/js/screens/store-screen.js?v=34": "./assets/js/screens/store-screen.js?v=35&mvp15=unified-balance",
    "./assets/js/screens/profile-screen-v110.js?v=1108": "./assets/js/screens/profile-screen-v110.js?v=1113&mvp15=unified-balance-copy-cleanup",
    "./assets/js/main-v110-handoff-shell.js?v=1137&ux=1&sk=3&icons=c1efd5af&render=5": "./assets/js/main-v110-handoff-shell.js?v=1146&mvp15=notification-polish",
    "./assets/js/session.js?v=21": "./assets/js/session.js?v=1131",
    "./assets/js/session.js?v=27": "./assets/js/session.js?v=1131",
    "./assets/js/screens/search-screen-v102.js?v=103": "./assets/js/screens/search-screen-v102.js?v=106&search=post-game-release-barrier",
    "./assets/js/screens/game-screen-v102-safe.js?v=102": "./assets/js/screens/game-screen-v102-safe.js?v=103&result=terminal-watch-priority",
    "./assets/js/screens/game-screen-v102.js?v=102": "./assets/js/screens/game-screen-v102.js?v=104&clock=phase-b-single-writer&battleship=leave-guard",
    "./assets/js/production-v100-optimistic-models.js?v=102": "./assets/js/production-v100-optimistic-models.js?v=104&clock=ttt-fresh60&battleship=registered-owner",
    "./assets/js/production-v102-battleship-models.js?v=102": "./assets/js/production-v102-battleship-models.js?v=103&ready=authoritative-reset",
    "./assets/js/production-v110-readonly-game-sync.js?v=1107&b=bc9d7b435f1a": "./assets/js/production-v110-readonly-game-sync.js?v=1112&terminal=nonblocking-watch",
    "./assets/js/production-v110-targeted-interactions.js?v=1102": "./assets/js/production-v110-targeted-interactions.js?v=1105&zone=unified",
    "./assets/js/production-v110-presence.js?v=1121&b=f5a28b030c69": "./assets/js/production-v110-presence.js?v=1123&zone=unified",
    "./assets/js/games/game-invites-v110.js?v=1137&ux=1": "./assets/js/games/game-invites-v110.js?v=1140&zone=unified",
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

$html = str_replace($headClose, "  " . $importMap . "\n" . $headClose, $html);

$cssTarget = './assets/css/main.css?v=155&sk=3&icons=c1efd5af&render=29&palette=three-state-notifications&battleship=authoritative-shot-only&wallet=weekly-bonus-cta';
$mainTarget = './assets/js/main-v110.js?v=1139&ux=1&sk=3&icons=c1efd5af&render=5&mvp15=unified-balance';
$cleanEntryTarget = './assets/js/production-clean-entry-v110.js?v=1124&clock=single-writer&release=battleship-action-quarantine';

$html = str_replace($cssAnchor, $cssTarget, $html);
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
$html = str_replace($cleanEntryAnchor, $cleanEntryTarget, $html);
$html = str_replace($mainAnchor, $mainTarget, $html);
$html = str_replace(
    $hotfixAnchor,
    'data-hotfix-build="v110-mvp15-unified-balance-copy-cleanup-v1164"',
    $html
);

foreach ([
    'main_v110' => $mainTarget,
    'clean_entry_v110' => $cleanEntryTarget,
    'shield_king_css' => $cssTarget,
    'unified_ui_cache' => './assets/js/ui.js?v=92&mvp15=unified-zone',
    'match_config_cache' => './assets/js/config.js?v=39&mvp15=match-economy',
    'match_state_cache' => './assets/js/state.js?v=29&mvp15=match-economy',
    'unified_home_cache' => './assets/js/screens/home-screen.js?v=78&mvp15=weekly-bonus-wallet',
    'match_shell_cache' => './assets/js/main-v110-handoff-shell.js?v=1146&mvp15=notification-polish',
    'unified_profile_cache' => './assets/js/screens/profile-screen-v110.js?v=1113&mvp15=unified-balance-copy-cleanup',
] as $targetName => $target) {
    if (!str_contains($html, $target)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Mini Games World v110 transformed target is unavailable: ' . $targetName . '.';
        exit;
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-MGW-Api-Session-Graph: v1132-canonical-profile');
header('X-MGW-Profile-API: provider-neutral-mgw-v1');
header('X-MGW-Profile-Consumer: unified-profile-avatar-v1');
header('X-MGW-Balance-UI: unified-balance-v1');
header('X-MGW-Match-Economy: server-config-v1');
header('X-MGW-Notification-Graph: v1139-three-state-scroll-stable');
header('X-MGW-Notification-Palette: green-red-blue-v1');
header('X-MGW-Invite-Graph: v1143-prepared-share-owner');
header('X-MGW-Search-Graph: v106-post-game-release-barrier');
header('X-MGW-TTT-Clock: authoritative-turn-clock-v7-handoff-state-retained');
header('X-MGW-TTT-Terminal: v6-terminal-clock-stops-on-finish');
header('X-MGW-Rematch-UX: v3-single-owner-no-busy-state');
header('X-MGW-Launch-Presentation: v128-ready-before-first-turn');
header('X-MGW-Presence: v1123-account-presence-only');
header('X-MGW-Game-Zone: unified-v1');
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
