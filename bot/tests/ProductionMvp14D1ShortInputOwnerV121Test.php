<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v121.js');
$passive = file_get_contents($root . '/app/assets/js/screens/notifications-passive-v121.js');
if (!is_string($entry) || !is_string($owner) || !is_string($passive)) {
    throw new RuntimeException('Missing D1 short-input v124 sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($entry, 'notification-window-owner-v121.js?v=121'), 'The staging shell must publish v121 notification input owner.');
$assert(str_contains($entry, 'notifications-passive-v121.js?v=121'), 'The historical notification service must remain passive.');
$assert(!str_contains($entry, 'notification-window-owner-v119.js?v=119'), 'Retired click-only v119 must not remain active.');
foreach (['pointerdown', 'pointerup', 'pointercancel', 'click', 'keydown'] as $eventName) {
    $assert(str_contains($owner, "addEventListener('{$eventName}'"), "The canonical owner must own {$eventName}.");
}
$assert(str_contains($owner, 'function handlePointerUp(event)')
        && str_contains($owner, 'openFromUserInput();')
        && str_contains($owner, 'suppressedClickTail = [' ) === false,
    'A valid real pointerup must open directly without queuing another action.');
$assert(str_contains($owner, 'suppressedClickTail = {')
        && str_contains($owner, 'CLICK_SUPPRESSION_RADIUS_PX = 32')
        && str_contains($owner, 'function isSuppressedClickTail(event)'),
    'The original gesture must retain a bounded coordinate signature for its generated click tail.');
$clickHandler = strpos($owner, 'function handleClickFallback(event)');
$tailCheck = strpos($owner, 'if (isSuppressedClickTail(event))', $clickHandler === false ? 0 : $clickHandler);
$targetLookup = strpos($owner, 'const trigger = notificationTrigger(event.target);', $clickHandler === false ? 0 : $clickHandler);
$assert($clickHandler !== false && $tailCheck !== false && $targetLookup !== false && $tailCheck < $targetLookup,
    'A retargeted mobile click must be consumed before the new overlay target is inspected.');
$assert(str_contains($owner, 'pointInsideElement(originalTrigger, endX, endY, TAP_MOVE_TOLERANCE_PX)')
        && str_contains($owner, 'Math.hypot(dx, dy) <= CLICK_SUPPRESSION_RADIUS_PX'),
    'Pointerup and click-tail matching must be coordinate-bounded, not a global blackout.');
$assert(!str_contains($owner, '.click()')
        && !preg_match('/setTimeout\s*\([^\n]*openFromUserInput/u', $owner),
    'The owner must not synthesize or retry an opening click.');
$assert(str_contains($owner, 'event.stopImmediatePropagation();')
        && str_contains($owner, 'requestGeneration === generation'),
    'The canonical capture boundary and late-response guard must remain active.');
$assert(!str_contains($passive, 'openNotificationsSheet(')
        && !str_contains($passive, "from '../components/sheet.js")
        && str_contains($passive, 'refreshNotificationBadge(false)')
        && str_contains($passive, 'showNotificationToast(item)'),
    'The passive service may poll and display toast content but cannot open the sheet.');
$assert(str_contains($passive, 'if (!baselineLoaded || !appReady)')
        && str_contains($passive, 'if (!announce) return;')
        && !str_contains($passive, 'if (!baselineLoaded || !announce || !appReady)'),
    'A silent post-baseline badge refresh must not pre-consume a newly arrived invitation toast.');

fwrite(STDOUT, "ProductionMvp14D1ShortInputOwnerV121Test: {$assertions} assertions passed\n");
