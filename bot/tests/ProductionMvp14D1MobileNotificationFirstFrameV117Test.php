<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-mobile-open-owner-v117.js');
if (!is_string($entry) || !is_string($owner)) {
    throw new RuntimeException('Missing mobile notification first-frame sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($entry, 'notification-mobile-open-owner-v117.js?v=117') === 1,
    'The mobile notification owner must be published exactly once.');
$assert(str_contains($owner, '#notificationsOpen, #notificationToast')
        && str_contains($owner, "window.matchMedia('(max-width: 760px)')"),
    'The owner must handle notification taps only on mobile surfaces.');
$assert(str_contains($owner, 'if (freshItems().length)')
        && str_contains($owner, 'renderNotifications(freshItems());')
        && str_contains($owner, 'EMPTY_CONFIRM_DELAY_MS = 180'),
    'A fresh item must paint immediately while an empty response is confirmed.');
$assert(substr_count($owner, 'api.notifications(true)') >= 2
        && str_contains($owner, 'requestGeneration !== generation'),
    'Slow responses must be retried and stale generations ignored.');
$assert(str_contains($owner, "document.addEventListener('mgw:notification-sync'")
        && str_contains($owner, 'rememberItems([item]);'),
    'The first frame must be primed by live notification events.');

fwrite(STDOUT, "ProductionMvp14D1MobileNotificationFirstFrameV117Test: {$assertions} assertions passed\n");
