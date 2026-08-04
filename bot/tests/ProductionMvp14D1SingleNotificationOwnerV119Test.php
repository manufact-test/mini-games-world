<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v118.js');
$legacyBell = file_get_contents($root . '/app/assets/js/screens/notification-bell-first-click-v116.js');
if (!is_string($entry) || !is_string($owner) || !is_string($legacyBell)) {
    throw new RuntimeException('Missing single notification owner sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($entry, 'notification-window-owner-v118.js?v=118') === 1,
    'The active staging entry must publish exactly one notification interaction owner.');
foreach ([
    'notification-empty-frame-guard-v115.js?v=115',
    'notification-bell-first-click-v116.js?v=116',
    'notification-mobile-open-owner-v117.js?v=117',
    'notification-desktop-open-owner-v117.js?v=117',
] as $script) {
    $assert(!str_contains($entry, $script), "Superseded notification layer must be absent from the active graph: {$script}");
}
$assert(str_contains($owner, "window.addEventListener('click'")
        && str_contains($owner, 'event.stopImmediatePropagation();')
        && str_contains($owner, 'openNotificationsSheet();'),
    'The original user click must be consumed and rendered by the window-capture owner.');
$assert(str_contains($owner, 'const requestGeneration = ++generation;')
        && str_contains($owner, 'requestGeneration === generation')
        && str_contains($owner, 'isNotificationsSheetOpen()'),
    'In-flight responses must apply only to the currently open generation.');
$assert(str_contains($owner, "document.addEventListener('mgw:sheet-closed'")
        && !str_contains($owner, 'STALE_REOPEN_BLOCK_MS')
        && !str_contains($owner, 'bell.click();'),
    'Manual close must invalidate stale responses without blocking or synthesizing the next click.');
$assert(str_contains($legacyBell, 'STALE_REOPEN_BLOCK_MS = 1200')
        && str_contains($legacyBell, 'closeSheet();'),
    'The inactive v116 file must preserve rollback evidence for the removed close-race behavior.');

fwrite(STDOUT, "ProductionMvp14D1SingleNotificationOwnerV119Test: {$assertions} assertions passed\n");
