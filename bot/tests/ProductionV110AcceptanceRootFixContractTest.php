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
$notifications = $read('app/assets/js/screens/notifications-screen-v110-root.js');
$gameInvites = $read('app/assets/js/games/game-invites-v110.js');
$legacyGameInvites = $read('app/assets/js/games/game-invites.js');
$legacyMain = $read('app/assets/js/main-v105.js');
$legacyTargeted = $read('app/assets/js/production-v103-targeted-interactions.js');
$legacyNotifications = $read('app/assets/js/screens/notifications-screen.js');
$legacyV104InviteControls = $read('app/assets/js/production-v104-invite-game-controls.js');
$legacyV104Result = $read('app/assets/js/production-v104-result-instant.js');
$legacyV105Latency = $read('app/assets/js/production-v105-invite-latency.js');
$legacyV109InviteSpeed = $read('app/assets/js/production-v109-invite-speed.js');
$legacyV105Ttt = $read('app/assets/js/production-v105-tictactoe-stability.js');
$legacyV109Notifications = $read('app/assets/js/production-v109-notifications.js');
$legacyPresence = $read('app/assets/js/production-v109-presence.js');
$presence = $read('app/assets/js/production-v110-presence.js');
$gameSync = $read('app/assets/js/production-v110-readonly-game-sync.js');
$gameWatch = $read('bot/game-watch.php');
$php = $read('app/v110.php');
$presenceEndpoint = $read('bot/presence.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');
$launchUrl = $read('bot/helpers/WebAppLaunchUrl.php');
$invitesEndpoint = $read('bot/invites.php');

$assert(
    str_contains($main, "import './main-v110-handoff-shell.js?v=1108';")
        && str_contains($main, "window.__MGW_BUILD__ = 'v110-mvp14r3-invite-presence-notification-profile-root'")
        && str_contains($shell, "window.__MGW_BUILD__ = 'v110-mvp14r3-invite-presence-notification-profile-root'"),
    'v110 must publish the fresh invite, presence, notification and profile build through an isolated shell.'
);

$assert(
    $count($entry, 'initV110AcceptanceRuntime();') === 1
        && $count($entry, 'initV110TargetedInteractions();') === 1
        && $count($entry, 'initV110MatchLifecycle();') === 1
        && str_contains($entry, "window.__MGW_REGRESSION_BUILD__ = 'v110-mvp14r3-invite-presence-notification-profile-root'"),
    'Every retained production entry owner must initialize exactly once.'
);

$assert(
    !str_contains($entry, 'initV104InviteGameControls')
        && !str_contains($entry, 'initV104ResultInstant')
        && !str_contains($entry, 'initV105InviteLatency')
        && !str_contains($entry, 'initV109InviteSpeed')
        && str_contains($legacyV104InviteControls, 'initV104InviteGameControls')
        && str_contains($legacyV104Result, 'initV104ResultInstant')
        && str_contains($legacyV105Latency, 'initV105InviteLatency')
        && str_contains($legacyV109InviteSpeed, 'initV109InviteSpeed'),
    'Parallel invitation/result owners must remain outside active v110 without deleting rollback assets.'
);

$assert(
    $count($shell, 'initGameInvites();') === 1
        && str_contains($shell, "from './games/game-invites-v110.js?v=1105'")
        && str_contains($gameInvites, "document.addEventListener('click', handleDocumentClick, true)")
        && str_contains($legacyGameInvites, 'const SYNC_INTERVAL_MS = 1500;')
        && !str_contains($legacyGameInvites, '/bot/invite-watch.php'),
    'The isolated v110 invitation file must remain the single active invitation owner.'
);

$homePosition = strpos($lifecycle, "showScreen('home');");
$requestPosition = strpos($lifecycle, 'const result = await api.leaveGame(String(snapshot.id));');
$replayPosition = strpos($lifecycle, 'window.queueMicrotask(() => queuedButton.click());');
$assert(
    str_contains($lifecycle, "window.addEventListener('click', ownMatchLifecycleClick, true)")
        && $homePosition !== false
        && $requestPosition !== false
        && $replayPosition !== false
        && $homePosition < $requestPosition
        && $requestPosition < $replayPosition,
    'Manual surrender must route home before the request and replay queued search only after release.'
);

$assert(
    str_contains($lifecycle, 'queueSearchAfterRelease(startButton);')
        && str_contains($lifecycle, "button.textContent = 'Запускаем поиск…';")
        && !str_contains($lifecycle, 'renderPendingResult')
        && !str_contains($lifecycle, 'renderConfirmedResult')
        && !str_contains($lifecycle, 'data-v110-leave-pending')
        && !str_contains($lifecycle, 'openSheet('),
    'Manual surrender must expose no blocked result overlay.'
);

$assert(
    str_contains($lifecycle, 'state.activeGame = null;')
        && str_contains($lifecycle, 'clearGameView();')
        && str_contains($lifecycle, "source:'v110-surrender-home'")
        && str_contains($lifecycle, 'enterGame(snapshot, viewer);'),
    'The surrender transition must clear immediately and restore the authoritative game on failure.'
);

$assert(
    !str_contains($targeted, 'leavePending')
        && !str_contains($targeted, 'confirmLeaveGame')
        && !str_contains($targeted, 'api.leaveGame')
        && !str_contains($targeted, "showScreen('home')"),
    'The targeted interaction guard must not become a second surrender owner.'
);

$toastStart = strpos($notifications, 'async function openToastNotification()');
$toastPaint = strpos($notifications, 'renderNotifications(mergeNotificationItems([item], currentItems()));', $toastStart ?: 0);
$toastDismiss = strpos($notifications, 'dismissToast();', $toastStart ?: 0);
$toastRefresh = strpos($notifications, 'void refreshOpenSheet();', $toastStart ?: 0);
$assert(
    $toastStart !== false && $toastPaint !== false && $toastDismiss !== false && $toastRefresh !== false
        && $toastPaint < $toastDismiss && $toastDismiss < $toastRefresh,
    'A blue toast click must synchronously paint the exact live item before dismissal and refresh.'
);

$assert(
    str_contains($notifications, "const fetcher = typeof speed?.rawFetch === 'function' ? speed.rawFetch")
        && str_contains($notifications, "cache:'no-store'")
        && str_contains($notifications, 'await delay(EMPTY_RETRY_MS);')
        && !str_contains($notifications, 'api.notifications(true)')
        && !str_contains($notifications, 'optimisticNotificationRead'),
    'Notification opening must bypass stale optimistic cache and retry unread-empty races.'
);

$assert(
    str_contains($notifications, "document.addEventListener('mgw:notification-sync'")
        && str_contains($notifications, "const token = String(item?.invite_token || '')")
        && str_contains($notifications, 'const actions = Array.isArray(item?.actions) ? item.actions : [];')
        && $count($shell, 'initNotificationsScreen();') === 1,
    'The active notification owner must retain invite actions and initialize once.'
);

$assert(
    !str_contains($runtime, 'ownNotificationOpen')
        && !str_contains($runtime, 'seedToastPreview')
        && !str_contains($runtime, '/bot/notifications.php')
        && !str_contains($runtime, "closest('#notificationToast')")
        && !str_contains($runtime, "closest('#notificationsOpen')"),
    'The acceptance runtime must not reintroduce a parallel notification owner.'
);

$assert(
    str_contains($legacyTargeted, 'runtime.leavePending = true;')
        && str_contains($legacyTargeted, "showScreen('home');")
        && str_contains($legacyMain, '__MGW_V103_TARGETED_INTERACTIONS__?.leavePending')
        && !str_contains($legacyNotifications, 'rawNotifications(markRead)'),
    'Historical rollback files must remain available while v110 uses isolated assets.'
);

$assert(
    !str_contains($entry, 'initV105TicTacToeStability')
        && str_contains($legacyV105Ttt, 'window.fetch = stableFetch')
        && !str_contains($entry, 'initV109Notifications')
        && str_contains($legacyV109Notifications, 'function enrichInviteActions'),
    'Retired fetch and notification wrappers must remain outside active v110.'
);

$assert(
    str_contains($shell, 'notifications-screen-v110-root.js?v=1107')
        && str_contains($shell, 'production-v110-readonly-game-sync.js?v=1107')
        && str_contains($shell, 'production-v110-presence.js?v=1107')
        && str_contains($shell, 'profile-screen.js?v=1108')
        && str_contains($shell, 'production-v110-notification-preflight.js?v=1108')
        && str_contains($php, 'production-clean-entry-v110.js?v=1108')
        && str_contains($php, 'main-v110.js?v=1108'),
    'Changed owners must use v1108 while retained accepted owners keep their exact prior revisions.'
);

$assert(
    str_contains($runtime, "window.addEventListener('click', guardAndTrackTicTacToe, true)")
        && str_contains($runtime, "board[cell] !== '-'")
        && str_contains($runtime, 'paintPendingMove();')
        && !str_contains($runtime, 'window.fetch ='),
    'The accepted Tic Tac Toe single-owner safeguards must remain.'
);

$assert(
    str_contains($runtime, 'if (serverRemainingMs + 700 < localRemaining)')
        && str_contains($runtime, 'Never jump upward on a same-turn poll')
        && str_contains($runtime, 'Math.ceil((clock.deadline - performance.now()) / 1000)'),
    'The accepted visible timer behavior must remain untouched.'
);

$assert(
    str_contains($runtime, 'mgw-v110-search-summary')
        && str_contains($runtime, '#searchInfo{min-height:2.9em}')
        && str_contains($runtime, "secondary:type === 'domino' ? 'Классика 0–6'"),
    'The accepted search summary layout must remain untouched.'
);

$assert(
    str_contains($presenceEndpoint, "if (\$action === 'ping' || \$action === 'status') \$presence->touch(\$accountId, \$sessionId);")
        && strpos($presenceEndpoint, '$presence->touch') < strpos($presenceEndpoint, '$stats->build'),
    'Authenticated presence reads must confirm the current session before counting online players.'
);

$presenceInit = strpos($shell, 'initV110Presence();');
$bootCall = strrpos($shell, 'boot();');
$assert(
    !str_contains($entry, 'initV109Presence')
        && str_contains($legacyPresence, 'export function initV109Presence')
        && $count($shell, 'initV110Presence();') === 1
        && $presenceInit !== false && $bootCall !== false && $presenceInit < $bootCall
        && str_contains($presence, 'startPresence();')
        && !str_contains($presence, 'mgwPrefetch'),
    'One immediate non-prefetch v110 presence owner must replace the active v109 owner.'
);

$jsonFastPath = strpos($gameWatch, "if (\$driver === 'json')");
$storageFallback = strpos($gameWatch, 'StorageFactory::create($config)');
$assert(
    $jsonFastPath !== false && $storageFallback !== false && $jsonFastPath < $storageFallback
        && str_contains($gameWatch, "'games.json'")
        && str_contains($gameWatch, 'flock($handle, LOCK_SH)')
        && !str_contains($gameWatch, 'app.lock')
        && !str_contains($gameWatch, 'api_ok(['),
    'Production JSON game watch must avoid the global app lock and general API success hooks.'
);

$assert(
    str_contains($gameSync, 'const WATCH_INTERVAL_MS = 250;')
        && str_contains($gameSync, "typeof speed?.rawFetch === 'function'")
        && str_contains($gameSync, 'enterGame(game, result.me || null);')
        && !str_contains($gameSync, 'openSheet(')
        && !str_contains($gameSync, 'finishGame('),
    'The lock-free transport may supply projections but never own rendering or results.'
);

$assert(
    str_contains($php, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0')
        && str_contains($launchUrl, "private const ENTRY_PATH = '/app/v110.php?v=1108';")
        && str_contains($welcome, "Active canonical path: '/app/v110.php?v=1108'.")
        && str_contains($welcome, 'WebAppLaunchUrl::base($this->config)')
        && str_contains($invitesEndpoint, 'return WebAppLaunchUrl::invitation($config, $token);')
        && !str_contains($welcome, '/app/?v=85')
        && !str_contains($invitesEndpoint, '/app/?v=85'),
    'Every Telegram start, menu and invite must use the fresh canonical v1108 URL builder.'
);

$assert(
    !str_contains($entry, 'production-v106-')
        && !str_contains($entry, 'production-v107-')
        && !str_contains($entry, 'production-v108-')
        && !str_contains($runtime, '/bot/game-clock.php')
        && !str_contains($runtime, 'reset_clock'),
    'Failed runtime chains and server-clock mutation must not return.'
);

fwrite(STDOUT, "ProductionV110AcceptanceRootFixContractTest: {$assertions} assertions passed\n");
