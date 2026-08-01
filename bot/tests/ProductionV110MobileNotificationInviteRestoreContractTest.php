<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read R10 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$invites = $read('app/assets/js/games/game-invites-v110.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r5.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$entry = $read('app/v110.php');

$assert(!str_contains($invites, 'scheduleVisibleShareWarm')
    && !str_contains($invites, 'initShareVisibilityPrewarm')
    && str_contains($invites, 'scheduleWarmShareDraft(currentContext(), 0);')
    && str_contains($invites, 'cancelWarmShareDraft();\n    openPlayerPicker(currentContext());'),
    'Player selection must retain the accepted setup prewarm only and cancel it before loading opponents.');
$assert(str_contains($invites, 'restoreWarmShareDraft(attempt);')
    && str_contains($invites, 'armWarmShareExpiry(entry)')
    && str_contains($invites, 'tg.shareMessage(preparedId'),
    'Fast editable Telegram sharing and instant cancellation reuse must remain intact.');
$assert(str_contains($notifications, 'hydrateLiveItems();')
    && str_contains($notifications, 'Date.now() < localAuthorityUntil')
    && str_contains($notifications, 'setSheetSeed(generation, immediate, preserveSeed || immediate.length > 0);'),
    'Authenticated cache hydration and the exact first-frame seed must survive mobile response races.');
$assert(str_contains($notifications, 'const closedNotificationsSheet = notificationSheetActive;')
    && str_contains($notifications, 'suppressToastClickUntil = Math.max')
    && str_contains($notifications, 'suppressAnnouncementsUntil = Math.max')
    && str_contains($notifications, 'markCurrentItemsReadLocally();'),
    'Closing notifications must suppress the mobile ghost click and prevent the same item from reopening the sheet.');
$assert(str_contains($notifications, 'MAX_EMPTY_SHEET_RETRIES = 4')
    && str_contains($notifications, 'renderLoading();')
    && str_contains($notifications, 'void refreshOpenSheet(generation);'),
    'Known unread data must stay in a bounded loading state instead of flashing an incorrect empty screen.');
$assert(str_contains($shell, 'game-invites-v110.js?v=1114')
    && str_contains($shell, 'notifications-screen-v110r5.js?v=1114')
    && str_contains($entry, 'main-v110.js?v=1114'),
    'The canonical production graph must load only the R10 owners.');

fwrite(STDOUT, "ProductionV110MobileNotificationInviteRestoreContractTest: {$assertions} assertions passed\n");
