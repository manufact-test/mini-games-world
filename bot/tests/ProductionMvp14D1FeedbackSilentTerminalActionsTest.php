<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$main = file_get_contents($root . '/app/assets/js/main.js');
$terminal = file_get_contents($root . '/app/assets/js/games/invite-terminal-actions-v115.js');
if (!is_string($main) || !is_string($terminal)) {
    throw new RuntimeException('Missing silent terminal action sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$terminalInit = strpos($main, 'initInviteTerminalActions();');
$inviteInit = strpos($main, 'initGameInvites();');
$assert(
    str_contains($main, "import { initInviteTerminalActions } from './games/invite-terminal-actions-v115.js?v=115';")
        && $terminalInit !== false
        && $inviteInit !== false
        && $terminalInit < $inviteInit,
    'Silent decline/cancel must own the earliest capture boundary before the broad invite coordinator.'
);

$assert(
    str_contains($terminal, "window.addEventListener('click', handleTerminalAction, true);")
        && str_contains($terminal, "const TERMINAL_ACTIONS = new Set(['decline', 'cancel']);")
        && str_contains($terminal, 'event.stopImmediatePropagation();'),
    'Only decline and cancel must be intercepted before legacy compatibility handlers.'
);

$assert(
    str_contains($terminal, 'closeSheet();')
        && str_contains($terminal, "new CustomEvent('mgw:notification-remove'")
        && str_contains($terminal, "new CustomEvent('mgw:game-dismissed')"),
    'A successful terminal action must close immediately, remove the local card and return to the menu.'
);

$assert(
    !str_contains($terminal, "toast('Приглашение отклонено")
        && !str_contains($terminal, "toast('Приглашение отменено")
        && !str_contains($terminal, 'Понятно')
        && !str_contains($terminal, 'showTerminalInvite')
        && !str_contains($terminal, 'mgw:notifications-refresh')); // failure refresh checked separately below

$successBlockStart = strpos($terminal, 'try {');
$catchStart = strpos($terminal, '} catch (error) {');
$successBlock = $successBlockStart !== false && $catchStart !== false
    ? substr($terminal, $successBlockStart, $catchStart - $successBlockStart)
    : '';
$assert(
    $successBlock !== ''
        && !str_contains($successBlock, 'toast(')
        && !str_contains($successBlock, 'mgw:notifications-refresh'),
    'Success must not show an actor confirmation toast or trigger a stale notification repaint.'
);

$assert(
    str_contains($terminal, "new CustomEvent('mgw:invite-terminal-action-failed'")
        && str_contains($terminal, "new CustomEvent('mgw:notifications-refresh'")
        && str_contains($terminal, "toast(error?.message || 'Не удалось изменить приглашение.')"),
    'Only failure may restore the authoritative pending invitation and show an error.'
);

fwrite(STDOUT, "ProductionMvp14D1FeedbackSilentTerminalActionsTest: {$assertions} assertions passed\n");
