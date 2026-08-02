<?php
declare(strict_types=1);

// Isolated R12a contract: notification authority only.
$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$center = $read('app/assets/js/screens/notifications-screen-v110r12.js');

$assert(
    str_contains($shell, "notifications-screen-v110r12.js?v=1120")
        && !str_contains($shell, "notifications-screen-v110r5.js?v=1115"),
    'The active shell must load only the R12 notification center.'
);
$assert(
    str_contains($center, "data-notifications-owner=\"r12\"")
        && str_contains($center, 'sheetState.pinned')
        && str_contains($center, 'visibleSheetItems()'),
    'The notification sheet must keep one explicit owner and pinned first-frame items.'
);
$assert(
    str_contains($center, "pressedToastItem = toastItem ? cloneItem(toastItem) : newestItem();")
        && str_contains($center, "openNotificationsSheet({ seed:[item], source:'toast' })"),
    'A tapped toast must preserve the exact item before any close or asynchronous refresh.'
);
$assert(
    str_contains($center, 'LOCAL_AUTHORITY_MS = 12000')
        && str_contains($center, 'mergeServerItems(serverItems)')
        && str_contains($center, 'rememberLocalAuthority(item)'),
    'Fresh local notification authority must survive a temporarily stale server response.'
);
$assert(
    str_contains($center, 'CLOSE_GUARD_MS = 1100')
        && str_contains($center, 'armCloseGuard()')
        && str_contains($center, "target.closest('[data-close-sheet]')"),
    'Closing the notification sheet must suppress click-through and duplicate reopen.'
);
$assert(
    str_contains($center, "mgw:invite-action-local-result")
        && str_contains($center, 'applyInviteActionResult'),
    'Invite actions must update the existing notification card through the notification owner.'
);

fwrite(STDOUT, "ProductionV110NotificationCenterR12ContractTest: {$assertions} assertions passed\n");
