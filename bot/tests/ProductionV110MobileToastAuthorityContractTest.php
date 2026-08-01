<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$notifications = $read('app/assets/js/screens/notifications-screen-v110r5.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$entry = $read('app/v110.php');

$assert(
    str_contains($notifications, "if (Date.now() < suppressToastClickUntil) return;\n    void openNotificationsSheet(currentItems());"),
    'The bell owner must reject the mobile ghost click immediately after a notification sheet closes.'
);
$assert(
    str_contains($notifications, 'if (notificationSheetActive')
        && str_contains($notifications, 'authorityRevision !== notificationAuthorityRevision')
        && str_contains($notifications, 'Date.now() < localAuthorityUntil'),
    'A background badge response must not replace the authoritative list while the notification sheet is active.'
);
$assert(
    str_contains($notifications, 'notificationAuthorityRevision += 1;')
        && str_contains($notifications, 'suppressAnnouncementsUntil = Math.max(suppressAnnouncementsUntil, guardUntil);')
        && str_contains($notifications, 'void openNotificationsSheet([item], true, true);'),
    'The tapped toast item must become the authoritative first-frame seed before the sheet opens.'
);
$assert(
    str_contains($shell, 'notifications-screen-v110r5.js?v=1115')
        && str_contains($entry, 'main-v110.js?v=1115'),
    'Production must load the focused R11 notification owner.'
);

fwrite(STDOUT, "ProductionV110MobileToastAuthorityContractTest: {$assertions} assertions passed\n");
