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
$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');
$stats = $read('app/assets/js/stats-owner-v110.js');
$presence = $read('app/assets/js/production-v110-presence.js');
$historicalTerminal = $read('app/assets/js/games/invite-terminal-actions-v110r12.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$linkEntry = $read('app/assets/js/games/invite-link-entry-v110r12.js');
$inviteCreation = $read('bot/services/invites/GameInviteCreationTrait.php');
$inviteWatch = $read('bot/invite-watch.php');
$inviteEndpoint = $read('bot/invites.php');
$inviteService = $read('bot/services/GameInviteService.php');

$assert(
    str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1123';")
        && str_contains($welcome, "Active canonical path: '/app/v110.php?v=1123'.")
        && str_contains($php, 'main-v110.js?v=1133')
        && str_contains($main, 'main-v110-handoff-shell.js?v=1133')
        && str_contains($shell, 'game-invites-v110.js?v=1133')
        && str_contains($shell, 'notifications-screen-v110r12.js?v=1133')
        && !str_contains($shell, 'invite-terminal-actions-v110r12.js')
        && !str_contains($shell, 'initInviteTerminalActions')
        && str_contains($shell, 'invite-link-entry-v110r12.js?v=1123')
        && str_contains($shell, 'production-v110-presence.js?v=1121')
        && str_contains($shell, 'stats-owner-v110.js?v=1121'),
    'The canonical Telegram URL must publish the v1130 graph with one invite owner and the validated notification, link and presence owners.'
);

$assert(
    str_contains($stats, "issued:{ api:0, presence:0 }")
        && str_contains($stats, "applied:{ api:0, presence:0 }")
        && str_contains($stats, "if (owner === 'presence')")
        && str_contains($stats, "if (key === 'online_players') continue;")
        && substr_count($presence, "beginStatsRequest('presence')") === 2
        && !str_contains($stats, 'ONLINE_DROP_GRACE_MS')
        && !str_contains($stats, 'stableOnlineCount'),
    'Only independently ordered presence responses may update online_players, without UI smoothing.'
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
        && str_contains($perform, "new CustomEvent('mgw:notification-sync'")
        && str_contains($perform, 'showTerminalInvite(terminalInvite);')
        && str_contains($perform, 'announce:false')
        && !str_contains($perform, "new CustomEvent('mgw:notifications-refresh'"),
    'Decline/cancel must stay in the canonical owner, terminalize the current surface and avoid actor confirmation or stale refresh.'
);

$assert(
    str_contains($notifications, "document.addEventListener('mgw:notification-sync'")
        && str_contains($notifications, 'rememberLocalAuthority(item);')
        && str_contains($notifications, 'pinItem(item);')
        && str_contains($notifications, 'renderNotifications(visibleSheetItems());')
        && str_contains($notifications, 'data-notification-type="${escapeHtml(item.type)}"'),
    'The notification owner must preserve and replace the exact terminal card in place.'
);

$openLinkStart = strpos($inviteEndpoint, "case 'open_link':");
$openLinkEnd = strpos($inviteEndpoint, "case 'sync':", $openLinkStart ?: 0);
$openLinkBlock = $openLinkStart !== false && $openLinkEnd !== false
    ? substr($inviteEndpoint, $openLinkStart, $openLinkEnd - $openLinkStart)
    : '';
$assert(
    str_contains($inviteCreation, 'if ($this->isNotificationOnlyPendingInvite($activeInvite)) $activeInvite = null;')
        && str_contains($inviteCreation, 'if ($this->isNotificationOnlyPendingInvite($candidate))')
        && str_contains($inviteCreation, '$openedInvite = $candidate;')
        && str_contains($inviteCreation, '$trackedInvite = $candidate;')
        && str_contains($inviteCreation, "'opened_invite' => \$openedInvite")
        && str_contains($inviteWatch, "'invite' => null")
        && str_contains($openLinkBlock, '$core = $invites->sync($data, $user, $token);')
        && !str_contains($openLinkBlock, '$core[\'invite\'] = $boundInvite;')
        && str_contains($linkEntry, 'const invite = result?.opened_invite || null;')
        && str_contains($linkEntry, 'showIncomingInvite(invite);')
        && !str_contains($linkEntry, 'currentInvite ='),
    'A received pending invitation must remain notification-only while Telegram renders one complete non-blocking opened_invite sheet.'
);

$paintPosition = strpos($notifications, "if (source === 'toast') await waitForFirstSheetPaint(generation);");
$refreshPosition = strpos($notifications, 'await refreshOpenSheet(generation);', $paintPosition ?: 0);
$assert(
    str_contains($inviteService, "'invite_status' => \$this->liveInviteStatus(\$invite)")
        && str_contains($inviteService, "'actions' => \$this->liveInviteActions(\$invite, \$userId)")
        && str_contains($notifications, 'element.__mgwNotificationItem = cloneItem(item);')
        && str_contains($notifications, 'pressedToastItem = toastSnapshot(element);')
        && str_contains($notifications, 'pressedToastItem || toastSnapshot() || newestItem()')
        && $paintPosition !== false && $refreshPosition !== false && $paintPosition < $refreshPosition
        && str_contains($notifications, 'item.actions = completeInviteActions(item);'),
    'The mobile toast must open from one complete immutable invite snapshot and paint before background reconciliation.'
);

$assert(
    !str_contains($shell, 'window.fetch =')
        && !str_contains($shell, 'production-v110-opponent-picker-stability.js')
        && !str_contains($stats, 'pendingOnlineDrop'),
    'The final publication must not restore global request interception or online masking layers.'
);

fwrite(STDOUT, "ProductionV110R12AcceptedRegressionPublicationContractTest: {$assertions} assertions passed\n");
