<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$legacy = file_get_contents($root . '/app/assets/js/screens/notification-mobile-open-owner-v117.js');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v118.js');
if (!is_string($entry) || !is_string($legacy) || !is_string($owner)) {
    throw new RuntimeException('Missing mobile notification ownership sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(!str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
        && substr_count($entry, 'notification-window-owner-v118.js?v=118') === 1,
    'The responsive v117 owner must be retained only for rollback and superseded by one window owner.');
$assert(str_contains($legacy, "window.matchMedia('(max-width: 760px)')")
        && str_contains($legacy, 'EMPTY_CONFIRM_DELAY_MS = 180'),
    'The historical mobile implementation must remain inspectable without being active.');
$assert(str_contains($owner, '#notificationsOpen, #notificationToast')
        && str_contains($owner, 'if (cached.length) renderNotifications(cached);'),
    'The active owner must cover mobile taps and paint fresh cache immediately.');
$assert(substr_count($owner, 'api.notifications(true)') >= 2
        && str_contains($owner, 'requestGeneration === generation'),
    'Slow and empty responses must be confirmed and stale generations ignored.');
$assert(str_contains($owner, "window.addEventListener('mgw:notification-sync'")
        && str_contains($owner, 'rememberItems([item]);'),
    'Live notification events must prime the authoritative first-frame cache.');

fwrite(STDOUT, "ProductionMvp14D1MobileNotificationFirstFrameV117Test: {$assertions} assertions passed\n");
