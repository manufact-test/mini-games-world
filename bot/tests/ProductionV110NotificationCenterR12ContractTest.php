<?php
declare(strict_types=1);

// Isolated R12 notification authority contract.
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
    str_contains($center, 'element.__mgwNotificationItem = cloneItem(item);')
        && str_contains($center, 'pressedToastItem = toastSnapshot(element);')
        && str_contains($center, 'pressedToastItem || toastSnapshot() || newestItem()')
        && str_contains($center, "openNotificationsSheet({ seed:[item], source:'toast' })"),
    'A tapped toast must preserve one exact immutable item before any close or asynchronous refresh.'
);
$paintPosition = strpos($center, "if (source === 'toast') await waitForFirstSheetPaint(generation);");
$refreshPosition = strpos($center, 'await refreshOpenSheet(generation);', $paintPosition ?: 0);
$assert(
    $paintPosition !== false && $refreshPosition !== false && $paintPosition < $refreshPosition
        && str_contains($center, 'window.requestAnimationFrame(resolve)'),
    'The exact toast card must paint before background notification reconciliation may repaint it.'
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
    str_contains($center, "document.addEventListener('mgw:notification-remove'")
        && str_contains($center, 'function removeInviteNotification(detail)')
        && str_contains($center, 'items.delete(id)')
        && str_contains($center, 'localAuthority.delete(key)')
        && str_contains($center, 'sheetState.pinned.delete(key)')
        && !str_contains($center, "mgw:invite-action-local-result")
        && !str_contains($center, 'applyInviteActionResult'),
    'Actor terminal actions must remove the invitation card through the single notification owner instead of creating a self-confirmation state.'
);

fwrite(STDOUT, "ProductionV110NotificationCenterR12ContractTest: {$assertions} assertions passed\n");
