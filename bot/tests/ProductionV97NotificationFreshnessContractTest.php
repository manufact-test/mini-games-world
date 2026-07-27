<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runtime = file_get_contents($root . '/app/assets/js/production-v97-runtime-owner.js');
$notifications = file_get_contents($root . '/bot/notifications.php');
if (!is_string($runtime) || !is_string($notifications)) {
    throw new RuntimeException('Cannot read notification sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($runtime, 'async function openNotificationsOwned()')
        && str_contains($runtime, 'const result = await ownedNotifications(true);')
        && str_contains($runtime, 'renderNotifications(result.items || []);'),
    'Opening the bell must reconcile the sheet from the fresh mark-read response.'
);
$assert(
    str_contains($runtime, 'latestNotifications.loaded')
        && str_contains($runtime, 'latestNotifications.unreadCount === 0'),
    'An empty cached sheet may be reused only when its unread count is also zero.'
);
$assert(
    str_contains($notifications, '$items = mgw_visible_notifications($data, $notifications, $userId, 30);')
        && str_contains($notifications, '$notifications->markAllRead($data, $userId);')
        && str_contains($notifications, "return ['items' => $items, 'unread_count' => 0];"),
    'The server mark-read response must return the visible items before clearing their unread flags.'
);

fwrite(STDOUT, "ProductionV97NotificationFreshnessContractTest: {$assertions} assertions passed\n");
