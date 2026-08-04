<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$main = file_get_contents($root . '/app/assets/js/main.js');
$background = file_get_contents($root . '/app/assets/js/screens/notifications-passive-v121.js');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v121.js');
$prewarm = file_get_contents($root . '/app/assets/js/first-interaction-readiness-v103.js');
$index = file_get_contents($root . '/app/index.html');
$entry = file_get_contents($root . '/app/v114.php');
$v110 = file_get_contents($root . '/app/v110.php');
if (!is_string($main) || !is_string($background) || !is_string($owner) || !is_string($prewarm)
    || !is_string($index) || !is_string($entry) || !is_string($v110)) {
    throw new RuntimeException('Missing v121 notification ownership source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$backgroundInit = strpos($main, 'initNotificationsScreen();');
$prewarmInit = strpos($main, 'initFirstInteractionReadinessEarly();');
$assert($backgroundInit !== false && $prewarmInit !== false && $backgroundInit < $prewarmInit,
    'The background notification service must initialize before generic warming.');
$assert(str_contains($main, "./screens/notifications-screen-v99.js?v=99")
        && str_contains($main, "./first-interaction-readiness-v103.js?v=103")
        && str_contains($main, "./interaction-latency-coordinator-v101.js?v=101")
        && str_contains($main, "window.__MGW_HOTFIX_BUILD__ = 'v115-mvp14-d1-feedback-integration'")
        && str_contains($entry, '"./assets/js/screens/notifications-screen-v99.js?v=99": "./assets/js/screens/notifications-passive-v121.js?v=121"')
        && str_contains($entry, '"./assets/js/first-interaction-readiness-v103.js?v=103": "./assets/js/first-interaction-readiness-v103.js?v=114"'),
    'The v121 shell must retain reviewed main specifiers while routing notifications to one passive module object.');
$assert(substr_count($entry, 'notification-window-owner-v121.js?v=121') === 1
        && !str_contains($entry, 'notification-window-owner-v119.js?v=119')
        && !str_contains($entry, 'notification-empty-frame-guard-v115.js?v=115')
        && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116')
        && !str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117'),
    'The active shell must publish one canonical v121 UI owner and no competing owner.');
$assert(str_contains($owner, "window.addEventListener('pointerdown'")
        && str_contains($owner, "window.addEventListener('pointerup'")
        && str_contains($owner, "window.addEventListener('click'")
        && str_contains($owner, 'notificationTrigger(event.target)')
        && str_contains($owner, 'event.stopImmediatePropagation();')
        && str_contains($owner, 'openFromUserInput();'),
    'The v121 owner must exclusively own real pointer, click fallback and keyboard surfaces.');
$assert(substr_count($owner, 'api.notifications(true)') >= 2
        && str_contains($owner, 'renderNotifications(items);')
        && str_contains($owner, 'data-invite-action=')
        && str_contains($owner, 'data-invite-token='),
    'The canonical owner must fetch confirmed lists and render invitation actions.');
$assert(str_contains($background, 'refreshNotificationBadge(false)')
        && str_contains($background, 'notificationPoll = window.setInterval')
        && str_contains($background, 'setUnreadCount(')
        && str_contains($background, 'showNotificationToast(item)')
        && !str_contains($background, 'openNotificationsSheet('),
    'The passive module may poll badges and show toast content but cannot open the sheet.');
$assert(str_contains($prewarm, 'warmNotificationsSnapshot()')
        && str_contains($prewarm, 'return api.notifications(false);'),
    'First-interaction readiness may warm notifications only through a read-only request.');
$assert(!str_contains($prewarm, "target.id === 'notificationsOpen'")
        && !str_contains($prewarm, 'renderNotificationsSheet')
        && !str_contains($prewarm, 'data-invite-action=')
        && !str_contains($prewarm, 'setUnreadCount('),
    'Prewarm must not intercept or render the notification interface.');
$retainedScript = '<script type="module" src="./assets/js/production-regression-fix-entry.js?v=102"></script>';
$sourceMain = '<script type="module" src="./assets/js/main.js?v=98.3"></script>';
$assert(strpos($index, $retainedScript) !== false
        && strpos($index, $sourceMain) !== false
        && strpos($index, $retainedScript) < strpos($index, $sourceMain)
        && str_contains($entry, "'./assets/js/main.js?v=98.3'")
        && str_contains($entry, "'./assets/js/main.js?v=115'")
        && str_contains($entry, 'data-hotfix-build="v121-mvp14-notification-short-input-owner"')
        && str_contains($entry, 'X-MGW-Frontend-Build: v121-mvp14-notification-short-input-owner'),
    'The source shell must retain its anchor while staging publishes the v121 wrapper after the regression script.');
$assert(str_contains($v110, "'./assets/js/main.js?v=98.3'")
        && str_contains($v110, "'./assets/js/main-v110.js?v=1124'")
        && str_contains($v110, "'data-hotfix-build=\"v98-mvp14-notification-canonical-owner\"'"),
    'The historical v110 wrapper must remain unchanged and separate.');
$assert(substr_count($main, 'initNotificationsScreen();') === 1
        && substr_count($main, 'initFirstInteractionReadinessEarly();') === 1,
    'Each passive background/prewarm initializer must run exactly once.');
$assert(!str_contains($entry, 'lemonchiffon-gerbil-545102.hostingersite.com')
        && !str_contains($entry, 'mini-games-world.com')
        && !str_contains($main, 'mini-games-world.com'),
    'The UI ownership fix must not introduce a production target.');

fwrite(STDOUT, "ProductionMvp14R13NotificationSingleOwnerTest: {$assertions} assertions passed\n");
