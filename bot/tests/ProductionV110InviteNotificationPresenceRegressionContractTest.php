<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v110 r4 source: ' . $path);
    return $content;
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$sheet = $read('app/assets/js/components/sheet.js');
$toast = $read('app/assets/js/components/toast.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r4.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$clean = $read('app/assets/js/production-clean-entry-v110.js');
$presence = $read('bot/services/PresenceService.php');
$auth = $read('bot/services/AuthService.php');

$assert(str_contains($sheet, 's.replaceChildren();')
    && str_contains($sheet, "document.dispatchEvent(new CustomEvent('mgw:sheet-closed'))"),
    'Closing a sheet must remove stale content and announce the lifecycle transition.');
$assert(str_contains($toast, 'NON_ACTIONABLE_CONFIRMATIONS')
    && str_contains($toast, "'Приглашение отменено.'"),
    'The canonical toast policy must suppress redundant self-cancel confirmation.');
$assert(str_contains($notifications, "event.target.closest('#notificationsOpen')")
    && str_contains($notifications, 'void openNotificationsSheet(currentItems());'),
    'The first bell click must immediately enter the single notification owner.');
$assert(str_contains($notifications, 'if (item && showToast(item)) rememberAnnouncedId')
    && str_contains($notifications, "document.addEventListener('mgw:sheet-closed'"),
    'A notification may be marked announced only after delivery and must survive temporary suppression.');
$assert(!str_contains($shell, 'NotificationPreflight')
    && substr_count($shell, 'initNotificationsScreen();') === 1
    && !str_contains($clean, 'initV109SelfCancelRefreshGuard'),
    'No notification preflight or self-cancel overlay may remain active.');
$assert(str_contains($presence, "\$GLOBALS['config']['data_dir']")
    && str_contains($auth, '(new PresenceService())->touch($accountId, $sessionId);'),
    'Authenticated normal and invitation launches must write the same configured presence root.');

fwrite(STDOUT, "ProductionV110InviteNotificationPresenceRegressionContractTest: {$assertions} assertions passed\n");
