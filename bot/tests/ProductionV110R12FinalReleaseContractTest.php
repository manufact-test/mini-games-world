<?php
declare(strict_types=1);

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

$php = $read('app/v110.php');
$main = $read('app/assets/js/main-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$clean = $read('app/assets/js/production-clean-entry-v110.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$terminal = $read('app/assets/js/games/invite-terminal-actions-v110r12.js');
$opponents = $read('app/assets/js/production-v110-opponent-picker-stability.js');
$stats = $read('app/assets/js/stats-owner-v110.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$lifecycle = $read('app/assets/js/production-v110-match-lifecycle.js');
$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');

$build = 'v110-mvp14r12-invite-notification-stability';
$assert(
    str_contains($php, 'production-clean-entry-v110.js?v=1119')
        && str_contains($php, 'main-v110.js?v=1119')
        && str_contains($php, $build)
        && str_contains($main, "main-v110-handoff-shell.js?v=1119")
        && str_contains($main, $build)
        && str_contains($shell, $build)
        && str_contains($clean, $build),
    'Every active R12 entry owner must publish one fresh build and cache revision.'
);
$assert(
    str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1119';")
        && str_contains($welcome, "Active canonical path: '/app/v110.php?v=1119'."),
    'Telegram menu, start and invite paths must all launch the R12 entrypoint.'
);
$assert(
    substr_count($shell, 'initNotificationsScreen();') === 1
        && str_contains($shell, 'notifications-screen-v110r12.js?v=1119')
        && str_contains($notifications, 'data-notifications-owner="r12"')
        && str_contains($notifications, 'sheetState.pinned')
        && str_contains($notifications, 'CLOSE_GUARD_MS = 1100'),
    'R12 must keep one notification owner with pinned first-frame data and duplicate-open protection.'
);
$assert(
    str_contains($notifications, "pressedToastItem = toastItem ? cloneItem(toastItem) : newestItem();")
        && str_contains($notifications, "openNotificationsSheet({ seed:[item], source:'toast' })")
        && str_contains($notifications, 'mergeServerItems(serverItems)')
        && str_contains($notifications, 'LOCAL_AUTHORITY_MS = 12000'),
    'A tapped blue toast must remain visible while a slower server response reconciles.'
);
$assert(
    str_contains($shell, 'invite-terminal-actions-v110r12.js?v=1119')
        && str_contains($terminal, 'const notificationSurface = isNotificationSurface(button);')
        && str_contains($terminal, "sheet.querySelector('[data-notifications-owner=\"r12\"]')")
        && str_contains($terminal, 'closeSheet();')
        && !str_contains($terminal, 'Вы отклонили это приглашение.')
        && !str_contains($terminal, 'Вы отменили это приглашение.'),
    'Notification-card terminal actions must stay in place while standalone invitation sheets close silently.'
);
$assert(
    str_contains($terminal, 'const rawUnreadCount = result?.unread_count;')
        && str_contains($terminal, 'if (unreadCount !== null)')
        && !str_contains($terminal, 'Number(result?.unread_count || 0)'),
    'A terminal action without an authoritative unread count must preserve unrelated unread notifications.'
);
$assert(
    str_contains($shell, 'production-v110-opponent-picker-stability.js?v=1119')
        && str_contains($opponents, 'EMPTY_RETRY_DELAYS_MS = [240, 680]')
        && str_contains($opponents, 'freshCachedItems()')
        && str_contains($opponents, 'return jsonResponse({ ok:true, items:cached });'),
    'Recent opponents must paint from a scoped fresh cache and retry transient empty responses.'
);
$assert(
    str_contains($shell, 'stats-owner-v110.js?v=1119')
        && str_contains($stats, 'ONLINE_DROP_GRACE_MS = 6500')
        && str_contains($stats, 'sequence < runtime.applied')
        && str_contains($stats, 'next.online_players = stableOnlineCount(next.online_players)'),
    'The online counter must reject stale snapshots and suppress a transient one-sample dip.'
);
$homePosition = strpos($lifecycle, "showScreen('home');");
$requestPosition = strpos($lifecycle, 'const result = await api.leaveGame(String(snapshot.id));');
$assert(
    $homePosition !== false && $requestPosition !== false && $homePosition < $requestPosition
        && !str_contains($lifecycle, 'openSheet('),
    'The accepted immediate cancel-game return to the main menu must remain unchanged.'
);
$assert(
    str_contains($invites, 'tg.shareMessage(preparedId')
        && str_contains($invites, "String(errorCode || '') === 'USER_DECLINED'")
        && str_contains($invites, 'restoreWarmShareDraft(attempt);'),
    'Native editable Telegram sharing and silent cancellation reuse must remain unchanged.'
);

fwrite(STDOUT, "ProductionV110R12FinalReleaseContractTest: {$assertions} assertions passed\n");
