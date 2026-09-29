<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Missing MVP-25.4 Profile compositor source: ' . $path);
    return $content;
};

$backgroundCss = $read('app/assets/css/components/mgw-profile-backgrounds.css');
$mainCss = $read('app/assets/css/main.css');
$guard = $read('app/assets/js/profile/mgw-mobile-profile-animation-guard-v2.js');
$manifest = $read('app/runtime/client/version-manifest.php');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$probe = $read('e2e/staging/mvp25-4-profile-route-latency.spec.mjs');
$playwright = $read('e2e/playwright.config.mjs');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($backgroundCss, 'backdrop-filter:blur(14px) saturate(1.08)')
        && str_contains($backgroundCss, '-webkit-backdrop-filter:blur(14px) saturate(1.08)'),
    'Desktop/full Profile glass appearance must remain available.'
);

$assert(
    str_contains($backgroundCss, '@media (max-width:640px), (pointer:coarse)')
        && str_contains($backgroundCss, '#screen-profile[data-profile-background-item-id] :is(')
        && str_contains($backgroundCss, 'backdrop-filter:none!important;')
        && str_contains($backgroundCss, '-webkit-backdrop-filter:none!important;'),
    'Phone Profile must keep the glass fill while dropping repeated per-card backdrop blur.'
);

$assert(
    str_contains($mainCss, "mgw-profile-backgrounds.css?v=2")
        && str_contains($mainCss, 'mvp25_4=mobile-compositor-v1'),
    'Main CSS must publish a fresh Profile background compositor asset identity.'
);

$assert(
    str_contains($guard, 'const RESUME_BATCH_SIZE = 4;')
        && str_contains($guard, 'let resumeBatchFrame = 0;')
        && str_contains($guard, 'resumeBatchFrame = window.requestAnimationFrame(resumeBatch);'),
    'Phone Profile animations must resume in bounded frame batches.'
);

$assert(
    str_contains($guard, 'pauseKnownAnimations();')
        && str_contains($guard, 'animation.play();')
        && str_contains($guard, 'pausedByGuard.delete(animation);'),
    'The existing pause/resume ownership and paid animation playback must remain intact.'
);

$assert(
    !str_contains($guard, 'MGW_PROFILE_ROUTE_SETTLE_MS')
        && !str_contains($guard, 'PROFILE_ROUTE_SETTLING_CLASS'),
    'The removed synthetic Profile route delay must not return.'
);

$assert(
    str_contains($manifest, "mgw-mobile-profile-animation-guard-v2.js?v=3")
        && str_contains($manifest, 'mvp25_4=batched-resume-v1')
        && str_contains($manifest, "main.css?v=210&mvp25_4=profile-mobile-compositor-v1"),
    'Runtime manifest must cache-bust both corrected mobile compositor owners.'
);

$assert(
    str_contains($shell, "profileTrigger.dataset.shellNav = 'profile';")
        && str_contains($shell, 'showScreen(route);'),
    'Top and bottom Profile entry points must retain the immediate synchronous shell route.'
);

$assert(
    str_contains($probe, "profile-pointerdown")
        && str_contains($probe, "profile-class-mutation")
        && str_contains($probe, "PerformanceObserver")
        && str_contains($playwright, "'mvp25-4-profile-route-latency.spec.mjs'"),
    'The exact mobile Profile route phase probe must remain in canonical staging E2E.'
);

fwrite(STDOUT, "MVP-25.4 mobile Profile compositor contract OK ({$assertions} assertions).\n");
