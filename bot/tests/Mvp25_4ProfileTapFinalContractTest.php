<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Missing Profile tap source: ' . $path);
    return $content;
};

$index = $read('app/index.html');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$router = $read('app/assets/js/router.js');
$entry = $read('app/assets/js/profile/mgw-profile-entry-effects.js');
$victory = $read('app/assets/js/profile/mgw-profile-victory-effects-v4.js');
$victoryWrapper = $read('app/assets/js/profile/mgw-profile-victory-effects-card-parity.js');
$manifest = $read('app/runtime/client/version-manifest.php');
$playwright = $read('e2e/playwright.config.mjs');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($index, 'class="user-mini" id="profileOpen"')
        && str_contains($shell, "profileTrigger.dataset.shellNav = 'profile';"),
    'Top Profile identity must remain a shell navigation target.'
);

$assert(
    str_contains($shell, "if (!(target instanceof HTMLElement)) return;")
        && str_contains($shell, "if (target instanceof HTMLButtonElement && target.disabled) return;")
        && !str_contains($shell, "if (!(target instanceof HTMLButtonElement)) return;"),
    'Shell navigation must accept the top Profile div while preserving disabled-button handling.'
);

$assert(
    str_contains($router, "if (previous !== next) dispatchScreenChanged({ from:previous, to:next });")
        && !str_contains($router, 'deferProfileTransition')
        && !str_contains($router, 'deferredProfilePaintRaf'),
    'Canonical router must stay synchronous; the old global deferred Profile fan-out must not return.'
);

foreach ([
    'Entry Effects' => $entry,
    'Victory Effects' => $victory,
] as $owner => $source) {
    $assert(
        str_contains($source, "if (next === 'profile') scheduleProfileRouteWorkAfterPaint();"),
        $owner . ' must defer Profile-only route decoration until after first paint.'
    );
    $assert(
        str_contains($source, 'function scheduleProfileRouteWorkAfterPaint(){')
            && str_contains($source, "window.requestAnimationFrame(() => window.requestAnimationFrame(run));")
            && str_contains($source, "if (active !== 'profile') return;"),
        $owner . ' post-paint owner must be bounded to the still-active Profile route.'
    );
    $assert(
        str_contains($source, "else if (next === 'store') {")
            && str_contains($source, "void ensureSnapshot();"),
        $owner . ' must preserve Store hydration behavior.'
    );
}

$assert(
    str_contains($victoryWrapper, "mgw-profile-victory-effects-v4.js?v=2")
        && str_contains($victoryWrapper, "mvp25_4=profile-post-paint-v1"),
    'Victory Effects wrapper must publish the fresh post-paint base owner.'
);

$assert(
    str_contains($manifest, "mgw-profile-entry-effects.js?v=7&mvp19_3=player-arbitration&mvp25_4=profile-post-paint-v1")
        && str_contains($manifest, "mgw-profile-victory-effects-card-parity.js?v=12")
        && str_contains($manifest, "mvp25_4=profile-post-paint-v1")
        && str_contains($manifest, "main-v110-handoff-shell.js?v=1161")
        && str_contains($manifest, "profile_topbar=direct-shell-v3")
        && str_contains($manifest, "profile_tap=post-paint-v1"),
    'Manifest must cache-bust all final Profile tap owners.'
);

$assert(
    str_contains($playwright, "'mvp25-4-profile-route-latency.spec.mjs'"),
    'The staging Profile route phase probe must remain in canonical Playwright.'
);

fwrite(STDOUT, "MVP-25.4 Profile tap final contract OK ({$assertions} assertions).\n");
