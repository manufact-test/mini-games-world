<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read production v110 source: ' . $path);
    return $content;
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$presence = $read('bot/services/PresenceService.php');
$signals = $read('bot/services/InviteSignalService.php');
$preflight = $read('app/assets/js/production-v110-notification-preflight.js');
$profile = $read('app/assets/js/screens/profile-screen.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$main = $read('app/assets/js/main-v110.js');
$entry = $read('app/v110.php');
$launch = $read('bot/helpers/WebAppLaunchUrl.php');

$assert(!str_contains($presence, 'sys_get_temp_dir'), 'Presence must not depend on a worker-local system temp directory.');
$assert(str_contains($presence, "/data/.runtime/presence"), 'Presence must use shared runtime storage beside JSON data.');
$assert(!str_contains($signals, 'sys_get_temp_dir'), 'Invite signals must not depend on a worker-local system temp directory.');
$assert(str_contains($signals, "'.runtime'"), 'Invite signals must use the shared runtime directory.');

$preflightPosition = strpos($shell, 'initV110NotificationPreflight();');
$ownerPosition = strpos($shell, 'initNotificationsScreen();');
$assert($preflightPosition !== false && $ownerPosition !== false && $preflightPosition < $ownerPosition,
    'Notification preflight must register before the single notification owner.');
$assert(str_contains($preflight, "#notificationToast, #notificationsOpen"), 'Preflight must cover both the blue toast and bell button.');
$assert(str_contains($preflight, "primeAndOpen(target.id === 'notificationToast')"), 'A visible blue toast must require a real notification item before opening.');
$assert(str_contains($preflight, "mgw:notification-sync"), 'Preflight must feed items to the existing notification owner.');
$assert(!str_contains($preflight, 'openSheet'), 'Preflight must not become a second notification renderer.');

$assert(str_contains($profile, "PROFILE_STATS_CACHE_KEY = 'mgw_profile_stats_v1'"), 'Profile must keep last trustworthy stats for first-frame rendering.');
$assert(str_contains($profile, "renderProfileStats(state.profileStats || null);"), 'Profile cards must be rendered during initialization.');
$assert(str_contains($profile, "ready ? Number(stats[key]) : '—'"), 'Profile must reserve all four statistic cards before fresh data arrives.');
$assert(str_contains($profile, 'saveCachedProfileStats(state.profileStats)'), 'Fresh profile statistics must refresh the first-frame cache.');

$build = 'v110-mvp14r3-invite-presence-notification-profile-root';
$assert(str_contains($shell, $build) && str_contains($main, $build) && str_contains($entry, $build),
    'Active shell, main and PHP entry must share the same build identity.');
$assert(str_contains($main, "main-v110-handoff-shell.js?v=1108"), 'Main must load the fresh v110 shell revision.');
$assert(str_contains($entry, "production-clean-entry-v110.js?v=1108") && str_contains($entry, "main-v110.js?v=1108"),
    'PHP entry must load only fresh v110 assets.');
$assert(str_contains($launch, "/app/v110.php?v=1108"), 'Every Telegram launch path must use the fresh outer revision.');

fwrite(STDOUT, 'ProductionV110InvitePresenceNotificationProfileRootContractTest: ' . $assertions . " assertions passed\n");
