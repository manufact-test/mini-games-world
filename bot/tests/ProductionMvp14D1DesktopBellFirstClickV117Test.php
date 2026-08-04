<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-desktop-open-owner-v117.js');
if (!is_string($entry) || !is_string($owner)) {
    throw new RuntimeException('Missing desktop notification first-click sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($entry, 'notification-desktop-open-owner-v117.js?v=117') === 1,
    'The desktop notification owner must be published exactly once.');
$assert(str_contains($owner, '#notificationsOpen, #notificationToast')
        && str_contains($owner, 'event.stopImmediatePropagation();')
        && str_contains($owner, 'isDesktopSurface()'),
    'The desktop owner must receive the original click before the stale canonical opening lock.');
$assert(!str_contains($owner, 'openingSheet')
        && str_contains($owner, 'const requestGeneration = ++generation;'),
    'Every click must open immediately and use generations instead of a blocking opening flag.');
$assert(str_contains($owner, 'renderLoading();')
        && str_contains($owner, 'requestGeneration !== generation')
        && str_contains($owner, '!isNotificationsSheetOpen()'),
    'A slow prior request must never prevent or repaint a later desktop click.');
$assert(substr_count($owner, 'api.notifications(true)') >= 2,
    'An empty or delayed desktop result must receive an authoritative confirmation request.');

fwrite(STDOUT, "ProductionMvp14D1DesktopBellFirstClickV117Test: {$assertions} assertions passed\n");
