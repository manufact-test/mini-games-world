<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$notifications = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v121.js');
$background = file_get_contents($root . '/app/assets/js/screens/notifications-passive-v121.js');
$opponents = file_get_contents($root . '/app/assets/js/opponents-authoritative-confirm-v122.js');
$opponentEndpoint = file_get_contents($root . '/bot/invite-opponents.php');
$endpoint = file_get_contents($root . '/bot/notifications.php');
if (!is_string($entry) || !is_string($notifications) || !is_string($background)
    || !is_string($opponents) || !is_string($opponentEndpoint) || !is_string($endpoint)) throw new RuntimeException('Missing integrated D1 v125 sources.');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void { $assertions++; if (!$condition) throw new RuntimeException($message); };
$assert(substr_count($entry, 'notification-window-owner-v121.js?v=121') === 1
    && str_contains($entry, 'notifications-passive-v121.js?v=121')
    && !str_contains($entry, 'notification-window-owner-v119.js?v=119'),
    'The Bug B follow-up must retain one v121 notification owner and passive service.');
$assert(substr_count($entry, 'opponents-authoritative-confirm-v122.js?v=122') === 1
    && !str_contains($entry, 'opponents-authoritative-confirm-v117.js?v=117'),
    'The shell must publish v122 opponent confirmation once.');
$assert(!str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
    && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117')
    && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116'),
    'Retired notification owners must remain absent.');
$assert(str_contains($notifications, "document.addEventListener('mgw:notification-remove'")
    && str_contains($notifications, "String(item?.invite_token || '') !== inviteToken")
    && str_contains($notifications, 'generation += 1;'),
    'Terminal actions must clear stale actionable cache.');
$assert(!str_contains($notifications, 'openingSheet')
    && str_contains($notifications, 'const requestGeneration = ++generation;')
    && str_contains($notifications, 'requestGeneration === generation')
    && str_contains($notifications, "window.addEventListener('pointerup'"),
    'Notification input and responses must share generation protection.');
$assert(!str_contains($background, 'openNotificationsSheet(')
    && str_contains($background, 'refreshNotificationBadge(false)')
    && str_contains($background, 'showNotificationToast(item)'),
    'The background service must remain passive.');
$assert(str_contains($opponents, 'RETRY_DELAYS_MS = [150, 250, 400, 600, 850, 1100]')
    && str_contains($opponents, 'REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES = 3')
    && str_contains($opponents, 'MIN_EMPTY_CONFIRMATION_MS = 3200')
    && str_contains($opponents, "payload?.storage_driver === 'json'")
    && str_contains($opponents, 'Number(payload?.unresolved_online_count || 0) === 0')
    && !str_contains($opponents, 'openSheet('),
    'Opponent confirmation must stay bounded, JSON-catalog complete and transport-only.');
$assert(str_contains($opponentEndpoint, 'StorageFactory::createJson(')
    && str_contains($opponentEndpoint, '$onlineOpponentIds')
    && str_contains($opponentEndpoint, '$unresolvedOnlineCount')
    && !str_contains($opponentEndpoint, 'DatabasePrimaryStateStorageAdapter'),
    'The endpoint must reconcile live presence against canonical JSON profiles.');
$assert(str_contains($endpoint, "['pending', 'accepted', 'declined']")
    && str_contains($endpoint, "\$item['title'] = 'Приглашение отклонено';")
    && str_contains($endpoint, "\$item['read'] = true;"),
    'Declined invitation read history must remain intact.');
fwrite(STDOUT, "ProductionMvp14D1FollowupIntegrationV117Test: {$assertions} assertions passed\n");
