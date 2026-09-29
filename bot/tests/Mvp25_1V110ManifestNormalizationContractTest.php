<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$manifest = require $root . '/app/runtime/client/version-manifest.php';
$v110 = file_get_contents($root . '/app/v110.php');
$launch = file_get_contents($root . '/bot/helpers/WebAppLaunchUrl.php');

$assert(is_array($manifest), 'Client version manifest must remain loadable.');
$assert(is_string($v110), 'v110 entrypoint must remain readable.');
$assert(is_string($launch), 'Telegram launch owner must remain readable.');

$imports = is_array($manifest['imports'] ?? null) ? $manifest['imports'] : [];
$assets = is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [];

$expectedImportTokens = [
    '@mgw/clean-entry' => 'mvp23_profile=instant-route-v1',
    './assets/js/profile/mgw-mobile-profile-animation-guard-v2.js?v=1' => 'mvp23_profile=instant-resume-v1',
    './assets/js/components/account-shortcuts.js?v=48' => 'mvp23_mobile=atomic-account-data-v1',
    './assets/js/main-v110-handoff-shell.js?v=1137&ux=1&sk=3&icons=c1efd5af&render=5' => 'mvp23_mobile=cold-surfaces-v1',
    './assets/js/components/preloader.js?v=42' => 'mvp23_mobile=bounded-cold-prime-v1',
    './assets/js/games/go/renderer.js?v=70' => 'live_effects=stable-overlay-v9',
    './assets/js/games/domino/renderer.js?v=74' => 'live_effects=v41',
    './assets/js/games/battleship/renderer.js?v=56' => 'destroy=fire-core-v4',
    './assets/js/screens/game-screen-v102.js?v=102' => 'battleship_fire=direct-result-v4',
];

foreach ($expectedImportTokens as $key => $token) {
    $target = $imports[$key] ?? null;
    $assert(
        is_string($target) && $target !== '' && str_contains($target, $token),
        'Accepted manifest owner is missing folded product identity: ' . $key
    );
}

$chessTarget = $imports['./assets/js/games/chess/renderer.js?v=68'] ?? '';
$assert(
    is_string($chessTarget)
        && $chessTarget !== ''
        && !str_contains($chessTarget, 'staging_check_test'),
    'Chess renderer must not retain the accepted manual-test query owner.'
);

$goTarget = $imports['./assets/js/games/go/renderer.js?v=70'] ?? '';
$assert(
    is_string($goTarget)
        && !str_contains($goTarget, 'manual_review='),
    'Go renderer must use a product identity rather than a manual-review identity.'
);

$dominoTarget = $imports['./assets/js/games/domino/renderer.js?v=74'] ?? '';
$assert(
    is_string($dominoTarget)
        && !str_contains($dominoTarget, 'manual-stability')
        && !str_contains($dominoTarget, 'parent=manual-corrective'),
    'Domino active manifest identity must not retain manual acceptance labels.'
);

$requiredStyles = [
    'checkers_height_fit',
    'battleship_exit_fit',
    'chess_capture_parity',
    'go_exit_fit',
    'go_live_effects',
    'go_capture_overlay_v8',
    'go_capture_overlay_v9',
    'go_rules_alignment',
];
foreach ($requiredStyles as $assetKey) {
    $target = $assets[$assetKey] ?? null;
    $assert(is_string($target) && $target !== '', 'Accepted stylesheet manifest key missing: ' . $assetKey);
    $path = is_string($target) ? parse_url($target, PHP_URL_PATH) : null;
    $assert(
        is_string($path)
            && str_starts_with($path, './assets/css/')
            && is_file($root . '/app/' . substr($path, 2)),
        'Accepted stylesheet target must resolve to a repository file: ' . $assetKey
    );
}

$assert(
    is_string($assets['main_css'] ?? null)
        && str_contains((string)$assets['main_css'], 'battleship_fire=direct-result-v4'),
    'Main CSS accepted Battleship fire identity must be owned by the manifest.'
);

foreach ([
    'staging_check_test=any-move-v1',
    'manual_review=effect2-stable-overlay-v9',
    'Temporary staging-only MVP-19.5 acceptance hook',
    'Staging manual-acceptance cache hook',
    'X-MGW-Chess-Check-Test',
] as $residue) {
    $assert(!str_contains($v110, $residue), 'v110 must not retain acceptance residue: ' . $residue);
}

$assert(
    !str_contains($v110, "\$imports[\$accountShortcutsImportKey] .=")
        && !str_contains($v110, "\$imports[\$chessRendererImportKey] .=")
        && !str_contains($v110, "\$imports[\$goRendererImportKey] .=")
        && !str_contains($v110, "\$imports[\$dominoRendererImportKey] .=")
        && !str_contains($v110, "\$imports[\$battleshipRendererImportKey] .=")
        && !str_contains($v110, "\$assets['main_css'] .="),
    'v110 must render canonical manifest owners without mutating their accepted identities.'
);

$assert(
    str_contains($launch, "private const ENTRY_PATH = '/app/v110.php"),
    'Telegram launch owner must remain canonical v110.'
);

fwrite(STDOUT, "Mvp25_1V110ManifestNormalizationContractTest: {$assertions} assertions passed\n");
