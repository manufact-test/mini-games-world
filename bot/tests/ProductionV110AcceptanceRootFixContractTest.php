<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v110 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$count = static fn(string $haystack, string $needle): int => substr_count($haystack, $needle);

$entry = $read('app/assets/js/production-clean-entry-v110.js');
$main = $read('app/assets/js/main-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$runtime = $read('app/assets/js/production-v110-acceptance-runtime.js');
$targeted = $read('app/assets/js/production-v110-targeted-interactions.js');
$lifecycle = $read('app/assets/js/production-v110-match-lifecycle.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$gameInvites = $read('app/assets/js/games/game-invites-v110.js');
$sheet = $read('app/assets/js/components/sheet.js');
$presenceClient = $read('app/assets/js/production-v110-presence.js');
$presenceService = $read('bot/services/PresenceService.php');
$auth = $read('bot/services/AuthService.php');
$api = $read('bot/api.php');
$gameSync = $read('app/assets/js/production-v110-readonly-game-sync.js');
$gameWatch = $read('bot/game-watch.php');
$php = $read('app/v110.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');
$launchUrl = $read('bot/helpers/WebAppLaunchUrl.php');
$invitesEndpoint = $read('bot/invites.php');

$build = 'v110-mvp14r11-mobile-toast-authority';
$assert(str_contains($main, "import './main-v110-handoff-shell.js?v=1115';")
    && str_contains($main, $build)
    && str_contains($shell, $build)
    && str_contains($entry, $build),
    'The isolated notification task must preserve one consistent outer production build.');

$assert($count($entry, 'initV110AcceptanceRuntime();') === 1
    && $count($entry, 'initV110TargetedInteractions();') === 1
    && $count($entry, 'initV110MatchLifecycle();') === 1,
    'Retained production owners must initialize exactly once.');
$assert(!str_contains($entry, 'initV104InviteGameControls')
    && !str_contains($entry, 'initV104ResultInstant')
    && !str_contains($entry, 'initV105InviteLatency')
    && !str_contains($entry, 'initV109InviteSpeed')
    && !str_contains($entry, 'initV109SelfCancelRefreshGuard')
    && !str_contains($entry, 'initV109ShareSpeed')
    && !str_contains($entry, 'initV109ShareFallbackGuard')
    && !str_contains($entry, 'initV99InvitePickerHold'),
    'Retired invitation, share, result and self-cancel layers must remain inactive.');

$assert($count($shell, 'initGameInvites();') === 1
    && str_contains($shell, "from './games/game-invites-v110.js?v=1114'")
    && str_contains($gameInvites, "document.addEventListener('click', handleDocumentClick, true)"),
    'The canonical invitation file must remain the single invitation owner.');
$assert(str_contains($sheet, 's.replaceChildren();')
    && str_contains($sheet, "attributeFilter:['class']"),
    'A closed sheet must destroy hidden stale ownership state.');

$homePosition = strpos($lifecycle, "showScreen('home');");
$requestPosition = strpos($lifecycle, 'const result = await api.leaveGame(String(snapshot.id));');
$assert($homePosition !== false && $requestPosition !== false && $homePosition < $requestPosition
    && str_contains($lifecycle, 'queueSearchAfterRelease(startButton);')
    && !str_contains($lifecycle, 'openSheet('),
    'The accepted manual surrender home transition must remain unchanged.');
$assert(!str_contains($targeted, 'confirmLeaveGame')
    && !str_contains($targeted, 'api.leaveGame'),
    'The targeted interaction guard must not become a second surrender owner.');

$assert($count($shell, 'initNotificationsScreen();') === 1
    && str_contains($shell, 'notifications-screen-v110r12.js?v=1117')
    && !str_contains($shell, 'notifications-screen-v110r5.js')
    && !str_contains($shell, 'NotificationPreflight'),
    'The active graph must contain exactly one current notification owner.');
$assert(str_contains($notifications, 'sheetState.pinned')
    && str_contains($notifications, 'LOCAL_AUTHORITY_MS = 12000')
    && str_contains($notifications, 'CLOSE_GUARD_MS = 1100')
    && str_contains($notifications, "openNotificationsSheet({ seed:[item], source:'toast' })")
    && str_contains($notifications, 'renderLoading();')
    && str_contains($notifications, "mgw:invite-action-local-result"),
    'The notification owner must preserve the tapped item, reject stale refreshes and suppress ghost reopen.');
$assert(!str_contains($runtime, 'ownNotificationOpen')
    && !str_contains($runtime, '/bot/notifications.php'),
    'The acceptance runtime must not reintroduce a parallel notification owner.');

$assert(str_contains($presenceClient, "document.addEventListener('mgw:app-ready'")
    && str_contains($presenceClient, "window.addEventListener('pageshow'")
    && str_contains($presenceClient, 'cancelInFlightRequests()')
    && $count($shell, 'initV110Presence();') === 1,
    'One resume-aware client presence owner must remain.');
$assert(str_contains($presenceService, '$GLOBALS[\'config\'][\'data_dir\']')
    && !str_contains($auth, 'touchAuthenticatedPresence')
    && str_contains($api, "\$action === 'bootstrap'")
    && str_contains($api, '$presenceService->touch('),
    'Bootstrap and statistics must share the configured presence root.');

$assert(str_contains($gameWatch, "if (\$driver === 'json')")
    && str_contains($gameWatch, 'flock($handle, LOCK_SH)')
    && !str_contains($gameWatch, 'app.lock'),
    'The accepted lock-free PvP watch must remain unchanged.');
$assert(str_contains($gameSync, 'const WATCH_INTERVAL_MS = 250;')
    && !str_contains($gameSync, 'openSheet(')
    && !str_contains($gameSync, 'finishGame('),
    'PvP freshness must remain a non-rendering transport.');

$assert(str_contains($runtime, "window.addEventListener('click', guardAndTrackTicTacToe, true)")
    && str_contains($runtime, 'Never jump upward on a same-turn poll')
    && str_contains($runtime, 'mgw-v110-search-summary'),
    'Accepted game interaction behavior must remain untouched.');

$assert(str_contains($php, 'production-clean-entry-v110.js?v=1115')
    && str_contains($php, 'main-v110.js?v=1115')
    && str_contains($php, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0')
    && str_contains($launchUrl, "private const ENTRY_PATH = '/app/v110.php?v=1115';")
    && str_contains($welcome, "Active canonical path: '/app/v110.php?v=1115'.")
    && str_contains($invitesEndpoint, 'return WebAppLaunchUrl::invitation($config, $token);'),
    'All Telegram launches must keep the canonical v110 entrypoint.');

fwrite(STDOUT, "ProductionV110AcceptanceRootFixContractTest: {$assertions} assertions passed\n");
