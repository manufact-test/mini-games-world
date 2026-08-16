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

$manifestPath = __DIR__ . '/runtime/client/version-manifest.php';
$versionManifest = require $manifestPath;
if (!is_array($versionManifest)
    || !is_array($versionManifest['imports'] ?? null)
    || !is_array($versionManifest['assets'] ?? null)
    || ($versionManifest['version'] ?? null) !== 'v2-route-scoped-polling') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mini Games World client version manifest is unavailable.';
    exit;
}

$imports = $versionManifest['imports'];
$assets = $versionManifest['assets'];
foreach (['@mgw/clean-entry', '@mgw/main', './assets/js/state.js?v=27', './assets/js/router.js?v=27'] as $requiredImport) {
    if (!isset($imports[$requiredImport]) || !is_string($imports[$requiredImport]) || $imports[$requiredImport] === '') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Mini Games World client version manifest import is unavailable: ' . $requiredImport . '.';
        exit;
    }
}
foreach (['main_css', 'consistency_css', 'bootstrap'] as $requiredAsset) {
    if (!isset($assets[$requiredAsset]) || !is_string($assets[$requiredAsset]) || $assets[$requiredAsset] === '') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Mini Games World client version manifest asset is unavailable: ' . $requiredAsset . '.';
        exit;
    }
}

$headClose = '</head>';
$cssAnchor = './assets/css/main.css?v=93-wallet-15-3';
$entryScriptsAnchor = <<<'HTML'
  <script type="module" src="./assets/js/production-regression-fix-entry.js?v=102"></script>
  <script type="module" src="./assets/js/main.js?v=98.4-wallet-15-3"></script>
HTML;
$hotfixAnchor = 'data-hotfix-build="v98-mvp14-notification-canonical-owner"';

foreach ([
    'head' => $headClose,
    'css' => $cssAnchor,
    'entry_scripts' => $entryScriptsAnchor,
    'hotfix_build' => $hotfixAnchor,
] as $anchorName => $anchor) {
    if (!str_contains($html, $anchor)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Mini Games World v110 source anchor is unavailable: ' . $anchorName . '.';
        exit;
    }
}

try {
    $importMapPayload = json_encode(
        ['imports' => $imports],
        JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    );
} catch (JsonException $error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mini Games World client version manifest cannot be rendered.';
    exit;
}
$importMap = "<script type=\"importmap\">\n{$importMapPayload}\n</script>";
$html = str_replace($headClose, "  " . $importMap . "\n" . $headClose, $html);

$cssTarget = $assets['main_css'];
$consistencyCssTarget = $assets['consistency_css'];
$bootstrapTarget = $assets['bootstrap'];
$bootstrapTag = '  <script type="module" src="' . $bootstrapTarget . '"></script>';

$html = str_replace($cssAnchor, $cssTarget, $html);
$html = str_replace('./assets/css/production-v95-consistency.css?v=95', $consistencyCssTarget, $html);
$html = str_replace(
    '<p>Готовим игровую комнату</p>',
    '<p>Те самые игры. То самое чувство.</p>',
    $html
);
$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);
$html = str_replace(
    $hotfixAnchor,
    'data-hotfix-build="v110-mvp16-route-scoped-polling-v1167"',
    $html
);

$requiredRenderedTargets = [
    'client_bootstrap_v2' => $bootstrapTarget,
    'clean_entry_v110' => $imports['@mgw/clean-entry'],
    'main_v110' => $imports['@mgw/main'],
    'shield_king_css' => $cssTarget,
    'unified_ui_cache' => $imports['./assets/js/ui.js?v=89'] ?? '',
    'match_config_cache' => $imports['./assets/js/config.js?v=38'] ?? '',
    'app_state_v2_cache' => $imports['./assets/js/state.js?v=27'],
    'router_v2_cache' => $imports['./assets/js/router.js?v=27'],
    'unified_home_cache' => $imports['./assets/js/screens/home-screen.js?v=74'] ?? '',
    'match_shell_cache' => $imports['./assets/js/main-v110-handoff-shell.js?v=1137&ux=1&sk=3&icons=c1efd5af&render=5'] ?? '',
    'unified_profile_cache' => $imports['./assets/js/screens/profile-screen-v110.js?v=1108'] ?? '',
];
foreach ($requiredRenderedTargets as $targetName => $target) {
    if ($target === '' || !str_contains($html, $target)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Mini Games World v110 transformed target is unavailable: ' . $targetName . '.';
        exit;
    }
}

if (substr_count($html, '<script type="module" src="') !== 1) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mini Games World v110 must expose exactly one top-level module bootstrap.';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-MGW-Client-Bootstrap: v2-single-owner');
header('X-MGW-Router: v2-route-registry-cleanup');
header('X-MGW-Query-Version-Manifest: v2-route-scoped-polling');
header('X-MGW-Api-Session-Graph: v1132-canonical-profile');
header('X-MGW-Profile-API: provider-neutral-mgw-v1');
header('X-MGW-Profile-Consumer: unified-profile-avatar-v1');
header('X-MGW-Balance-UI: unified-balance-v1');
header('X-MGW-Match-Economy: server-config-v1');
header('X-MGW-Notification-Graph: v1139-three-state-scroll-stable');
header('X-MGW-Notification-Palette: green-red-blue-v1');
header('X-MGW-Invite-Graph: v1143-prepared-share-owner');
header('X-MGW-Search-Graph: v107-route-scoped-lifecycle');
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
