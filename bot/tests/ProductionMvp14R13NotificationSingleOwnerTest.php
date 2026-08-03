<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$main = file_get_contents($root . '/app/assets/js/main.js');
$canonical = file_get_contents($root . '/app/assets/js/screens/notifications-screen.js');
$prewarm = file_get_contents($root . '/app/assets/js/first-interaction-readiness.js');
$index = file_get_contents($root . '/app/index.html');
$v110 = file_get_contents($root . '/app/v110.php');
if (!is_string($main) || !is_string($canonical) || !is_string($prewarm)
    || !is_string($index) || !is_string($v110)) {
    throw new RuntimeException('Missing notification ownership source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$canonicalInit = strpos($main, 'initNotificationsScreen();');
$prewarmInit = strpos($main, 'initFirstInteractionReadinessEarly();');
$assert($canonicalInit !== false && $prewarmInit !== false && $canonicalInit < $prewarmInit,
    'The canonical notifications screen must initialize before generic first-interaction warming.');

$assert(str_contains($main, "./screens/notifications-screen.js?v=85")
    && str_contains($main, "./first-interaction-readiness.js?v=98")
    && str_contains($main, "window.__MGW_BUILD__ = 'v96-mvp14-root-cause-stabilization'")
    && str_contains($main, "window.__MGW_HOTFIX_BUILD__ = 'v98-mvp14-notification-canonical-owner'"),
    'The canonical-owner release must retain reviewed lineage and publish the v98 module graph.');

$assert(str_contains($canonical, "const trigger = event.target.closest('#notificationsOpen')")
    && str_contains($canonical, 'event.stopImmediatePropagation();')
    && str_contains($canonical, 'openNotificationsSheet();'),
    'The canonical screen must exclusively own the notifications click.');

$assert(str_contains($canonical, 'const result = await api.notifications(true);')
    && str_contains($canonical, 'renderNotifications(items);')
    && str_contains($canonical, 'data-invite-action=')
    && str_contains($canonical, 'data-invite-token='),
    'The canonical owner must fetch the fresh marked-read list and render invitation actions.');

$assert(str_contains($prewarm, 'warmNotificationsSnapshot()')
    && str_contains($prewarm, 'return api.notifications(false);'),
    'First-interaction readiness may warm notifications only through a read-only request.');

$assert(!str_contains($prewarm, "target.id === 'notificationsOpen'")
    && !str_contains($prewarm, 'renderNotificationsSheet')
    && !str_contains($prewarm, 'refreshNotificationsSnapshot')
    && !str_contains($prewarm, 'data-invite-action=')
    && !str_contains($prewarm, 'data-invite-token=')
    && !str_contains($prewarm, 'setUnreadCount(')
    && !str_contains($prewarm, 'api.notifications(Boolean(markRead))'),
    'Prewarm must not intercept, mark read, render or mutate the notifications interface.');

$regressionEntry = strpos($index, 'production-regression-fix-entry.js?v=96');
$activeMain = strpos($index, 'main.js?v=98');
$assert($regressionEntry !== false
    && $activeMain !== false
    && $regressionEntry < $activeMain
    && !str_contains($index, 'main.js?v=97')
    && !str_contains($index, 'main.js?v=96')
    && str_contains($index, 'data-hotfix-build="v98-mvp14-notification-canonical-owner"'),
    'The HTML shell must publish the v98 active entry after the retained v96 regression layer.');

$assert(str_contains($v110, "'./assets/js/main.js?v=98'")
    && str_contains($v110, "'data-hotfix-build=\"v98-mvp14-notification-canonical-owner\"'"),
    'The v110 wrapper must transform the current v98 source shell.');

$assert(substr_count($main, 'initNotificationsScreen();') === 1
    && substr_count($main, 'initFirstInteractionReadinessEarly();') === 1,
    'Each canonical/prewarm initializer must run exactly once.');

$assert(!str_contains($main, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($main, 'mini-games-world.com'),
    'The UI ownership fix must not introduce a production target.');

fwrite(STDOUT, "ProductionMvp14R13NotificationSingleOwnerTest: {$assertions} assertions passed\n");
