<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v121.js');
$passive = file_get_contents($root . '/app/assets/js/screens/notifications-passive-v121.js');
if (!is_string($entry) || !is_string($owner) || !is_string($passive)) {
    throw new RuntimeException('Missing canonical notification owner v121 sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($entry, 'notification-window-owner-v121.js?v=121') === 1,
    'The canonical v121 notification owner must be published exactly once.');
$assert(!str_contains($entry, 'notification-window-owner-v119.js?v=119')
        && !str_contains($entry, 'notification-empty-frame-guard-v115.js?v=115')
        && !str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116'),
    'Every retired notification interaction owner must be removed from the active graph.');
$assert(str_contains($entry, 'notifications-passive-v121.js?v=121'),
    'The historical notification service must resolve to the passive badge/toast module.');
$assert(str_contains($owner, "window.addEventListener('pointerdown'")
        && str_contains($owner, "window.addEventListener('pointerup'")
        && str_contains($owner, "window.addEventListener('click'")
        && str_contains($owner, 'event.stopImmediatePropagation();'),
    'The original pointer sequence must be owned at window capture before document handlers.');
$assert(str_contains($owner, 'function handlePointerUp(event)')
        && str_contains($owner, 'openFromUserInput();')
        && str_contains($owner, 'suppressClickUntil = Date.now() + CLICK_SUPPRESSION_MS'),
    'A valid real pointerup must open once and consume its generated click tail.');
$assert(!str_contains($owner, '.click()')
        && !str_contains($owner, 'openingSheet')
        && !str_contains($owner, 'STALE_REOPEN_BLOCK_MS'),
    'The canonical owner must not synthesize retries or impose a blackout.');
$assert(str_contains($owner, "document.addEventListener('mgw:notification-sync'")
        && str_contains($owner, 'rememberItems([item])')
        && str_contains($owner, "document.addEventListener('mgw:notifications-refresh'"),
    'Document notification events must feed the same canonical first-frame cache.');
$assert(str_contains($owner, 'const requestGeneration = ++generation;')
        && str_contains($owner, 'requestGeneration === generation')
        && str_contains($owner, 'if (wasActive && !active) invalidateOpenRequest();'),
    'Each gesture must open independently while stale and post-close responses remain gated.');
$assert(substr_count($owner, 'api.notifications(true)') >= 2
        && str_contains($owner, 'EMPTY_CONFIRM_DELAY_MS = 180')
        && str_contains($owner, 'if (!items.length && freshItems().length) items = freshItems();'),
    'A false empty response must be confirmed without replacing a fresh invitation.');
$assert(!str_contains($passive, 'openNotificationsSheet(')
        && !str_contains($passive, "from '../components/sheet.js")
        && str_contains($passive, 'refreshNotificationBadge(false)')
        && str_contains($passive, 'showNotificationToast(item)'),
    'The passive service may poll badges and display toast content but cannot open the sheet.');

fwrite(STDOUT, "ProductionMvp14D1CanonicalNotificationOwnerV119Test: {$assertions} assertions passed\n");
