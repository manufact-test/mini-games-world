<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$main = file_get_contents($root . '/app/assets/js/main.js');
$background = file_get_contents($root . '/app/assets/js/screens/notifications-screen-v99.js');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v119.js');
$prewarm = file_get_contents($root . '/app/assets/js/first-interaction-readiness-v103.js');
$entry = file_get_contents($root . '/app/v114.php');
if (!is_string($main) || !is_string($background) || !is_string($owner)
    || !is_string($prewarm) || !is_string($entry)) {
    throw new RuntimeException('Missing notification ownership source under v122 shell.');
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
$assert(str_contains($entry, '"./assets/js/screens/notifications-screen-v99.js?v=99": "./assets/js/screens/notifications-screen-v99.js?v=114"')
        && str_contains($entry, '"./assets/js/first-interaction-readiness-v103.js?v=103": "./assets/js/first-interaction-readiness-v103.js?v=114"'),
    'The isolated Bug B shell must retain reviewed notification and prewarm module objects.');
$assert(substr_count($entry, 'notification-window-owner-v119.js?v=119') === 1
        && !str_contains($entry, 'notification-empty-frame-guard-v115.js?v=115')
        && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116')
        && !str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117'),
    'The active shell must preserve one canonical v119 notification UI owner.');
$assert(str_contains($owner, "window.addEventListener('click'")
        && str_contains($owner, "target?.closest('#notificationsOpen, #notificationToast')")
        && str_contains($owner, 'event.stopImmediatePropagation();')
        && str_contains($owner, 'openNotificationsSheet();'),
    'The isolated player-picker change must not alter accepted notification gesture ownership.');
$assert(substr_count($owner, 'api.notifications(true)') >= 2
        && str_contains($owner, 'data-invite-action=')
        && str_contains($owner, 'data-invite-token='),
    'The canonical owner must still fetch confirmed notification lists and actions.');
$assert(str_contains($background, 'refreshNotificationBadge(false);')
        && str_contains($background, 'notificationPoll = window.setInterval')
        && str_contains($background, 'setUnreadCount('),
    'Background badge polling must remain intact.');
$assert(str_contains($prewarm, 'warmNotificationsSnapshot()')
        && str_contains($prewarm, 'return api.notifications(false);')
        && !str_contains($prewarm, "target.id === 'notificationsOpen'")
        && !str_contains($prewarm, 'renderNotificationsSheet'),
    'Prewarm may read notifications but cannot own the interface.');
$assert(str_contains($entry, 'data-hotfix-build="v122-mvp14-opponents-authoritative-source"')
        && str_contains($entry, 'X-MGW-Frontend-Build: v122-mvp14-opponents-authoritative-source')
        && substr_count($entry, 'opponents-authoritative-confirm-v122.js?v=122') === 1,
    'The shell identity may advance to v122 only for the isolated player-picker source change.');
$assert(substr_count($main, 'initNotificationsScreen();') === 1
        && substr_count($main, 'initFirstInteractionReadinessEarly();') === 1,
    'Each notification background/prewarm initializer must run exactly once.');
$assert(!str_contains($entry, 'lemonchiffon-gerbil-545102.hostingersite.com')
        && !str_contains($entry, 'mini-games-world.com'),
    'The player-picker change must not introduce a production target.');

fwrite(STDOUT, "ProductionMvp14R13NotificationSingleOwnerTest: {$assertions} assertions passed\n");
