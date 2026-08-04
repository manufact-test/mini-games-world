<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v119.js');
if (!is_string($entry) || !is_string($owner)) {
    throw new RuntimeException('Missing canonical desktop notification first-click sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($entry, 'notification-window-owner-v119.js?v=119') === 1,
    'The canonical notification owner must be published exactly once.');
$assert(!str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116'),
    'Retired desktop and retry owners must not compete with the canonical v119 owner.');
$assert(str_contains($owner, "window.addEventListener('click'")
        && str_contains($owner, '#notificationsOpen, #notificationToast')
        && str_contains($owner, 'event.stopImmediatePropagation();'),
    'The original desktop click must be owned at window capture before legacy document handlers.');
$assert(!str_contains($owner, 'openingSheet')
        && str_contains($owner, 'const requestGeneration = ++generation;'),
    'Every desktop click must open independently and use generations instead of a blocking opening flag.');
$assert(str_contains($owner, 'renderLoading();')
        && str_contains($owner, 'requestGeneration === generation')
        && str_contains($owner, 'isNotificationsSheetOpen()'),
    'A slow prior request must never prevent or repaint a later desktop click.');
$assert(substr_count($owner, 'api.notifications(true)') >= 2,
    'An empty or delayed desktop result must receive an authoritative confirmation request.');

fwrite(STDOUT, "ProductionMvp14D1DesktopBellFirstClickV117Test: {$assertions} assertions passed\n");
