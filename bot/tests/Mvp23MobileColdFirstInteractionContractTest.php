<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Missing mobile-cold source: ' . $path);
    return $content;
};

$account = $read('app/assets/js/screens/account-data-sheet-v1.js');
$shortcuts = $read('app/assets/js/components/account-shortcuts.js');
$main = $read('app/assets/js/main-v110-handoff-shell.js');
$preloader = $read('app/assets/js/components/preloader.js');
$manifest = $read('app/runtime/client/version-manifest.php');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$primePos = strpos($account, 'await primeAccountDataFirstOpen();');
$openPos = strpos($account, 'openSheet(shellHtml());');
$assert(
    $primePos !== false && $openPos !== false && $primePos < $openPos,
    'Account Data must await first style/snapshot readiness before exposing the sheet.'
);

$assert(
    str_contains($account, 'let snapshotPromise = null;')
        && str_contains($account, 'loadSnapshot(false)')
        && str_contains($account, 'ensureStylesReady()')
        && str_contains($account, 'SNAPSHOT_TTL_MS = 15000'),
    'Account Data first-open readiness must coalesce snapshot and stylesheet work.'
);

$shortcutStart = strpos($shortcuts, 'async function openAccountDataShortcut()');
$shortcutEnd = strpos($shortcuts, 'function loadAccountDataModule()', $shortcutStart === false ? 0 : $shortcutStart);
$shortcutBlock = ($shortcutStart !== false && $shortcutEnd !== false)
    ? substr($shortcuts, $shortcutStart, $shortcutEnd - $shortcutStart)
    : '';
$assert(
    $shortcutBlock !== ''
        && !str_contains($shortcutBlock, 'closeSheet();')
        && str_contains($shortcutBlock, 'await module.openAccountDataSheet();'),
    'Account Data shortcut must atomically replace the existing More sheet after readiness, without an empty close frame.'
);

$assert(
    str_contains($shortcuts, "account-data-sheet-v1.js?v=5")
        && str_contains($shortcuts, 'export async function primeAccountDataShortcut()'),
    'Account Data corrected module must be cache-busted and expose a shared prime owner.'
);

$assert(
    str_contains($main, 'globalThis.__MGW_MOBILE_COLD_SURFACES_READY__ = primeMobileColdFirstInteractions();')
        && str_contains($main, 'await module.initStoreScreen();')
        && str_contains($main, 'await waitForPendingStylesheets(900);')
        && str_contains($main, 'await primeMobileStoreFirstPresentation();'),
    'Mobile shell boot must fully prime Store code/data/styles/raster under the intro.'
);

$storeRoute = strpos($main, "if (route === 'store' && isMobileShellClient())");
$storeOpen = strpos($main, 'void openStoreTabLazy()', $storeRoute === false ? 0 : $storeRoute);
$storeShow = strpos($main, "showScreen('store');", $storeOpen === false ? 0 : $storeOpen);
$assert(
    $storeRoute !== false && $storeOpen !== false && $storeShow !== false && $storeOpen < $storeShow,
    'Mobile Store route must not become visible until the complete Store surface is ready.'
);

$assert(
    str_contains($main, 'let shellNavigationGeneration = 0;')
        && str_contains($main, 'const navigationGeneration = ++shellNavigationGeneration;')
        && str_contains($main, 'if (navigationGeneration !== shellNavigationGeneration) return;'),
    'A later Home/Profile/Tournament navigation must cancel a stale cold Store reveal.'
);

$assert(
    str_contains($preloader, '__MGW_MOBILE_COLD_SURFACES_READY__')
        && str_contains($preloader, 'MOBILE_COLD_SURFACES_HOLD_MAX_MS = 1500')
        && str_contains($preloader, 'mobileColdSurfacesReady'),
    'Intro must own a bounded wait for mobile cold-surface preparation.'
);

$assert(
    str_contains($manifest, "account-shortcuts.js?v=56")
        && str_contains($manifest, "main-v110-handoff-shell.js?v=1158")
        && str_contains($manifest, "preloader.js?v=46")
        && str_contains($manifest, 'mobile-cold-first-open-v1'),
    'Active client mappings must carry fresh revisions for the corrective.'
);

fwrite(STDOUT, "MVP-23 mobile cold first-interaction contract OK ({$assertions} assertions).\n");
