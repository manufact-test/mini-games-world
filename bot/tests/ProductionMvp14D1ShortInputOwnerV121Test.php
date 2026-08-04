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

$assert(str_contains($entry, 'notification-window-owner-v121.js?v=121'),
    'The no-cache shell must publish the v121 notification input owner.');
$assert(str_contains($entry, 'notifications-passive-v121.js?v=121'),
    'The historical notifications module must resolve to the passive v121 badge/toast surface.');
$assert(!str_contains($entry, 'notification-window-owner-v119.js?v=119'),
    'The retired v119 click-only owner must not remain active.');
$assert(str_contains($entry, 'v121-mvp14-notification-short-input-owner'),
    'The live shell and response header must expose the v121 build identity.');

foreach (['pointerdown', 'pointerup', 'pointercancel', 'click', 'keydown'] as $eventName) {
    $assert(str_contains($owner, "addEventListener('{$eventName}'"),
        "The canonical owner must explicitly own {$eventName}.');
}
$assert(str_contains($owner, 'openFromUserInput();')
        && str_contains($owner, 'function handlePointerUp(event)'),
    'A valid real pointerup must open immediately from the original gesture.');
$assert(str_contains($owner, 'suppressClickUntil = Date.now() + CLICK_SUPPRESSION_MS')
        && str_contains($owner, 'return;\n  }\n\n  // Keyboard activation'),
    'The click generated after pointerup must be consumed as the same gesture, not retried.');
$assert(!str_contains($owner, '.click()'),
    'The v121 owner must not synthesize a second click.');
$assert(!preg_match('/setTimeout\s*\([^\n]*openFromUserInput/u', $owner),
    'The v121 owner must not open later through an automatic retry.');
$assert(str_contains($owner, "event.stopImmediatePropagation();")
        && str_contains($owner, "notificationTrigger(event.target)"),
    'The original gesture must be consumed at the canonical capture boundary.');

$assert(!str_contains($passive, "closest('#notificationsOpen')")
        && !str_contains($passive, 'openNotificationsSheet(')
        && !str_contains($passive, "from '../components/sheet.js"),
    'The passive module must not own bell/toast opening or sheet rendering.');
$assert(str_contains($passive, 'notification-window-owner-v121 owns'),
    'The passive module must document the single-owner boundary.');
$assert(str_contains($passive, "refreshNotificationBadge(false)")
        && str_contains($passive, 'showNotificationToast(item)'),
    'Badge polling and visual toast delivery must remain active.');

fwrite(STDOUT, "ProductionMvp14D1ShortInputOwnerV121Test: {$assertions} assertions passed\n");
