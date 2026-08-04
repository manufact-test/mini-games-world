<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$notifications = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v121.js');
$background = file_get_contents($root . '/app/assets/js/screens/notifications-passive-v121.js');
$opponents = file_get_contents($root . '/app/assets/js/opponents-authoritative-confirm-v122.js');
$endpoint = file_get_contents($root . '/bot/notifications.php');
if (!is_string($entry) || !is_string($notifications) || !is_string($background)
    || !is_string($opponents) || !is_string($endpoint)) throw new RuntimeException('Missing integrated D1 v123 sources.');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void { $assertions++; if (!$condition) throw new RuntimeException($message); };
$assert(substr_count($entry, 'notification-window-owner-v121.js?v=121') === 1
    && str_contains($entry, 'notifications-passive-v121.js?v=121')
    && !str_contains($entry, 'notification-window-owner-v119.js?v=119'), 'v123 must publish one v121 owner and passive service.');
$assert(substr_count($entry, 'opponents-authoritative-confirm-v122.js?v=122') === 1
    && !str_contains($entry, 'opponents-authoritative-confirm-v117.js?v=117'), 'v123 must publish v122 opponent confirmation once.');
$assert(!str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
    && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117')
    && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116'), 'Retired notification owners must remain absent.');
$assert(str_contains($notifications, "document.addEventListener('mgw:notification-remove'")
    && str_contains($notifications, "String(item?.invite_token || '') !== inviteToken")
    && str_contains($notifications, 'generation += 1;'), 'Terminal actions must clear stale actionable cache.');
$assert(!str_contains($notifications, 'openingSheet')
    && str_contains($notifications, 'const requestGeneration = ++generation;')
    && str_contains($notifications, 'requestGeneration === generation')
    && str_contains($notifications, "window.addEventListener('pointerup'"), 'Notification input and responses must share generation protection.');
$assert(!str_contains($background, 'openNotificationsSheet(')
    && str_contains($background, 'refreshNotificationBadge(false)')
    && str_contains($background, 'showNotificationToast(item)'), 'The background service must remain passive.');
$assert(str_contains($opponents, 'RETRY_DELAYS_MS = [80, 140, 240, 400, 650, 950]')
    && str_contains($opponents, 'REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES = 2')
    && str_contains($opponents, "payload?.storage_driver === 'database'")
    && !str_contains($opponents, 'openSheet('), 'Opponent confirmation must stay bounded, DB-primary and transport-only.');
$assert(str_contains($endpoint, "['pending', 'accepted', 'declined']")
    && str_contains($endpoint, "\$item['title'] = 'Приглашение отклонено';")
    && str_contains($endpoint, "\$item['read'] = true;"), 'Declined invitation read history must remain intact.');
$assert(str_contains($entry, 'data-hotfix-build="v123-mvp14-d1-two-manual-regressions"')
    && str_contains($entry, 'X-MGW-Frontend-Build: v123-mvp14-d1-two-manual-regressions'), 'The no-cache entry must expose v123.');
fwrite(STDOUT, "ProductionMvp14D1FollowupIntegrationV117Test: {$assertions} assertions passed\n");
