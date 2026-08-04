<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v121.js');
if (!is_string($entry) || !is_string($owner)) {
    throw new RuntimeException('Missing canonical desktop bell close-race sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($entry, 'notification-window-owner-v121.js?v=121') === 1
        && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116')
        && !str_contains($entry, 'notification-window-owner-v119.js?v=119'),
    'The staging entry must load one canonical v121 owner and no retired click retry owner.');
$assert(str_contains($owner, "window.addEventListener('pointerdown'")
        && str_contains($owner, "window.addEventListener('pointerup'")
        && str_contains($owner, 'notificationTrigger(event.target)')
        && str_contains($owner, 'event.preventDefault();')
        && str_contains($owner, 'event.stopImmediatePropagation();'),
    'Every original bell pointer sequence must be handled before document listeners.');
$assert(str_contains($owner, "document.addEventListener('mgw:sheet-closed'")
        && str_contains($owner, 'const requestGeneration = ++generation;')
        && str_contains($owner, 'if (wasActive && !active) invalidateOpenRequest();')
        && str_contains($owner, 'requestGeneration === generation'),
    'Manual close must invalidate every in-flight response without blocking the next real gesture.');
$assert(!str_contains($owner, 'STALE_REOPEN_BLOCK_MS')
        && !str_contains($owner, 'bell.click();')
        && !str_contains($owner, 'openingSheet')
        && !str_contains($owner, '.click()'),
    'The canonical owner must not synthesize retries or impose a post-close blackout.');
$assert(str_contains($owner, 'renderLoading();')
        && substr_count($owner, 'api.notifications(true)') >= 2
        && str_contains($owner, 'if (!items.length && freshItems().length) items = freshItems();'),
    'The owner itself must render immediately and confirm delayed empty responses.');

fwrite(STDOUT, "ProductionMvp14D1FeedbackDesktopBellFirstClickTest: {$assertions} assertions passed\n");
