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
$stats = $read('app/assets/js/stats-owner-v110.js');
$presence = $read('app/assets/js/production-v110-presence.js');
$presenceService = $read('bot/services/PresenceService.php');
$opponentEndpoint = $read('bot/invite-opponents.php');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$lifecycle = $read('app/assets/js/production-v110-match-lifecycle.js');
$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');
$wrapperPath = $root . '/app/assets/js/production-v110-opponent-picker-stability.js';

$build = 'v110-mvp14r12-invite-notification-presence-stability';
$assert(
    str_contains($php, 'production-clean-entry-v110.js?v=1120')
        && str_contains($php, 'main-v110.js?v=1120')
        && str_contains($php, $build)
        && str_contains($main, 'main-v110-handoff-shell.js?v=1120')
        && str_contains($main, $build)
        && str_contains($shell, $build)
        && str_contains($clean, $build),
    'Every active R12 entry owner must publish the clean v1120 build identity.'
);
$assert(
    str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1120';")
        && str_contains($welcome, "Active canonical path: '/app/v110.php?v=1120'."),
    'Telegram menu, start and invitation paths must launch the same clean R12 entrypoint.'
);
$assert(
    substr_count($shell, 'initNotificationsScreen();') === 1
        && str_contains($shell, 'notifications-screen-v110r12.js?v=1120')
        && str_contains($notifications, 'data-notifications-owner="r12"')
        && str_contains($notifications, 'sheetState.pinned')
        && str_contains($notifications, 'CLOSE_GUARD_MS = 1100'),
    'R12 must keep one notification owner with pinned first-frame data and duplicate-open protection.'
);
$assert(
    str_contains($notifications, 'pressedToastItem = toastItem ? cloneItem(toastItem) : newestItem();')
        && str_contains($notifications, "openNotificationsSheet({ seed:[item], source:'toast' })")
        && str_contains($notifications, 'mergeServerItems(serverItems)')
        && str_contains($notifications, 'LOCAL_AUTHORITY_MS = 12000'),
    'A tapped blue toast must remain visible while a slower server response reconciles.'
);
$assert(
    str_contains($shell, 'invite-terminal-actions-v110r12.js?v=1120')
        && str_contains($terminal, 'const notificationSurface = isNotificationSurface(button);')
        && str_contains($terminal, 'if (notificationSurface) {')
        && str_contains($terminal, 'closeSheet();')
        && !str_contains($terminal, 'Вы отклонили это приглашение.')
        && !str_contains($terminal, 'Вы отменили это приглашение.'),
    'Notification-card terminal actions must stay in place while standalone invitation sheets close silently.'
);
$assert(
    !file_exists($wrapperPath)
        && !str_contains($shell, 'production-v110-opponent-picker-stability.js')
        && !str_contains($shell, 'initV110OpponentPickerStability')
        && !str_contains($shell, 'window.fetch =')
        && str_contains($invites, 'async function openPlayerPicker(context)')
        && str_contains($invites, 'postJson(OPPONENTS_URL, {})'),
    'The player picker must remain inside the canonical invite owner without a global fetch wrapper.'
);
$assert(
    str_contains($opponentEndpoint, 'new PresenceService()')
        && str_contains($opponentEndpoint, 'onlineAccountIds()')
        && str_contains($opponentEndpoint, 'array_slice($result, 0, 10)')
        && str_contains($opponentEndpoint, 'str_starts_with($candidateId, \'bot_\')'),
    'The bounded opponent source must use shared presence and exclude bots.'
);
$assert(
    str_contains($shell, 'stats-owner-v110.js?v=1120')
        && str_contains($stats, 'if (sequence < runtime.applied) return false;')
        && str_contains($stats, 'state.stats = { ...stats };')
        && !str_contains($stats, 'ONLINE_DROP_GRACE_MS')
        && !str_contains($stats, 'stableOnlineCount')
        && !str_contains($stats, 'pendingOnlineDrop'),
    'The online counter must render the newest authoritative snapshot without UI smoothing.'
);
$assert(
    str_contains($shell, 'production-v110-presence.js?v=1120')
        && str_contains($presence, 'const presenceLeaseId = createPresenceLeaseId();')
        && str_contains($presence, '// Presence transport starts before the profile bootstrap.')
        && str_contains($presenceService, 'private const LEAVE_GRACE_SEC = 12;')
        && str_contains($presenceService, '$sessionId . "\\0presence:" . $presenceLeaseId'),
    'Telegram reopen continuity must be owned by document-scoped presence leases, not by UI masking.'
);
$homePosition = strpos($lifecycle, "showScreen('home');");
$requestPosition = strpos($lifecycle, 'const result = await api.leaveGame(String(snapshot.id));');
$assert(
    $homePosition !== false && $requestPosition !== false && $homePosition < $requestPosition
        && !str_contains($lifecycle, 'openSheet('),
    'Immediate manual game cancellation must still return to the main menu before the network response.'
);
$assert(
    str_contains($invites, 'tg.shareMessage(preparedId')
        && str_contains($invites, "String(errorCode || '') === 'USER_DECLINED'")
        && str_contains($invites, 'restoreWarmShareDraft(attempt);'),
    'Native editable Telegram sharing and silent cancellation reuse must remain unchanged.'
);

fwrite(STDOUT, "ProductionV110R12FinalReleaseContractTest: {$assertions} assertions passed\n");
