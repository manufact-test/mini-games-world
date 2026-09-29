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

require_once __DIR__ . '/runtime/localization/LocalizationCatalog.php';
try {
    $localizationCatalog = new LocalizationCatalog(__DIR__ . '/locales');
    $localizationPayload = $localizationCatalog->clientPayload();
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mini Games World localization catalog is unavailable.';
    exit;
}

$imports = $versionManifest['imports'];
$assets = $versionManifest['assets'];

// Accepted client/cache identities are canonicalized in version-manifest.php.
// The entrypoint only renders the manifest; it no longer mutates accepted owners at runtime.

$localizationConfig = $versionManifest['localization'] ?? null;
if (!is_array($localizationConfig)
    || ($localizationConfig['version'] ?? null) !== 'keys-v1'
    || ($localizationConfig['default_locale'] ?? null) !== $localizationCatalog->defaultLocale()) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mini Games World localization version manifest is unavailable.';
    exit;
}

foreach (['@mgw/clean-entry', '@mgw/main', '@mgw/i18n', './assets/js/state.js?v=27', './assets/js/router.js?v=27'] as $requiredImport) {
    if (!isset($imports[$requiredImport]) || !is_string($imports[$requiredImport]) || $imports[$requiredImport] === '') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Mini Games World client version manifest import is unavailable: ' . $requiredImport . '.';
        exit;
    }
}

// Accepted game/render owners are read directly from the canonical manifest.
$chessRendererImportKey = './assets/js/games/chess/renderer.js?v=68';
$goRendererImportKey = './assets/js/games/go/renderer.js?v=70';
$dominoRendererImportKey = './assets/js/games/domino/renderer.js?v=74';
$battleshipRendererImportKey = './assets/js/games/battleship/renderer.js?v=56';
$battleshipGameScreenImportKey = './assets/js/screens/game-screen-v102.js?v=102';

foreach ([
    $chessRendererImportKey,
    $goRendererImportKey,
    $dominoRendererImportKey,
    $battleshipRendererImportKey,
    $battleshipGameScreenImportKey,
] as $requiredGameOwner) {
    if (!isset($imports[$requiredGameOwner])
        || !is_string($imports[$requiredGameOwner])
        || $imports[$requiredGameOwner] === '') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Mini Games World accepted game owner is unavailable: ' . $requiredGameOwner . '.';
        exit;
    }
}

foreach ([
    'main_css',
    'consistency_css',
    'bootstrap',
    'checkers_height_fit',
    'battleship_exit_fit',
    'chess_capture_parity',
    'go_exit_fit',
    'go_live_effects',
    'go_capture_overlay_v8',
    'go_capture_overlay_v9',
    'go_rules_alignment',
] as $requiredAsset) {
    if (!isset($assets[$requiredAsset]) || !is_string($assets[$requiredAsset]) || $assets[$requiredAsset] === '') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Mini Games World client version manifest asset is unavailable: ' . $requiredAsset . '.';
        exit;
    }
}

$checkersTelegramHeightFitTarget = $assets['checkers_height_fit'];
$battleshipExitFitTarget = $assets['battleship_exit_fit'];
$chessCaptureParityTarget = $assets['chess_capture_parity'];
$goExitFitTarget = $assets['go_exit_fit'];
$goEffectsV7Target = $assets['go_live_effects'];
$goCaptureOverlayV8Target = $assets['go_capture_overlay_v8'];
$goCaptureOverlayV9Target = $assets['go_capture_overlay_v9'];
$goRulesAlignmentTarget = $assets['go_rules_alignment'];

foreach ([
    'checkers_height_fit' => $checkersTelegramHeightFitTarget,
    'battleship_exit_fit' => $battleshipExitFitTarget,
    'chess_capture_parity' => $chessCaptureParityTarget,
    'go_exit_fit' => $goExitFitTarget,
    'go_live_effects' => $goEffectsV7Target,
    'go_capture_overlay_v8' => $goCaptureOverlayV8Target,
    'go_capture_overlay_v9' => $goCaptureOverlayV9Target,
    'go_rules_alignment' => $goRulesAlignmentTarget,
] as $styleName => $styleTarget) {
    $stylePath = parse_url($styleTarget, PHP_URL_PATH);
    if (!is_string($stylePath)
        || !str_starts_with($stylePath, './assets/css/')
        || !is_file(__DIR__ . '/' . substr($stylePath, 2))) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Mini Games World accepted stylesheet is unavailable: ' . $styleName . '.';
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
    $localizationJson = json_encode(
        $localizationPayload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
    );
} catch (JsonException $error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mini Games World client manifests cannot be rendered.';
    exit;
}
$localizationTag = '<script type="application/json" id="mgw-localization">' . $localizationJson . '</script>';
$importMap = "<script type=\"importmap\">\n{$importMapPayload}\n</script>";
$html = str_replace($headClose, "  " . $localizationTag . "\n  " . $importMap . "\n" . $headClose, $html);

$cssTarget = $assets['main_css'];
$consistencyCssTarget = $assets['consistency_css'];
$bootstrapTarget = $assets['bootstrap'];
$bootstrapTag = '  <script type="module" src="' . $bootstrapTarget . '"></script>';
$checkersTelegramHeightFitTag = '  <link rel="stylesheet" href="' . $checkersTelegramHeightFitTarget . '" />';
$battleshipExitFitTag = '  <link rel="stylesheet" data-mgw-battleship-exit-fit="mvp19-12-bounded-screen-scroll-v1" href="' . $battleshipExitFitTarget . '" />';
$chessCaptureParityTag = '  <link rel="stylesheet" href="' . $chessCaptureParityTarget . '" />';
$goExitFitTag = '  <link rel="stylesheet" data-mgw-go-live-exit-fit="mvp19-8-full-width-scroll-v3" href="' . $goExitFitTarget . '" />';
$goEffectsV7Tag = '  <link rel="stylesheet" data-mgw-go-live-effects-v7="mvp19-8-effect2-single-pass-territory-final-v7" href="' . $goEffectsV7Target . '" />';
$goCaptureOverlayV8Tag = '  <link rel="stylesheet" data-mgw-go-live-capture-overlay-v8="mvp19-8-capture-overlay-v8" href="' . $goCaptureOverlayV8Target . '" />';
$goCaptureOverlayV9Tag = '  <link rel="stylesheet" data-mgw-go-live-capture-overlay-v9="mvp19-8-stable-capture-overlay-v9" href="' . $goCaptureOverlayV9Target . '" />';
$goRulesAlignmentTag = '  <link rel="stylesheet" data-mgw-go-rules-alignment="mvp19-8-rule-marker-size-parity-v3" href="' . $goRulesAlignmentTarget . '" />';

$html = str_replace($cssAnchor, $cssTarget, $html);
$html = str_replace('./assets/css/production-v95-consistency.css?v=95', $consistencyCssTarget, $html);
$html = str_replace($headClose, $checkersTelegramHeightFitTag . "\n" . $battleshipExitFitTag . "\n" . $chessCaptureParityTag . "\n" . $goExitFitTag . "\n" . $goEffectsV7Tag . "\n" . $goCaptureOverlayV8Tag . "\n" . $goCaptureOverlayV9Tag . "\n" . $goRulesAlignmentTag . "\n" . $headClose, $html);
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
    'localization_i18n' => $imports['@mgw/i18n'],
    'shield_king_css' => $cssTarget,
    'checkers_telegram_height_fit' => $checkersTelegramHeightFitTarget,
    'battleship_viewport_scroll' => $battleshipExitFitTarget,
    'chess_capture_store_parity' => $chessCaptureParityTarget,
    'go_full_width_scroll' => $goExitFitTarget,
    'go_effect2_single_pass_territory_final' => $goEffectsV7Target,
    'go_capture_overlay_v8' => $goCaptureOverlayV8Target,
    'go_capture_overlay_v9' => $goCaptureOverlayV9Target,
    'go_rules_marker_alignment' => $goRulesAlignmentTarget,
    'go_renderer' => $imports[$goRendererImportKey],
    'chess_renderer' => $imports[$chessRendererImportKey],
    'domino_renderer' => $imports[$dominoRendererImportKey],
    'battleship_renderer' => $imports[$battleshipRendererImportKey],
    'battleship_game_screen' => $imports[$battleshipGameScreenImportKey],
    'unified_ui_cache' => $imports['./assets/js/ui.js?v=89'] ?? '',
    'match_config_cache' => $imports['./assets/js/config.js?v=38'] ?? '',
    'app_state_v2_cache' => $imports['./assets/js/state.js?v=27'],
    'router_v2_cache' => $imports['./assets/js/router.js?v=27'],
    'unified_home_cache' => $imports['./assets/js/screens/home-screen.js?v=74'] ?? '',
    'match_shell_cache' => $imports['./assets/js/main-v110-handoff-shell.js?v=1137&ux=1&sk=3&icons=c1efd5af&render=5'] ?? '',
    'unified_profile_cache' => $imports['./assets/js/screens/profile-screen-v110.js?v=1108'] ?? '',
    'visible_rating_profile' => $imports['./assets/js/screens/profile-screen-v110.js?v=1126&profile_base=accepted-game-cosmetics'] ?? '',
];
foreach ($requiredRenderedTargets as $targetName => $target) {
    if ($target === '' || !str_contains($html, $target)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Mini Games World v110 transformed target is unavailable: ' . $targetName . '.';
        exit;
    }
}

if (!str_contains($html, 'id="mgw-localization"')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mini Games World localization payload is unavailable.';
    exit;
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
header('X-MGW-Localization: keys-v1');
header('X-MGW-Locale: ' . $localizationCatalog->defaultLocale());
header('X-MGW-Rules-Languages: ru');
header('X-MGW-Api-Session-Graph: v1132-canonical-profile');
header('X-MGW-Profile-API: provider-neutral-mgw-v1');
header('X-MGW-Profile-Consumer: unified-profile-avatar-v1');
header('X-MGW-Balance-UI: unified-balance-v1');
header('X-MGW-Match-Economy: server-config-v1');
header('X-MGW-Visible-Rating: per-game-preseason-v2');
header('X-MGW-Leaderboards: per-game-antifarming-v1');
header('X-MGW-Tournaments: official-registration-v2-rules');
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
header('X-MGW-Go-Viewport: full-width-scroll-v3');
header('X-MGW-Go-Live-Effects: effect2-stable-overlay-v9-territory-final-v7');
header('X-MGW-Go-Rules-Markers: size-parity-v3');
header('X-MGW-Domino-Hand-Gesture: v27-pan-y-js-horizontal');
header('X-MGW-Domino-Live-Precision: local-owner-readable-radial-v37');
header('X-MGW-Domino-Live-Effects: precision-readable-radial-stock-crossburst-v37');
echo $html;