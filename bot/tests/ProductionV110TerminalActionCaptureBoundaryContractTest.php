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

$terminal = $read('app/assets/js/games/invite-terminal-actions-v110r12.js');
$legacy = $read('app/assets/js/games/game-invites-v110.js');

$assert(
    str_contains($terminal, "window.addEventListener('click', handleTerminalAction, true)")
        && str_contains($legacy, "document.addEventListener('click', handleDocumentClick, true)"),
    'The terminal owner must observe decline/cancel before the broader document invite handler.'
);
$assert(
    str_contains($terminal, 'event.preventDefault();')
        && str_contains($terminal, 'event.stopImmediatePropagation();'),
    'The terminal owner must consume the event before it reaches document listeners.'
);
$assert(
    !str_contains($terminal, "toast('Приглашение отклонено")
        && !str_contains($terminal, "toast('Приглашение отменено")
        && str_contains($terminal, "toast(error?.message || 'Не удалось изменить приглашение.')"),
    'Only a real failure may produce an actor-side toast.'
);
$assert(
    str_contains($terminal, 'closeSheet();')
        && str_contains($terminal, "new CustomEvent('mgw:notification-remove'")
        && strpos($terminal, 'closeSheet();') < strpos($terminal, 'const result = await inviteRequest(action, token);'),
    'Decline/cancel must close and remove the card before the network response.'
);

fwrite(STDOUT, "ProductionV110TerminalActionCaptureBoundaryContractTest: {$assertions} assertions passed\n");
