<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/games/invite-terminal-actions-v110r12.js');
if (!is_string($source)) throw new RuntimeException('Cannot read terminal invite owner.');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$tryStart = strpos($source, '  try {');
$catchStart = strpos($source, '  } catch (error) {', $tryStart ?: 0);
$finallyStart = strpos($source, '  } finally {', $catchStart ?: 0);
$successBlock = $tryStart !== false && $catchStart !== false
    ? substr($source, $tryStart, $catchStart - $tryStart)
    : '';
$failureBlock = $catchStart !== false && $finallyStart !== false
    ? substr($source, $catchStart, $finallyStart - $catchStart)
    : '';

$assert($successBlock !== '' && $failureBlock !== '', 'Terminal success and failure blocks must be identifiable.');
$assert(
    str_contains($successBlock, "new CustomEvent('mgw:notification-count'")
        && str_contains($successBlock, "new CustomEvent('mgw:game-dismissed'")
        && !str_contains($successBlock, "new CustomEvent('mgw:notifications-refresh'"),
    'Successful decline/cancel must use exact local removal and unread count without starting a stale notification request.'
);
$assert(
    str_contains($failureBlock, "new CustomEvent('mgw:notifications-refresh'")
        && str_contains($failureBlock, "toast(error?.message || 'Не удалось изменить приглашение.')"),
    'A failed terminal request must still refresh and restore authoritative notification state.'
);
$assert(
    strpos($source, "new CustomEvent('mgw:notification-remove'")
        < strpos($source, 'const result = await inviteRequest(action, token);'),
    'The actor card must still disappear before the network request.'
);

fwrite(STDOUT, "ProductionV110TerminalSuccessRefreshRaceContractTest: {$assertions} assertions passed\n");
