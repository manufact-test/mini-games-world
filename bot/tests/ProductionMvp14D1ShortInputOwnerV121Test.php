<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v124.js');
$passive = file_get_contents($root . '/app/assets/js/screens/notifications-passive-v124.js');
if (!is_string($entry) || !is_string($owner) || !is_string($passive)) {
    throw new RuntimeException('Missing D1 short-input v124 sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($entry, 'notification-window-owner-v124.js?v=124'), 'v124 must publish its notification input owner.');
$assert(str_contains($entry, 'notifications-passive-v124.js?v=124'), 'v124 must route the old notification service to passive v124.');
$assert(!str_contains($entry, 'notification-window-owner-v121.js?v=121')
        && !str_contains($entry, 'notifications-passive-v121.js?v=121')
        && !str_contains($entry, 'notification-window-owner-v119.js?v=119'), 'Retired notification owners and passive services must not remain active.');
$assert(str_contains($entry, 'v124-mvp14-d1-live-failure-fixes'), 'The integrated shell must expose v124.');
foreach (['pointerdown', 'pointerup', 'pointercancel', 'touchstart', 'touchend', 'touchcancel', 'click', 'keydown'] as $eventName) {
    $assert(str_contains($owner, "addEventListener('{$eventName}'"), "The canonical owner must own {$eventName}.");
}
$assert(str_contains($owner, 'function handlePointerUp(event)')
        && str_contains($owner, 'function handleTouchEnd(event)')
        && str_contains($owner, 'openFromUserInput();'),
    'A valid real pointerup or touchend must open from the original gesture.');
$assert(str_contains($owner, 'function claimGesture(triggerId)')
        && str_contains($owner, 'function isCompatibilityTail(triggerId)')
        && str_contains($owner, 'INPUT_TAIL_SUPPRESSION_MS = 700'),
    'Pointer, touch and generated click tails must be consumed as one gesture.');
$assert(!str_contains($owner, '.click()') && !preg_match('/setTimeout\s*\([^\n]*openFromUserInput/u', $owner),
    'The owner must not synthesize or retry an opening click.');
$assert(str_contains($owner, 'event.stopImmediatePropagation();')
        && str_contains($owner, 'requestGeneration === generation'),
    'The canonical capture boundary and late-response guard must remain active.');
$assert(!str_contains($passive, 'openNotificationsSheet(')
        && !str_contains($passive, "from '../components/sheet.js")
        && str_contains($passive, 'refreshNotificationBadge(false)')
        && str_contains($passive, 'showNotificationToast(item)')
        && str_contains($passive, 'rememberBaselineNotifications(items, requestStartedAt)'),
    'The passive service may poll and display fresh toast content but cannot open the sheet.');

fwrite(STDOUT, "ProductionMvp14D1ShortInputOwnerV121Test: {$assertions} assertions passed\n");
