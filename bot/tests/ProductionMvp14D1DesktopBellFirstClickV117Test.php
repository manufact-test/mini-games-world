<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$legacy = file_get_contents($root . '/app/assets/js/screens/notification-desktop-open-owner-v117.js');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v118.js');
if (!is_string($entry) || !is_string($legacy) || !is_string($owner)) {
    throw new RuntimeException('Missing desktop notification ownership sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(!str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117')
        && substr_count($entry, 'notification-window-owner-v118.js?v=118') === 1,
    'The desktop v117 owner must be retained only for rollback and superseded by one window owner.');
$assert(str_contains($legacy, 'isDesktopSurface()')
        && str_contains($legacy, 'const requestGeneration = ++generation;'),
    'The historical desktop implementation must remain inspectable without being active.');
$assert(str_contains($owner, "window.addEventListener('click'")
        && str_contains($owner, 'event.stopImmediatePropagation();')
        && !str_contains($owner, 'openingSheet'),
    'The active owner must receive every original desktop click before legacy document locks.');
$assert(str_contains($owner, 'renderLoading();')
        && str_contains($owner, 'requestGeneration === generation')
        && str_contains($owner, 'isNotificationsSheetOpen()'),
    'Each click must open immediately and stale responses must not repaint a later or closed sheet.');
$assert(substr_count($owner, 'api.notifications(true)') >= 2,
    'An empty or delayed desktop result must receive authoritative confirmation.');

fwrite(STDOUT, "ProductionMvp14D1DesktopBellFirstClickV117Test: {$assertions} assertions passed\n");
