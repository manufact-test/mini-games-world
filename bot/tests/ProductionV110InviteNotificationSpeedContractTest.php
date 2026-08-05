<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read production v110 source: ' . $path);
    return $content;
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$invites = $read('app/assets/js/games/game-invites-v110.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$sheet = $read('app/assets/js/components/sheet.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$clean = $read('app/assets/js/production-clean-entry-v110.js');

$assert(str_contains($invites, 'const WATCH_INTERVAL_MS = 400;')
    && str_contains($invites, 'const ACTIVE_SYNC_INTERVAL_MS = 500;')
    && str_contains($invites, 'const IDLE_SYNC_INTERVAL_MS = 1500;'),
    'The accepted lightweight invitation signal and bounded sync cadences must remain.');

$directPaint = strpos($invites, 'showDirectInvitePending(context, opponentName);');
$directRequest = strpos($invites, "const result = await inviteRequest('create_direct'");
$assert($directPaint !== false && $directRequest !== false && $directPaint < $directRequest,
    'The inviter must still see the owner surface before the request starts.');

$assert(str_contains($sheet, 's.replaceChildren();')
    && str_contains($sheet, "attributeFilter:['class']"),
    'A closed canonical sheet must remove hidden HTML even when an older import removes the active class.');

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
    && str_contains($perform, 'terminalContext.notificationSurface')
    && str_contains($perform, 'showTerminalInvite(terminalInvite);'),
    'The canonical invitation owner must preserve the current surface and keep actor terminal actions silent.');

$assert(str_contains($notifications, 'if (showToast(item)) rememberAnnouncedId(item.id);')
    && str_contains($notifications, 'if (next && showToast(next)) rememberAnnouncedId(next.id);')
    && str_contains($notifications, "return ['home','profile'].includes(screen);"),
    'An item may be marked announced only after it was actually shown on an allowed screen.');
$assert(str_contains($notifications, "document.addEventListener('mgw:sheet-closed', handleSheetClosed);")
    && str_contains($notifications, 'announcementGuardUntil'),
    'Notifications suppressed by active sheet lifecycle guards must remain bounded and independently deliverable.');

$bell = strpos($notifications, "const bell = target.closest('#notificationsOpen');");
$open = strpos($notifications, "void openNotificationsSheet({ seed:currentItems(), source:'bell' });", $bell ?: 0);
$assert($bell !== false && $open !== false && $bell < $open,
    'The single notification owner must open the bell immediately on the first click.');
$assert(!str_contains($shell, 'NotificationPreflight')
    && substr_count($shell, 'initGameInvites();') === 1
    && substr_count($shell, 'initNotificationsScreen();') === 1
    && !str_contains($shell, 'initInviteTerminalActions')
    && !str_contains($shell, 'invite-terminal-actions-v110r12.js')
    && !str_contains($clean, 'initV109SelfCancelRefreshGuard'),
    'The active graph must contain one invitation owner and one notification owner without overlay guards.');

fwrite(STDOUT, 'ProductionV110InviteNotificationSpeedContractTest: ' . $assertions . " assertions passed\n");
