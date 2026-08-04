<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v119.js');
if (!is_string($entry) || !is_string($owner)) {
    throw new RuntimeException('Missing canonical notification owner v119 sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($entry, 'notification-window-owner-v119.js?v=119') === 1,
    'The canonical v119 notification owner must be published exactly once.');
$assert(!str_contains($entry, 'notification-empty-frame-guard-v115.js?v=115')
        && !str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116'),
    'Every competing notification interaction owner must be removed from the active graph.');
$assert(str_contains($owner, "window.addEventListener('click'")
        && str_contains($owner, "#notificationsOpen, #notificationToast")
        && str_contains($owner, 'event.stopImmediatePropagation();'),
    'The original user gesture must be owned at window capture before legacy document handlers.');
$assert(str_contains($owner, "document.addEventListener('mgw:notification-sync'")
        && str_contains($owner, 'rememberItems([item])')
        && str_contains($owner, "document.addEventListener('mgw:notifications-refresh'"),
    'Document-targeted notification events must feed the same canonical first-frame cache.');
$assert(!str_contains($owner, 'openingSheet')
        && str_contains($owner, 'const requestGeneration = ++generation;')
        && str_contains($owner, 'requestGeneration === generation'),
    'Each click must open independently while stale responses remain generation-gated.');
$assert(substr_count($owner, 'api.notifications(true)') >= 2
        && str_contains($owner, 'EMPTY_CONFIRM_DELAY_MS = 180')
        && str_contains($owner, 'if (!items.length && freshItems().length) items = freshItems();'),
    'A false empty response must be confirmed without replacing a fresh cached invitation.');
$assert(str_contains($owner, "document.addEventListener('mgw:notification-remove'")
        && str_contains($owner, "String(item?.invite_token || '') !== inviteToken")
        && str_contains($owner, 'generation += 1;'),
    'Terminal invitation actions must evict stale actions and invalidate prior requests.');
$assert(str_contains($owner, "document.addEventListener('mgw:sheet-closed'")
        && str_contains($owner, 'if (wasActive && !active) invalidateOpenRequest();'),
    'Manual close must prevent every late response from reopening the sheet.');

fwrite(STDOUT, "ProductionMvp14D1CanonicalNotificationOwnerV119Test: {$assertions} assertions passed\n");
