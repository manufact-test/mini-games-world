<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v118.js');
if (!is_string($entry) || !is_string($owner)) {
    throw new RuntimeException('Missing notification window owner sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($entry, 'notification-window-owner-v118.js?v=118') === 1,
    'The v118 notification interaction owner must be published exactly once.');
$assert(str_contains($owner, "window.addEventListener('click'")
        && str_contains($owner, "#notificationsOpen, #notificationToast")
        && str_contains($owner, 'event.stopImmediatePropagation();'),
    'The original user click must be owned at window capture before every legacy document handler.');
$assert(!str_contains($owner, 'openingSheet')
        && str_contains($owner, 'const requestGeneration = ++generation;')
        && str_contains($owner, 'requestGeneration === generation'),
    'Every click must open independently and stale requests must be generation-gated.');
$assert(substr_count($owner, 'api.notifications(true)') >= 2
        && str_contains($owner, 'EMPTY_CONFIRM_DELAY_MS = 180')
        && str_contains($owner, 'if (!items.length && freshItems().length) items = freshItems();'),
    'A delayed false-empty response must be confirmed without replacing a fresh first frame.');
$assert(str_contains($owner, "window.addEventListener('mgw:notification-remove'")
        && str_contains($owner, "String(item?.invite_token || '') !== inviteToken"),
    'Terminal invitation actions must evict their stale actionable card from the first-frame cache.');
$assert(str_contains($owner, "document.addEventListener('mgw:sheet-closed'")
        && str_contains($owner, 'if (wasActive && !active) invalidateOpenRequest();'),
    'Manual close must invalidate in-flight responses even when the close event is missed.');

fwrite(STDOUT, "ProductionMvp14D1NotificationWindowOwnerV118Test: {$assertions} assertions passed\n");
