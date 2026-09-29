<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$entry = $read('app/v110.php');
$manifest = $read('app/runtime/client/version-manifest.php');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$profile = $read('app/assets/js/screens/profile-screen-v110.js');
$api = $read('app/assets/js/api/client.js');
$baseline = $read('e2e/staging/mvp25-4-performance-baseline.spec.mjs');

$assert(str_contains($launch, "private const ENTRY_PATH = '/app/v110.php"), 'MVP-25.4 must preserve the real Telegram v110 entry.');
$assert(str_contains($entry, 'version-manifest.php'), 'v110 must keep the canonical client manifest.');
$assert(
    str_contains($manifest, 'main-v110-handoff-shell.js?v=1160')
        && str_contains($manifest, 'mvp25_4=profile-preloader-unblock-v1'),
    'Manifest must publish the MVP-25.4 startup owner.'
);

$start = strpos($shell, 'async function primeMobileProfileFirstPresentation(){');
$end = $start === false ? false : strpos($shell, 'function loadStoreScreenModule(){', $start);
$assert($start !== false && $end !== false && $end > $start, 'Profile first-presentation owner must remain explicit.');
$prime = substr($shell, (int)$start, (int)$end - (int)$start);

$assert(!str_contains($prime, 'api.profileV2('), 'Covered Profile raster must not wait on or start profileV2 network work.');
$assert(str_contains($prime, "document.getElementById('preloader')"), 'Covered Profile raster must remain guarded by the startup preloader.');
$assert(substr_count($prime, 'requestAnimationFrame') >= 2, 'Covered Profile raster must preserve the accepted two-frame warm pass.');

$assert(str_contains($shell, 'initProfileScreen();'), 'Boot must continue to initialize the canonical Profile owner.');
$assert(
    str_contains($shell, 'globalThis.__MGW_MOBILE_COLD_SURFACES_READY__ = primeMobileColdFirstInteractions();'),
    'Store/account mobile cold-surface warm must remain nonblocking.'
);

$assert(
    str_contains($profile, 'globalThis.setTimeout(warm, 0);')
        && str_contains($profile, 'void api.profileV2()'),
    'Profile owner must continue warming profileV2 asynchronously after initialization.'
);
$assert(
    str_contains($api, 'if (profileV2ReadPromise) return profileV2ReadPromise;')
        && str_contains($api, 'profileV2ReadPromise = requestUrl(PROFILE_V2_URL)'),
    'API client must preserve single-flight profileV2 reads.'
);
$assert(str_contains($baseline, '[MGW25_4_BASELINE]'), 'MVP-25.4 before/after staging measurement must remain wired.');

fwrite(STDOUT, "Mvp25_4StartupPerformanceContractTest: {$assertions} assertions passed\n");
