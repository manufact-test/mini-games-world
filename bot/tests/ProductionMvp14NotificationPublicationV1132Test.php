<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$rendered = shell_exec('php ' . escapeshellarg($root . '/app/v110.php'));
$main = file_get_contents($root . '/app/assets/js/main-v110.js');
$shell = file_get_contents($root . '/app/assets/js/main-v110-handoff-shell.js');
$screen = file_get_contents($root . '/app/assets/js/screens/notifications-screen-v110r12.js');
if (!is_string($rendered) || !is_string($main) || !is_string($shell) || !is_string($screen)) {
    throw new RuntimeException('Cannot read notification publication graph.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($rendered, './assets/js/main-v110.js?v=1132'),
    'Rendered ordinary v110 entry must publish fresh main v1132.');
$assert(!str_contains($rendered, './assets/js/main-v110.js?v=1132'),
    'Rendered ordinary v110 entry must not retain the stale main identity.');
$assert(str_contains($main, "./main-v110-handoff-shell.js?v=1132"),
    'Fresh main must publish fresh handoff shell v1132.');
$assert(!str_contains($main, "./main-v110-handoff-shell.js?v=1132"),
    'Fresh main must not retain the stale shell identity.');
$assert(str_contains($shell, "./screens/notifications-screen-v110r12.js?v=1132"),
    'Fresh shell must publish the corrected notification owner under v1132.');
$assert(!str_contains($shell, "./screens/notifications-screen-v110r12.js?v=1132"),
    'Fresh shell must not retain the CDN-stale notification identity.');
$assert(str_contains($screen, 'let notificationReadGeneration = 0;')
    && str_contains($screen, "? 'Вы отменили своё приглашение.'"),
    'Published notification owner must contain both accepted corrections.');
$assert(str_contains($rendered, 'v110-mvp14r12-notification-publication-v1132'),
    'Rendered build marker must expose the fresh notification publication.');

fwrite(STDOUT, 'ProductionMvp14NotificationPublicationV1132Test: '
    . $assertions . " assertions passed\n");
