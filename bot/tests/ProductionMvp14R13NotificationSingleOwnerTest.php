<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$main = file_get_contents($root . '/app/assets/js/main.js');
$background = file_get_contents($root . '/app/assets/js/screens/notifications-passive-v121.js');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v121.js');
$prewarm = file_get_contents($root . '/app/assets/js/first-interaction-readiness-v103.js');
$entry = file_get_contents($root . '/app/v114.php');
if (!is_string($main) || !is_string($background) || !is_string($owner) || !is_string($prewarm) || !is_string($entry)) throw new RuntimeException('Missing v123 notification ownership source.');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void { $assertions++; if (!$condition) throw new RuntimeException($message); };
$backgroundInit = strpos($main, 'initNotificationsScreen();');
$prewarmInit = strpos($main, 'initFirstInteractionReadinessEarly();');
$assert($backgroundInit !== false && $prewarmInit !== false && $backgroundInit < $prewarmInit, 'The passive notification service must initialize before generic warming.');
$assert(str_contains($entry, '"./assets/js/screens/notifications-screen-v99.js?v=99": "./assets/js/screens/notifications-passive-v121.js?v=121"')
    && str_contains($entry, '"./assets/js/first-interaction-readiness-v103.js?v=103": "./assets/js/first-interaction-readiness-v103.js?v=114"'), 'The v123 map must route notifications to passive v121 and retain prewarm.');
$assert(substr_count($entry, 'notification-window-owner-v121.js?v=121') === 1
    && !str_contains($entry, 'notification-window-owner-v119.js?v=119')
    && !str_contains($entry, 'notification-empty-frame-guard-v115.js?v=115')
    && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116')
    && !str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
    && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117'), 'The active shell must publish one v121 owner and no competitors.');
$assert(str_contains($owner, "window.addEventListener('pointerdown'")
    && str_contains($owner, "window.addEventListener('pointerup'")
    && str_contains($owner, "window.addEventListener('click'")
    && str_contains($owner, 'notificationTrigger(event.target)')
    && str_contains($owner, 'event.stopImmediatePropagation();')
    && str_contains($owner, 'openFromUserInput();'), 'v121 must own real pointer, fallback click and keyboard surfaces.');
$assert(substr_count($owner, 'api.notifications(true)') >= 2
    && str_contains($owner, 'renderNotifications(items);')
    && str_contains($owner, 'data-invite-action=')
    && str_contains($owner, 'data-invite-token='), 'The owner must fetch confirmed lists and render actions.');
$assert(str_contains($background, 'refreshNotificationBadge(false)')
    && str_contains($background, 'notificationPoll = window.setInterval')
    && str_contains($background, 'setUnreadCount(')
    && str_contains($background, 'showNotificationToast(item)')
    && !str_contains($background, 'openNotificationsSheet('), 'The passive module may poll and show toast content but cannot open the sheet.');
$assert(str_contains($prewarm, 'warmNotificationsSnapshot()')
    && str_contains($prewarm, 'return api.notifications(false);')
    && !str_contains($prewarm, "target.id === 'notificationsOpen'")
    && !str_contains($prewarm, 'renderNotificationsSheet'), 'Prewarm may read notifications but cannot own the interface.');
$assert(str_contains($entry, 'data-hotfix-build="v123-mvp14-d1-two-manual-regressions"')
    && str_contains($entry, 'X-MGW-Frontend-Build: v123-mvp14-d1-two-manual-regressions'), 'The shell must expose v123.');
$assert(substr_count($main, 'initNotificationsScreen();') === 1 && substr_count($main, 'initFirstInteractionReadinessEarly();') === 1, 'Each background/prewarm initializer must run once.');
$assert(!str_contains($entry, 'mini-games-world.com'), 'The integration must not introduce a production target.');
fwrite(STDOUT, "ProductionMvp14R13NotificationSingleOwnerTest: {$assertions} assertions passed\n");
