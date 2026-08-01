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

$assert(str_contains($owner, "const TERMINAL_ACTIONS = new Set(['decline', 'cancel'])")
    && str_contains($owner, "document.addEventListener('click', handleTerminalAction, true)")
    && str_contains($owner, 'event.stopImmediatePropagation();'),
    'Decline and cancel must have one scoped owner before the legacy invite handler.');
$assert(str_contains($owner, "document.dispatchEvent(new CustomEvent('mgw:invite-action-local-result'")
    && !str_contains($owner, 'closeSheet(')
    && !str_contains($owner, "toast('Приглашение отклонено")
    && !str_contains($owner, "toast('Приглашение отменено"),
    'Successful terminal actions must update the existing card without closing the sheet or showing a self-confirmation toast.');
$assert(str_contains($notifications, "mgw:invite-action-local-result")
    && str_contains($notifications, 'applyInviteActionResult')
    && str_contains($notifications, 'actions:[]')
    && str_contains($notifications, 'renderNotifications(visibleSheetItems());'),
    'The notification owner must convert the open invite card to terminal state in place.');
$terminalInit = strpos($shell, 'initInviteTerminalActions();');
$legacyInit = strpos($shell, 'initGameInvites();');
$assert($terminalInit !== false && $legacyInit !== false && $terminalInit < $legacyInit
    && str_contains($shell, 'invite-terminal-actions-v110r12.js?v=1117'),
    'The terminal owner must initialize before the broader invite owner.');
$assert(str_contains($legacy, "if (action === 'decline') toast('Приглашение отклонено.');"),
    'The old success branch may remain only as an unreachable compatibility path behind the scoped capture owner.');

fwrite(STDOUT, "ProductionV110InviteTerminalActionsR12ContractTest: {$assertions} assertions passed\n");
