<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v110 source: ' . $path);
    return $content;
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$sheet = $read('app/assets/js/components/sheet.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$clean = $read('app/assets/js/production-clean-entry-v110.js');
$presence = $read('bot/services/PresenceService.php');
$auth = $read('bot/services/AuthService.php');
$api = $read('bot/api.php');

$assert(str_contains($sheet, 's.replaceChildren();')
    && str_contains($sheet, "document.dispatchEvent(new CustomEvent('mgw:sheet-closed'))"),
    'Closing a sheet must remove stale content and announce the lifecycle transition.');

$performStart = strpos($invites, 'async function performInviteAction(');
$performEnd = strpos($invites, 'async function createRematch(', $performStart ?: 0);
$perform = $performStart !== false && $performEnd !== false
    ? substr($invites, $performStart, $performEnd - $performStart)
    : '';
$assert(str_contains($invites, "../components/sheet.js?v=1109")
    && str_contains($invites, "../components/toast.js?v=1109")
    && $perform !== ''
    && !str_contains($perform, "if (action === 'decline') toast('Приглашение отклонено.')")
    && !str_contains($perform, "action === 'decline' || action === 'cancel') {\n    closeSheet();")
    && str_contains($perform, "new CustomEvent('mgw:notification-sync'")
    && str_contains($perform, 'announce:false'),
    'The canonical invitation owner must keep decline/cancel in place and silent.');

$assert(str_contains($invites, "card.closest('#sheet')?.querySelector('[data-notifications-owner=\"r12\"]')")
    && str_contains($invites, 'function terminalNotificationItem(')
    && str_contains($invites, 'actions:[]'),
    'The canonical owner must replace the exact active notification card with a non-actionable terminal card.');

$assert(str_contains($notifications, "event.target.closest('#notificationsOpen')")
    && str_contains($notifications, "openNotificationsSheet({ seed:currentItems(), source:'bell' })")
    && str_contains($notifications, "document.addEventListener('mgw:notification-sync'")
    && str_contains($notifications, 'pinItem(item);')
    && str_contains($notifications, 'renderNotifications(visibleSheetItems());'),
    'The notification owner must open the bell and own in-place terminal reconciliation.');

$assert(!str_contains($shell, 'NotificationPreflight')
    && substr_count($shell, 'initNotificationsScreen();') === 1
    && substr_count($shell, 'initGameInvites();') === 1
    && !str_contains($shell, 'initInviteTerminalActions')
    && !str_contains($shell, 'invite-terminal-actions-v110r12.js')
    && !str_contains($clean, 'initV109SelfCancelRefreshGuard'),
    'No notification preflight, duplicate invite action owner or self-cancel overlay may remain active.');

$assert(str_contains($presence, "\$GLOBALS['config']['data_dir']")
    && !str_contains($auth, 'touchAuthenticatedPresence')
    && str_contains($api, "\$action === 'bootstrap'")
    && str_contains($api, '$presenceService->touch('),
    'Normal and invitation launches must confirm presence through the same bootstrap-owned configured root.');

fwrite(STDOUT, "ProductionV110InviteNotificationPresenceRegressionContractTest: {$assertions} assertions passed\n");
