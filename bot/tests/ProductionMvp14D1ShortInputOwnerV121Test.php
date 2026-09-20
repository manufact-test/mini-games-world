<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v121.js');
$passive = file_get_contents($root . '/app/assets/js/screens/notifications-passive-v121.js');
if (!is_string($entry) || !is_string($owner) || !is_string($passive)) {
    throw new RuntimeException('Missing D1 short-input v121 sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($entry, 'notification-window-owner-v121.js?v=121'), 'v123 must publish v121 notification input owner.');
$assert(str_contains($entry, 'notifications-passive-v121.js?v=121'), 'v123 must route the old notification service to passive v121.');
$assert(!str_contains($entry, 'notification-window-owner-v119.js?v=119'), 'Retired click-only v119 must not remain active.');
$assert(str_contains($entry, 'v123-mvp14-d1-two-manual-regressions'), 'The integrated shell must expose v123.');
foreach (['pointerdown', 'pointerup', 'pointercancel', 'click', 'keydown'] as $eventName) {
    $assert(str_contains($owner, "addEventListener('{$eventName}'"), "The canonical owner must own {$eventName}.");
}
$assert(str_contains($owner, 'function handlePointerUp(event)') && str_contains($owner, 'openFromUserInput();'),
    'A valid real pointerup must open from the original gesture.');
$assert(str_contains($owner, 'suppressClickUntil = Date.now() + CLICK_SUPPRESSION_MS')
        && str_contains($owner, 'if (Date.now() <= suppressClickUntil'),
    'The generated click must be consumed as the same gesture.');
$assert(!str_contains($owner, '.click()') && !preg_match('/setTimeout\s*\([^\n]*openFromUserInput/u', $owner),
    'The owner must not synthesize or retry an opening click.');
$assert(str_contains($owner, 'event.stopImmediatePropagation();')
        && str_contains($owner, 'requestGeneration === generation'),
    'The canonical capture boundary and late-response guard must remain active.');
$assert(!str_contains($passive, 'openNotificationsSheet(')
        && !str_contains($passive, "from '../components/sheet.js")
        && str_contains($passive, 'refreshNotificationBadge(false)')
        && str_contains($passive, 'showNotificationToast(item)'),
    'The passive service may poll and display toast content but cannot open the sheet.');

fwrite(STDOUT, "ProductionMvp14D1ShortInputOwnerV121Test: {$assertions} assertions passed\n");
