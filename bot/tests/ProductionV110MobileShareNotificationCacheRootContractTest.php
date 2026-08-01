<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read R9 source: ' . $path);
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

$assert(str_contains($invites, 'initShareVisibilityPrewarm();')
    && str_contains($invites, 'nearestVisibleInviteTrigger()')
    && str_contains($invites, 'SHARE_PREFETCH_ROOT_MARGIN'),
    'Visible mobile invite controls must prewarm the canonical prepared message before the share tap.');
$assert(str_contains($invites, 'restoreWarmShareDraft(attempt);')
    && !str_contains($invites, "void discardDraft(attempt.invite).finally"),
    'Cancelling the native Telegram dialog must reuse the still-valid prepared draft instead of forcing another slow request.');
$assert(str_contains($invites, 'SHARE_WARM_KEEPALIVE_MS')
    && str_contains($invites, 'armWarmShareExpiry(entry)'),
    'Reusable prepared drafts must have a bounded canonical lifetime.');
$assert(str_contains($notifications, 'liveItems = loadLiveItems();')
    && str_contains($notifications, 'persistLiveItems();')
    && str_contains($notifications, 'LIVE_STORAGE_TTL_MS'),
    'Notification cards must survive mobile WebView reloads long enough for an immediate first bell paint.');
$assert(str_contains($notifications, 'if (generation !== seededSheetGeneration) return [];')
    && !str_contains($notifications, 'Date.now() > seededSheetUntil')
    && !str_contains($notifications, 'seededSheetUntil = Date.now() + 2000'),
    'The clicked toast item must remain pinned for the entire open sheet generation, regardless of mobile latency.');
$assert(str_contains($notifications, 'const immediate = mergeNotificationItems(seedItems, currentItems());'),
    'The exact tapped toast item must be part of the first rendered notification frame.');
$assert(str_contains($shell, 'game-invites-v110.js?v=1113')
    && str_contains($shell, 'notifications-screen-v110r5.js?v=1113')
    && str_contains($entry, 'main-v110.js?v=1113')
    && str_contains($launch, '/app/v110.php?v=1113'),
    'All active mobile entry owners must load the R9 cache-busted build.');

fwrite(STDOUT, "ProductionV110MobileShareNotificationCacheRootContractTest: {$assertions} assertions passed\n");
