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
$notifications = $read('app/assets/js/screens/notifications-screen-v110r5.js');
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
$assert(str_contains($invites, "../components/sheet.js?v=1109")
    && str_contains($invites, "../components/toast.js?v=1109")
    && str_contains($invites, "if (action === 'decline') toast('Приглашение отклонено.');")
    && !str_contains($invites, "toast(action === 'decline' ?"),
    'The canonical invitation owner must use the fresh shared components and keep self-cancel silent.');

$assert(str_contains($notifications, 'if (item && showToast(item)) rememberAnnouncedId')
    && str_contains($notifications, "if (showToast(item)) rememberAnnouncedId(id);")
    && str_contains($notifications, "['home', 'profile'].includes(screenName)"),
    'An item may be marked announced only after it was actually shown on an allowed screen.');
$assert(str_contains($notifications, "document.addEventListener('mgw:sheet-closed'")
    && str_contains($notifications, 'announceNextLiveItem'),
    'A notification suppressed by an open sheet must remain deliverable after the sheet closes.');

$bell = strpos($notifications, "event.target.closest('#notificationsOpen')");
$open = strpos($notifications, 'void openNotificationsSheet(currentItems());', $bell ?: 0);
$assert($bell !== false && $open !== false && $bell < $open,
    'The single notification owner must open the bell immediately on the first click.');
$assert(!str_contains($shell, 'NotificationPreflight')
    && substr_count($shell, 'initGameInvites();') === 1
    && substr_count($shell, 'initNotificationsScreen();') === 1
    && !str_contains($clean, 'initV109SelfCancelRefreshGuard'),
    'The active graph must contain one invitation owner and one notification owner without overlay guards.');

fwrite(STDOUT, 'ProductionV110InviteNotificationSpeedContractTest: ' . $assertions . " assertions passed\n");
