<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$entry = $read('app/v110.php');

$assert(str_contains($notifications, 'if (Date.now() < closeGuardUntil || Date.now() < openGuardUntil) return;')
    && str_contains($notifications, 'CLOSE_GUARD_MS = 1100')
    && str_contains($notifications, 'armCloseGuard()'),
    'The notification owner must reject ghost opening immediately after close.');
$assert(str_contains($notifications, 'LOCAL_AUTHORITY_MS = 12000')
    && str_contains($notifications, 'mergeServerItems(serverItems)')
    && str_contains($notifications, 'rememberLocalAuthority(item)'),
    'A stale background response must not replace locally authoritative data.');
$assert(str_contains($notifications, 'pressedToastItem = toastSnapshot(element);')
    && str_contains($notifications, 'pressedToastItem || toastSnapshot() || newestItem()')
    && str_contains($notifications, "openNotificationsSheet({ seed:[item], source:'toast' })")
    && str_contains($notifications, 'sheetState.pinned'),
    'The tapped immutable toast snapshot must become the first-frame sheet authority.');
$assert(str_contains($notifications, "if (source === 'toast') await waitForFirstSheetPaint(generation);")
    && str_contains($notifications, 'window.requestAnimationFrame(resolve)')
    && str_contains($notifications, 'await refreshOpenSheet(generation);'),
    'Background reconciliation must not repaint before the exact mobile first frame is painted.');
$assert(str_contains($shell, 'notifications-screen-v110r12.js?v=1120')
    && !str_contains($shell, 'notifications-screen-v110r5.js')
    && str_contains($entry, 'main-v110.js?v=1121'),
    'Production must load only the current notification owner through the current statistics shell.');

fwrite(STDOUT, "ProductionV110MobileToastAuthorityContractTest: {$assertions} assertions passed\n");
