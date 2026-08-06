<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/screens/notifications-screen-v110r12.js');
if (!is_string($source)) {
    throw new RuntimeException('Cannot read canonical notification screen.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($source, "if (!message) return terminalNotificationFallback(item);"),
    'Empty terminal notification copy must be completed by the canonical renderer.');
$assert(str_contains($source, "? 'Вы отменили своё приглашение.'"),
    'Owner cancellation must explain that the user cancelled their own invitation.');
$assert(str_contains($source, ": 'Вы отменили участие в приглашении.'"),
    'Invitee cancellation must explain that the user cancelled participation.');
$assert(str_contains($source, "if (status === 'declined') return 'Вы отклонили приглашение.';"),
    'Decline terminal cards must retain explanatory copy.');
$assert(!str_contains($source, "message:'Вы отменили своё приглашение.'"),
    'The copy must stay in the existing renderer rather than creating a second event owner.');

fwrite(STDOUT, 'ProductionMvp14NotificationTerminalCopyContractTest: '
    . $assertions . " assertions passed\n");
