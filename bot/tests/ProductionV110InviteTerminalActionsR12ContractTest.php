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

$owner = $read('app/assets/js/games/invite-terminal-actions-v110r12.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$legacy = $read('app/assets/js/games/game-invites-v110.js');
$endpoint = $read('bot/notifications.php');
$actions = $read('bot/services/invites/GameInviteActionTrait.php');

$assert(str_contains($owner, "const TERMINAL_ACTIONS = new Set(['decline', 'cancel'])")
    && str_contains($owner, "window.addEventListener('click', handleTerminalAction, true)")
    && !str_contains($owner, "document.addEventListener('click', handleTerminalAction, true)")
    && str_contains($owner, 'event.stopImmediatePropagation();'),
    'Decline and cancel must be owned at window capture before every document-level compatibility handler.');

$closePosition = strpos($owner, 'closeSheet();');
$requestPosition = strpos($owner, 'const result = await inviteRequest(action, token);');
$assert($closePosition !== false && $requestPosition !== false && $closePosition < $requestPosition
    && str_contains($owner, "new CustomEvent('mgw:notification-remove'")
    && str_contains($owner, "new CustomEvent('mgw:invite-terminal-action-started'")
    && str_contains($owner, "new CustomEvent('mgw:invite-terminal-action-completed'")
    && !str_contains($owner, 'terminalNotificationItem(')
    && !str_contains($owner, "new CustomEvent('mgw:notification-sync'")
    && !str_contains($owner, "toast('Приглашение отклонено")
    && !str_contains($owner, "toast('Приглашение отменено"),
    'Actor terminal actions must close and remove local state before the request, then finish without a success toast or confirmation card.');

$assert(str_contains($notifications, "document.addEventListener('mgw:notification-remove'")
    && str_contains($notifications, 'function removeInviteNotification(detail)')
    && str_contains($notifications, "items.delete(id)")
    && str_contains($notifications, 'localAuthority.delete(key)')
    && str_contains($notifications, 'sheetState.pinned.delete(key)')
    && !str_contains($notifications, "mgw:invite-action-local-result")
    && !str_contains($notifications, 'function applyInviteActionResult(')
    && !str_contains($notifications, 'Вы отклонили это приглашение.'),
    'The notification owner must remove the actor card from memory, pinned state and cache instead of converting it into a terminal card.');

$assert(str_contains($endpoint, "return in_array(\$status, ['pending', 'accepted'], true);")
    && !str_contains($endpoint, "\$status === 'declined'")
    && !str_contains($endpoint, "\$status === 'cancelled'"),
    'The notification endpoint must stop returning received invitation cards to the actor after decline or cancellation.');

$declineStart = strpos($actions, 'public function decline(');
$cancelStart = strpos($actions, 'public function cancel(', $declineStart ?: 0);
$declineBlock = $declineStart !== false && $cancelStart !== false
    ? substr($actions, $declineStart, $cancelStart - $declineStart)
    : '';
$assert(str_contains($declineBlock, "'invite_declined'")
    && str_contains($declineBlock, "(string)(\$invite['inviter_id'] ?? '')")
    && str_contains($declineBlock, "'Приглашение отклонено'"),
    'The inviter must still receive the authoritative server notification that the request was declined.');

$terminalInit = strpos($shell, 'initInviteTerminalActions();');
$legacyInit = strpos($shell, 'initGameInvites();');
$assert($terminalInit !== false && $legacyInit !== false && $terminalInit < $legacyInit
    && str_contains($shell, 'invite-terminal-actions-v110r12.js?v=1122'),
    'The silent terminal owner must initialize before the broader invite compatibility handler.');

$assert(str_contains($legacy, "if (action === 'decline') toast('Приглашение отклонено.');")
    && str_contains($legacy, "document.addEventListener('click', handleDocumentClick, true)"),
    'The rollback branch may retain its old success toast, but window capture must make it unreachable in the active graph.');

fwrite(STDOUT, "ProductionV110InviteTerminalActionsR12ContractTest: {$assertions} assertions passed\n");
