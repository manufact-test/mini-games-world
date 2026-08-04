<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$legacy = file_get_contents($root . '/app/assets/js/screens/notification-bell-first-click-v116.js');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v118.js');
if (!is_string($entry) || !is_string($legacy) || !is_string($owner)) {
    throw new RuntimeException('Missing desktop bell ownership sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($entry, 'notification-window-owner-v118.js?v=118') === 1
        && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116'),
    'The active entry must publish the window owner exactly once and retire the v116 retry guard.');
$assert(str_contains($legacy, 'STALE_REOPEN_BLOCK_MS = 1200')
        && str_contains($legacy, 'closeSheet();'),
    'The retained rollback file documents why v116 cannot coexist with immediate legitimate reopen clicks.');
$assert(str_contains($owner, "window.addEventListener('click'")
        && str_contains($owner, 'event.stopImmediatePropagation();')
        && str_contains($owner, 'const requestGeneration = ++generation;'),
    'The active owner must receive the original click before document handlers and gate stale responses by generation.');
$assert(str_contains($owner, "document.addEventListener('mgw:sheet-closed'")
        && str_contains($owner, 'if (wasActive && !active) invalidateOpenRequest();'),
    'Manual close must invalidate only in-flight responses without blocking the next trusted click.');
$assert(!str_contains($owner, 'STALE_REOPEN_BLOCK_MS')
        && !str_contains($owner, 'bell.click();'),
    'The single owner must not synthesize clicks or impose a post-close dead window.');

fwrite(STDOUT, "ProductionMvp14D1FeedbackDesktopBellFirstClickTest: {$assertions} assertions passed\n");
