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
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');

$assert(substr_count($shell, 'initGameInvites();') === 1
    && !str_contains($shell, 'initInviteTerminalActions'),
    'D2 must have one active invite action owner.');
$assert(str_contains($owner, 'const terminalContext = terminalActionContext(button, action, token);')
    && str_contains($owner, 'const terminalInvite = terminalInviteResult('),
    'D2 must preserve the current surface and use the authoritative result.');
$assert(str_contains($owner, 'terminalContext.notificationSurface')
    && str_contains($owner, 'showTerminalInvite(terminalInvite);'),
    'Both notification-card and standalone invite-sheet surfaces must transition in place.');
$assert(str_contains($owner, "new CustomEvent('mgw:notification-sync'")
    && str_contains($owner, 'actions:[]')
    && str_contains($notifications, 'data-notification-type='),
    'The exact visible notification card must become a terminal card without duplication.');
$assert(!str_contains($owner, "if (action === 'decline') toast('Приглашение отклонено.');"),
    'The actor must not receive a self-confirmation toast.');
$assert(str_contains($shell, 'game-invites-v110.js?v=1130'),
    'D2 must publish a fresh immutable canonical owner.');

fwrite(STDOUT, "ProductionMvp14D2TerminalCardInPlaceV1130Test: {$assertions} assertions passed\n");
