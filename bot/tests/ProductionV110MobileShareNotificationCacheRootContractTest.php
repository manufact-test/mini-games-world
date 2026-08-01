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
$launch = $read('bot/helpers/WebAppLaunchUrl.php');

$assert(!str_contains($invites, 'initShareVisibilityPrewarm();')
    && !str_contains($invites, 'nearestVisibleInviteTrigger()')
    && !str_contains($invites, 'SHARE_PREFETCH_ROOT_MARGIN')
    && str_contains($invites, "if (!shareAttempt?.nativePending) cancelWarmShareDraft();"),
    'Player selection must not compete with page-level share prewarm.');
$assert(str_contains($invites, 'restoreWarmShareDraft(attempt);')
    && !str_contains($invites, 'void discardDraft(attempt.invite).finally')
    && str_contains($invites, 'SHARE_WARM_KEEPALIVE_MS')
    && str_contains($invites, 'armWarmShareExpiry(entry)'),
    'Native cancellation must reuse a bounded prepared draft.');
$assert(str_contains($notifications, "document.addEventListener('mgw:app-ready'")
    && str_contains($notifications, 'hydrateItems();')
    && str_contains($notifications, 'CACHE_TTL_MS = 900000'),
    'Notification cache must hydrate only after authenticated app readiness.');
$assert(str_contains($notifications, 'const immediate = mergeNotificationItems(seed, currentItems());')
    && str_contains($notifications, 'sheetState.pinned')
    && str_contains($notifications, 'renderNotifications(immediate);'),
    'Bell and toast opens must pin exact known items into the first frame.');
$assert(str_contains($notifications, 'CLOSE_GUARD_MS = 1100')
    && str_contains($notifications, 'announcementGuardUntil')
    && str_contains($notifications, 'markVisibleReadLocally();')
    && str_contains($notifications, 'renderLoading();'),
    'Closing the sheet must not re-announce or reopen it, and unknown data must not flash empty.');
$assert(str_contains($shell, 'game-invites-v110.js?v=1114')
    && str_contains($shell, 'notifications-screen-v110r12.js?v=1118')
    && !str_contains($shell, 'notifications-screen-v110r5.js')
    && str_contains($entry, 'main-v110.js?v=1118')
    && str_contains($launch, '/app/v110.php?v=1118'),
    'Current invitation and notification owners must load through the final entrypoint.');

fwrite(STDOUT, "ProductionV110MobileShareNotificationCacheRootContractTest: {$assertions} assertions passed\n");
