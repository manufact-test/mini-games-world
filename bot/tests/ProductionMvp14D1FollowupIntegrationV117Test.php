<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$notifications = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v121.js');
$background = file_get_contents($root . '/app/assets/js/screens/notifications-passive-v121.js');
$opponents = file_get_contents($root . '/app/assets/js/opponents-authoritative-confirm-v117.js');
$endpoint = file_get_contents($root . '/bot/notifications.php');
if (!is_string($entry) || !is_string($notifications) || !is_string($background)
    || !is_string($opponents) || !is_string($endpoint)) {
    throw new RuntimeException('Missing integrated D1 follow-up v121 sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($entry, 'notification-window-owner-v121.js?v=121') === 1
        && str_contains($entry, 'notifications-passive-v121.js?v=121')
        && !str_contains($entry, 'notification-window-owner-v119.js?v=119'),
    'Integrated entry must publish one v121 owner and one passive notification service.');
$assert(substr_count($entry, 'opponents-authoritative-confirm-v117.js?v=117') === 1,
    'The isolated Bug A branch must retain the previously reviewed opponent confirmation.');
$assert(!str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116'),
    'Responsive and retry notification owners must remain retired.');
$assert(str_contains($notifications, "document.addEventListener('mgw:notification-remove'")
        && str_contains($notifications, "String(item?.invite_token || '') !== inviteToken")
        && str_contains($notifications, 'generation += 1;'),
    'Terminal actions must clear stale actionable cards before history is reopened.');
$assert(!str_contains($notifications, 'openingSheet')
        && str_contains($notifications, 'const requestGeneration = ++generation;')
        && str_contains($notifications, 'requestGeneration === generation')
        && str_contains($notifications, "window.addEventListener('pointerup'"),
    'Mobile and desktop openings must share pointer ownership and generation guards.');
$assert(!str_contains($background, 'openNotificationsSheet(')
        && str_contains($background, 'refreshNotificationBadge(false)')
        && str_contains($background, 'showNotificationToast(item)'),
    'The background service must be passive while retaining badge/toast delivery.');
$assert(str_contains($opponents, 'RETRY_DELAYS_MS = [120, 260, 520]')
        && str_contains($opponents, "cache:'no-store'")
        && !str_contains($opponents, 'openSheet('),
    'Opponent confirmation must remain bounded and transport-only in the isolated Bug A branch.');
$assert(str_contains($endpoint, "['pending', 'accepted', 'declined']")
        && str_contains($endpoint, "\$item['title'] = 'Приглашение отклонено';")
        && str_contains($endpoint, "\$item['read'] = true;"),
    'The server must retain declined invitations as read history.');
$assert(str_contains($entry, 'data-hotfix-build="v121-mvp14-notification-short-input-owner"')
        && str_contains($entry, 'X-MGW-Frontend-Build: v121-mvp14-notification-short-input-owner'),
    'The no-cache entry must expose the v121 build identity.');

fwrite(STDOUT, "ProductionMvp14D1FollowupIntegrationV117Test: {$assertions} assertions passed\n");
