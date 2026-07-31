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
$auth = $read('bot/services/AuthService.php');
$api = $read('bot/api.php');
$profile = $read('app/assets/js/screens/profile-screen-v110.js');
$legacyProfile = $read('app/assets/js/screens/profile-screen.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r5.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$main = $read('app/assets/js/main-v110.js');
$clean = $read('app/assets/js/production-clean-entry-v110.js');
$entry = $read('app/v110.php');
$launch = $read('bot/helpers/WebAppLaunchUrl.php');

$assert(!str_contains($presence, 'sys_get_temp_dir'), 'Presence must not use worker-local temporary storage.');
$assert(str_contains($presence, "\$GLOBALS['config']['data_dir']")
    && str_contains($presence, "'.runtime'")
    && str_contains($presence, "'presence'"),
    'Every presence reader and writer must derive one configured shared runtime root.');
$assert(!str_contains($auth, 'touchAuthenticatedPresence')
    && str_contains($api, "\$action === 'bootstrap'")
    && str_contains($api, '$presenceService->touch('),
    'Successful normal and invitation launches must confirm presence only through authenticated bootstrap.');
$assert(str_contains($api, 'Mini Games World bootstrap presence failed:'),
    'A presence storage failure must never become an authentication failure.');

$assert(!str_contains($shell, 'NotificationPreflight')
    && substr_count($shell, 'initNotificationsScreen();') === 1
    && str_contains($shell, 'notifications-screen-v110r5.js?v=1110'),
    'The active graph must have exactly one notification owner and no click interceptor.');
$assert(!str_contains($clean, 'initV109SelfCancelRefreshGuard'),
    'The inactive v109 self-cancel overlay guard must not remain in the active graph.');
$assert(str_contains($notifications, "event.target.closest('#notificationsOpen')")
    && str_contains($notifications, 'void openNotificationsSheet(currentItems());')
    && str_contains($notifications, 'isCurrentNotificationsSheet(generation)'),
    'The notification owner must open immediately and reject late rendering into a closed sheet.');

$assert(str_contains($profile, "PROFILE_STATS_CACHE_KEY = 'mgw_profile_stats_v1'")
    && str_contains($profile, 'renderProfileStats(state.profileStats || null);')
    && !str_contains($legacyProfile, 'PROFILE_STATS_CACHE_KEY'),
    'The accepted isolated profile first frame must remain unchanged.');

$build = 'v110-mvp14r5-presence-invite-resume-root';
$assert(str_contains($shell, $build)
    && str_contains($main, $build)
    && str_contains($clean, $build)
    && str_contains($entry, $build),
    'Every active v110 entry owner must share the same build identity.');
$assert(str_contains($main, 'main-v110-handoff-shell.js?v=1110')
    && str_contains($entry, 'production-clean-entry-v110.js?v=1110')
    && str_contains($entry, 'main-v110.js?v=1110')
    && str_contains($launch, '/app/v110.php?v=1110'),
    'Telegram launch and active modules must use the fresh outer revision.');

fwrite(STDOUT, 'ProductionV110InvitePresenceNotificationProfileRootContractTest: ' . $assertions . " assertions passed\n");
