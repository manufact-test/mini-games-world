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

$invites = $read('app/assets/js/games/game-invites-v110.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$entry = $read('app/v110.php');

$assert(!str_contains($invites, 'scheduleVisibleShareWarm')
    && !str_contains($invites, 'initShareVisibilityPrewarm')
    && str_contains($invites, 'scheduleWarmShareDraft(currentContext(), 0);'),
    'Player selection must keep only the accepted share prewarm.');
$assert(str_contains($invites, 'restoreWarmShareDraft(attempt);')
    && str_contains($invites, 'armWarmShareExpiry(entry)')
    && str_contains($invites, 'tg.shareMessage(preparedId'),
    'Fast editable Telegram sharing must remain intact.');
$assert(str_contains($notifications, 'hydrateItems();')
    && str_contains($notifications, 'LOCAL_AUTHORITY_MS = 12000')
    && str_contains($notifications, 'sheetState.pinned')
    && str_contains($notifications, "openNotificationsSheet({ seed:[item], source:'toast' })"),
    'The exact notification must survive mobile response races.');
$assert(str_contains($notifications, 'CLOSE_GUARD_MS = 1100')
    && str_contains($notifications, 'armCloseGuard()')
    && str_contains($notifications, 'markVisibleReadLocally();'),
    'Closing notifications must suppress duplicate reopen.');
$assert(str_contains($notifications, 'renderLoading();')
    && str_contains($notifications, 'await refreshOpenSheet(generation);'),
    'Unknown first state must show loading instead of a false empty screen.');
$assert(str_contains($shell, 'game-invites-v110.js?v=1114')
    && str_contains($shell, 'notifications-screen-v110r12.js?v=1122')
    && !str_contains($shell, 'notifications-screen-v110r5.js')
    && str_contains($entry, 'main-v110.js?v=1123'),
    'The canonical graph must load the current owners through the final v1123 shell.');

fwrite(STDOUT, "ProductionV110MobileNotificationInviteRestoreContractTest: {$assertions} assertions passed\n");
