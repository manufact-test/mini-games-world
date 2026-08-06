<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/screens/notifications-screen-v110r12.js');
if (!is_string($source)) throw new RuntimeException('Cannot read canonical notification screen.');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($source, 'let notificationReadGeneration = 0;'),
    'The canonical notification owner must track issued read generations.');
$assert(substr_count($source, 'const read = beginNotificationRead();') === 2,
    'Background refresh and open-sheet refresh must both issue ordered reads.');
$assert(substr_count($source, 'if (!isLatestNotificationRead(read.generation)) return;') >= 2,
    'Stale reads must be rejected before merging or rendering.');
$assert(str_contains($source, 'promise:rawNotifications(false)'),
    'Generation ordering must wrap the existing authoritative unread-preserving request.');
$assert(str_contains($source, 'void rawNotifications(true).catch(() => null);'),
    'The separate authoritative mark-read request must remain unchanged.');
$assert(!str_contains($source, 'setTimeout(() => renderNotifications'),
    'The correction must not hide the race with a presentation timer.');

fwrite(STDOUT, 'ProductionMvp14NotificationReadGenerationContractTest: '
    . $assertions . " assertions passed\n");
