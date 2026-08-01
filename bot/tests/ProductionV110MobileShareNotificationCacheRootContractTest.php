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
$launch = $read('bot/helpers/WebAppLaunchUrl.php');

$assert(!str_contains($invites, 'initShareVisibilityPrewarm();')
    && !str_contains($invites, 'nearestVisibleInviteTrigger()')
    && !str_contains($invites, 'SHARE_PREFETCH_ROOT_MARGIN')
    && str_contains($invites, "if (!shareAttempt?.nativePending) cancelWarmShareDraft();"),
    'Direct-player selection must not compete with a speculative page-level prepared-message request.');
$assert(str_contains($invites, 'restoreWarmShareDraft(attempt);')
    && !str_contains($invites, "void discardDraft(attempt.invite).finally"),
    'Cancelling the native Telegram dialog must reuse the still-valid prepared draft instead of forcing another slow request.');
$assert(str_contains($invites, 'SHARE_WARM_KEEPALIVE_MS')
    && str_contains($invites, 'armWarmShareExpiry(entry)'),
    'Reusable prepared drafts must have a bounded canonical lifetime.');
$assert(str_contains($notifications, "document.addEventListener('mgw:app-ready'")
    && str_contains($notifications, 'hydrateLiveItems();')
    && !str_contains($notifications, 'initialized = true;\n  liveItems = loadLiveItems();'),
    'The mobile notification cache must hydrate only after the authenticated app identity is ready.');
$assert(str_contains($notifications, 'setSheetSeed(generation, immediate, preserveSeed || immediate.length > 0);')
    && str_contains($notifications, 'const immediate = mergeNotificationItems(seedItems, currentItems());'),
    'Bell and toast opens must pin their exact known items into the first rendered frame.');
$assert(str_contains($notifications, 'let notificationSheetActive = false;')
    && str_contains($notifications, 'suppressAnnouncementsUntil')
    && str_contains($notifications, 'markCurrentItemsReadLocally();')
    && str_contains($notifications, 'MAX_EMPTY_SHEET_RETRIES'),
    'Closing the mobile notification sheet must not re-announce or touch-through reopen it, and known unread data must not flash as empty.');
$assert(str_contains($shell, 'game-invites-v110.js?v=1114')
    && str_contains($shell, 'notifications-screen-v110r5.js?v=1114')
    && str_contains($entry, 'main-v110.js?v=1114')
    && str_contains($launch, '/app/v110.php?v=1114'),
    'All active mobile entry owners must load the R10 cache-busted build.');

fwrite(STDOUT, "ProductionV110MobileShareNotificationCacheRootContractTest: {$assertions} assertions passed\n");