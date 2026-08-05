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

$owner = $read('app/assets/js/games/game-invites-v110.js');
$retired = $read('app/assets/js/games/invite-terminal-actions-v110r12.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$entry = $read('app/v110.php');
$actions = $read('bot/services/invites/GameInviteActionTrait.php');

$assert(str_contains($owner, "document.addEventListener('click', handleDocumentClick, true)")
    && str_contains($owner, "const actionButton = event.target.closest('[data-invite-action]')")
    && str_contains($owner, 'performInviteAction('),
    'All invite actions must enter the single canonical game-invites owner.');

$assert(!str_contains($shell, 'initInviteTerminalActions')
    && !str_contains($shell, 'invite-terminal-actions-v110r12.js')
    && str_contains($shell, 'game-invites-v110.js?v=1130'),
    'The old window-capture terminal interceptor must be absent from the active graph.');

$performStart = strpos($owner, 'async function performInviteAction(');
$performEnd = strpos($owner, 'async function createRematch(', $performStart ?: 0);
$perform = $performStart !== false && $performEnd !== false
    ? substr($owner, $performStart, $performEnd - $performStart)
    : '';
$assert($perform !== ''
    && !str_contains($perform, "if (action === 'decline') toast('Приглашение отклонено.')")
    && !str_contains($perform, "action === 'decline' || action === 'cancel') {\n    closeSheet();")
    && str_contains($perform, "new CustomEvent('mgw:notification-sync'")
    && str_contains($perform, 'announce:false')
    && str_contains($perform, 'showTerminalInvite(terminalInvite);'),
    'Decline/cancel must keep the sheet open and replace the current surface without a self-toast.');

$assert(str_contains($owner, 'function terminalActionContext(')
    && str_contains($owner, "card.closest('#sheet')?.querySelector('[data-notifications-owner=\"r12\"]')")
    && !str_contains($owner, "button.closest('[data-notifications-owner=\"r12\"]')")
    && str_contains($owner, 'data-notification-type')
    && str_contains($owner, 'function terminalNotificationItem(')
    && str_contains($owner, 'actions:[]')
    && str_contains($owner, "message:''"),
    'The canonical owner must preserve exact notification identity and resolve the owner from the active sheet.');

$assert(str_contains($notifications, 'data-notification-type="${escapeHtml(item.type)}"')
    && str_contains($notifications, "document.addEventListener('mgw:notification-sync'")
    && str_contains($notifications, 'pinItem(item);')
    && str_contains($notifications, 'renderNotifications(visibleSheetItems());'),
    'The notification owner must replace the visible card in place through its existing sync contract.');

$declineStart = strpos($actions, 'public function decline(');
$cancelStart = strpos($actions, 'public function cancel(', $declineStart ?: 0);
$declineBlock = $declineStart !== false && $cancelStart !== false
    ? substr($actions, $declineStart, $cancelStart - $declineStart)
    : '';
$assert(str_contains($declineBlock, "'invite_declined'")
    && str_contains($declineBlock, "'Приглашение отклонено'"),
    'The other participant must still receive the authoritative terminal notification.');

$assert(str_contains($retired, "window.addEventListener('click', handleTerminalAction, true)")
    && !str_contains($shell, 'invite-terminal-actions-v110r12.js'),
    'The historical file may remain for rollback evidence but must not execute.');

$assert(str_contains($entry, 'main-v110.js?v=1130')
    && str_contains($shell, 'game-invites-v110.js?v=1130'),
    'The D2 correction must be published through the v1130 ordinary Start graph.');

fwrite(STDOUT, "ProductionV110InviteTerminalActionsR12ContractTest: {$assertions} assertions passed\n");
