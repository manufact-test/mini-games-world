<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Missing Profile latency source: ' . $path);
    return $content;
};

$entry = $read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
$wrapper = $read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
$guard = $read('app/assets/js/profile/mgw-mobile-profile-animation-guard-v2.js');
$main = $read('app/assets/js/main-v110-handoff-shell.js');
$css = $read('app/assets/css/production-v99-profile-reaction-polish.css');
$stagingEntry = $read('app/v110.php');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    !str_contains($entry, 'MGW_PROFILE_ROUTE_SETTLE_MS')
        && !str_contains($entry, 'mgwProfileRouteSettleTimer')
        && !str_contains($entry, "classList.add('mgw-profile-route-settling')")
        && !str_contains($entry, 'handleMgwProfileRouteIntent'),
    'Profile navigation must not arm the former timed 360 ms route-settle guard.'
);

$assert(
    str_contains($entry, "screen.classList.contains('mgw-profile-prewarm-pass')")
        && str_contains($entry, "screen.classList.add('active')")
        && str_contains($entry, "profileNav.classList.add('active')"),
    'Covered exact-active Profile warmup must stay intact.'
);

$assert(
    !str_contains($guard, 'PROFILE_ROUTE_SETTLING_CLASS')
        && !str_contains($guard, 'routeClassObserver')
        && !str_contains($guard, 'handleRouteClassMutation'),
    'Profile animation lifecycle must not depend on a synthetic timed route class.'
);

$assert(
    str_contains($guard, "if (currentShellRoute() === 'profile') return;")
        && str_contains($guard, 'resumeAfterTwoPaints();'),
    'Hidden Profile animations must stay paused off-route and resume after real Profile paint.'
);

$assert(
    str_contains($wrapper, 'final-polish.js?v=1139')
        && str_contains($wrapper, 'profile_route_guard=instant-no-settle-v1')
        && str_contains($wrapper, "import './profile/mgw-mobile-profile-animation-guard-v2.js?v=1';"),
    'Clean-entry wrapper must publish the fresh final-polish owner while retaining the canonical animation-guard specifier.'
);

$assert(
    str_contains($main, "profileTrigger.dataset.shellNav = 'profile';")
        && str_contains($main, "['profile', 'nav.profile']")
        && str_contains($main, 'showScreen(route);'),
    'Top identity and bottom Profile tab must retain one synchronous shell route owner.'
);

$assert(
    str_contains($css, '#app #screen-profile.screen.active')
        && str_contains($css, 'transition: none !important;')
        && str_contains($css, '#app #screen-profile.screen.active > .content'),
    'Profile screen/content presentation must remain atomic rather than reintroducing route motion.'
);

$assert(
    str_contains($stagingEntry, "\$cleanEntryImportKey = '@mgw/clean-entry';")
        && str_contains($stagingEntry, "\$imports[\$cleanEntryImportKey] .= '&mvp23_profile=instant-route-v1';")
        && str_contains($stagingEntry, "\$profileAnimationGuardImportKey = './assets/js/profile/mgw-mobile-profile-animation-guard-v2.js?v=1';")
        && str_contains($stagingEntry, "\$imports[\$profileAnimationGuardImportKey] .= '&mvp23_profile=instant-resume-v1';"),
    'Staging must cache-bust both canonical Profile route owners so Telegram WebView cannot reuse the old settle guard.'
);

fwrite(STDOUT, "MVP-23 Profile route latency contract OK ({$assertions} assertions).\n");
