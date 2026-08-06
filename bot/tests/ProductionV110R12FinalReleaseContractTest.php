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
$historicalTerminal = $read('app/assets/js/games/invite-terminal-actions-v110r12.js');
$linkEntry = $read('app/assets/js/games/invite-link-entry-v110r12.js');
$notificationEndpoint = $read('bot/notifications.php');
$stats = $read('app/assets/js/stats-owner-v110.js');
$presence = $read('app/assets/js/production-v110-presence.js');
$presenceService = $read('bot/services/PresenceService.php');
$opponentEndpoint = $read('bot/invite-opponents.php');
$opponentService = $read('bot/services/InviteOpponentService.php');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$lifecycle = $read('app/assets/js/production-v110-match-lifecycle.js');
$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');
$wrapperPath = $root . '/app/assets/js/production-v110-opponent-picker-stability.js';

$build = 'v110-mvp14r12-terminal-dedup-v1133';
$assert(
    str_contains($php, 'production-clean-entry-v110.js?v=1121')
        && str_contains($php, 'main-v110.js?v=1132')
        && str_contains($php, $build)
        && str_contains($main, 'main-v110-handoff-shell.js?v=1132')
        && str_contains($main, $build)
        && str_contains($shell, 'game-invites-v110.js?v=1130')
        && str_contains($shell, $build)
        && str_contains($clean, $build),
    'Every active R12 entry owner must publish one build identity and the final v1130 shell.'
);
$assert(
    str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1123';")
        && str_contains($welcome, "Active canonical path: '/app/v110.php?v=1123'."),
    'Telegram menu, start and invitation paths must launch the same canonical R12 entrypoint.'
);
$assert(
    substr_count($shell, 'initNotificationsScreen();') === 1
        && substr_count($shell, 'initGameInvites();') === 1
        && !str_contains($shell, 'initInviteTerminalActions')
        && !str_contains($shell, 'invite-terminal-actions-v110r12.js')
        && str_contains($shell, 'notifications-screen-v110r12.js?v=1132')
        && str_contains($notifications, 'data-notifications-owner="r12"')
        && str_contains($notifications, 'sheetState.pinned'),
    'R12 must keep one notification renderer and one canonical invite action owner.'
);
$paintPosition = strpos($notifications, "if (source === 'toast') await waitForFirstSheetPaint(generation);");
$refreshPosition = strpos($notifications, 'await refreshOpenSheet(generation);', $paintPosition ?: 0);
$assert(
    str_contains($notifications, 'element.__mgwNotificationItem = cloneItem(item);')
        && str_contains($notifications, 'pressedToastItem = toastSnapshot(element);')
        && str_contains($notifications, 'pressedToastItem || toastSnapshot() || newestItem()')
        && str_contains($notifications, "openNotificationsSheet({ seed:[item], source:'toast' })")
        && $paintPosition !== false
        && $refreshPosition !== false
        && $paintPosition < $refreshPosition
        && str_contains($notifications, 'mergeServerItems(serverItems)')
        && str_contains($notifications, 'LOCAL_AUTHORITY_MS = 12000'),
    'A tapped blue toast must paint its exact immutable card before a slower server response reconciles.'
);

$performStart = strpos($invites, 'async function performInviteAction(');
$performEnd = strpos($invites, 'async function createRematch(', $performStart ?: 0);
$perform = $performStart !== false && $performEnd !== false
    ? substr($invites, $performStart, $performEnd - $performStart)
    : '';
$assert(
    str_contains($historicalTerminal, "window.addEventListener('click', handleTerminalAction, true)")
        && !str_contains($shell, 'invite-terminal-actions-v110r12.js')
        && $perform !== ''
        && !str_contains($perform, "action === 'decline' || action === 'cancel') {\n    closeSheet();")
        && !str_contains($perform, "toast('Приглашение отклонено.')")
        && !str_contains($perform, "toast('Приглашение отменено.')")
        && str_contains($perform, 'const terminalContext = terminalActionContext(button, action, token);')
        && str_contains($perform, "new CustomEvent('mgw:notification-sync'")
        && str_contains($perform, 'showTerminalInvite(terminalInvite);')
        && str_contains($perform, 'announce:false')
        && !str_contains($perform, "new CustomEvent('mgw:notifications-refresh'"),
    'Decline and cancel must keep the current surface, replace it with a terminal state and avoid actor toasts or stale refreshes.'
);
$assert(
    str_contains($invites, "card.closest('#sheet')?.querySelector('[data-notifications-owner=\"r12\"]')")
        && str_contains($invites, 'function terminalNotificationItem(')
        && str_contains($invites, 'actions:[]')
        && str_contains($notifications, "document.addEventListener('mgw:notification-sync'")
        && str_contains($notifications, 'rememberLocalAuthority(item);')
        && str_contains($notifications, 'pinItem(item);')
        && str_contains($notifications, 'renderNotifications(visibleSheetItems());')
        && str_contains($notificationEndpoint, "return in_array(\$status, ['pending', 'accepted', 'declined'], true);")
        && str_contains($notificationEndpoint, "\$item['title'] = 'Приглашение отклонено';")
        && str_contains($notificationEndpoint, "\$item['read'] = true;"),
    'The exact actor card must become read terminal history without actions, while authoritative history remains available.'
);
$assert(
    str_contains($shell, 'invite-link-entry-v110r12.js?v=1123')
        && str_contains($linkEntry, 'const invite = result?.opened_invite || null;')
        && str_contains($linkEntry, 'showIncomingInvite(invite);')
        && str_contains($linkEntry, 'data-invite-action="accept"')
        && str_contains($linkEntry, 'data-invite-action="decline"')
        && !str_contains($linkEntry, 'currentInvite ='),
    'Telegram links must open one complete invitation sheet without restoring blocking current invite state.'
);
$assert(
    !file_exists($wrapperPath)
        && !str_contains($shell, 'production-v110-opponent-picker-stability.js')
        && !str_contains($shell, 'initV110OpponentPickerStability')
        && !str_contains($shell, 'window.fetch =')
        && str_contains($invites, 'async function openPlayerPicker(context, sourceButton = null)')
        && !str_contains($invites, 'Загружаем соперников')
        && str_contains($invites, 'postJson(OPPONENTS_URL, {})'),
    'The ready-first player picker must remain inside the canonical invite owner without the retired R12 wrapper.'
);
$assert(
    str_contains($opponentEndpoint, 'new PresenceService()')
        && str_contains($opponentEndpoint, '->onlineAccountIds()')
        && str_contains($opponentEndpoint, 'new InviteOpponentService()')
        && str_contains($opponentEndpoint, 'StorageFactory::createJson(')
        && !str_contains($opponentEndpoint, 'DatabasePrimaryStateStorageAdapter')
        && str_contains($opponentService, "str_starts_with(\$candidateId, 'bot_')")
        && str_contains($opponentService, 'array_slice($result, 0, self::MAX_ITEMS)'),
    'The bounded opponent source must use shared presence, the active invite runtime and one service that excludes bots.'
);
$assert(
    str_contains($shell, 'stats-owner-v110.js?v=1121')
        && str_contains($stats, "issued:{ api:0, presence:0 }")
        && str_contains($stats, "applied:{ api:0, presence:0 }")
        && str_contains($stats, "if (owner === 'presence')")
        && str_contains($stats, "if (key === 'online_players') continue;")
        && !str_contains($stats, 'ONLINE_DROP_GRACE_MS')
        && !str_contains($stats, 'stableOnlineCount')
        && !str_contains($stats, 'pendingOnlineDrop'),
    'The online counter must be owned only by independently ordered presence responses without UI smoothing.'
);
$assert(
    str_contains($shell, 'production-v110-presence.js?v=1121')
        && substr_count($presence, "beginStatsRequest('presence')") === 2
        && str_contains($presence, 'const presenceLeaseId = createPresenceLeaseId();')
        && str_contains($presence, '// Presence transport starts before the profile bootstrap.')
        && str_contains($presenceService, 'private const LEAVE_GRACE_SEC = 12;')
        && str_contains($presenceService, '$sessionId . "\\0presence:" . $presenceLeaseId'),
    'Telegram reopen continuity and online statistics must be owned by document-scoped presence, not UI masking.'
);
$homePosition = strpos($lifecycle, "showScreen('home');");
$leavePosition = strpos($lifecycle, 'const result = await api.leaveGame(String(snapshot.id));');
$assert(
    $homePosition !== false && $leavePosition !== false && $homePosition < $leavePosition
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
