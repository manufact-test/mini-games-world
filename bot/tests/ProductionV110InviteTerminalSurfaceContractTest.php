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

$historicalActions = $read('app/assets/js/games/invite-terminal-actions-v110r12.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');

$assert(
    str_contains($historicalActions, "window.addEventListener('click', handleTerminalAction, true)")
        && !str_contains($shell, 'invite-terminal-actions-v110r12.js')
        && !str_contains($shell, 'initInviteTerminalActions'),
    'The historical capture owner may remain for rollback evidence but must be unreachable from the active graph.'
);

$performStart = strpos($invites, 'async function performInviteAction(');
$performEnd = strpos($invites, 'async function createRematch(', $performStart ?: 0);
$perform = $performStart !== false && $performEnd !== false
    ? substr($invites, $performStart, $performEnd - $performStart)
    : '';
$assert(
    $perform !== ''
        && !str_contains($perform, "action === 'decline' || action === 'cancel') {\n    closeSheet();")
        && str_contains($perform, 'const terminalContext = terminalActionContext(button, action, token);')
        && str_contains($perform, 'const terminalInvite = terminalInviteResult('),
    'Decline and cancel must remain on the current surface while one authoritative request completes.'
);

$assert(
    str_contains($invites, "card.closest('#sheet')?.querySelector('[data-notifications-owner=\"r12\"]')")
        && str_contains($invites, "new CustomEvent('mgw:notification-sync'")
        && str_contains($invites, 'showTerminalInvite(terminalInvite);')
        && str_contains($invites, 'actions:[]')
        && str_contains($invites, 'read:true'),
    'The canonical owner must terminalize either the exact notification card or the standalone invite sheet in place.'
);

$assert(
    !str_contains($perform, "toast('Приглашение отклонено.')")
        && !str_contains($perform, "toast('Приглашение отменено.')")
        && !str_contains($perform, "new CustomEvent('mgw:notifications-refresh'")
        && str_contains($perform, 'announce:false'),
    'Actor terminal success must remain silent and must not start a stale refresh race.'
);

$assert(
    str_contains($notifications, "document.addEventListener('mgw:notification-sync'")
        && str_contains($notifications, 'rememberLocalAuthority(item);')
        && str_contains($notifications, 'pinItem(item);')
        && str_contains($notifications, 'renderNotifications(visibleSheetItems());')
        && str_contains($notifications, 'data-notification-type="${escapeHtml(item.type)}"'),
    'The existing notification owner must preserve exact identity and redraw the terminal card without duplication.'
);

$assert(
    substr_count($shell, 'initGameInvites();') === 1
        && substr_count($shell, 'initNotificationsScreen();') === 1,
    'The active graph must contain exactly one invitation action owner and one notification renderer.'
);

fwrite(STDOUT, "ProductionV110InviteTerminalSurfaceContractTest: {$assertions} assertions passed\n");
