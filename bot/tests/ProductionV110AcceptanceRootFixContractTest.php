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
$notifications = $read('app/assets/js/screens/notifications-screen-v110.js');
$gameInvites = $read('app/assets/js/games/game-invites.js');
$legacyMain = $read('app/assets/js/main-v105.js');
$legacyTargeted = $read('app/assets/js/production-v103-targeted-interactions.js');
$legacyNotifications = $read('app/assets/js/screens/notifications-screen.js');
$legacyV104InviteControls = $read('app/assets/js/production-v104-invite-game-controls.js');
$legacyV104Result = $read('app/assets/js/production-v104-result-instant.js');
$legacyV105Latency = $read('app/assets/js/production-v105-invite-latency.js');
$legacyV109InviteSpeed = $read('app/assets/js/production-v109-invite-speed.js');
$php = $read('app/v110.php');
$presence = $read('bot/presence.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');
$launchUrl = $read('bot/helpers/WebAppLaunchUrl.php');
$invitesEndpoint = $read('bot/invites.php');
$legacyV105Ttt = $read('app/assets/js/production-v105-tictactoe-stability.js');
$legacyV109Notifications = $read('app/assets/js/production-v109-notifications.js');

$assert(
    str_contains($main, "import './main-v110-handoff-shell.js?v=1105';")
        && str_contains($main, "window.__MGW_BUILD__ = 'v110-mvp14r3-invite-notification-speed'")
        && str_contains($shell, "window.__MGW_BUILD__ = 'v110-mvp14r3-invite-notification-speed'"),
    'v110 must publish the invite and notification speed build through a fresh isolated shell.'
);

$assert(
    $count($entry, 'initV110AcceptanceRuntime();') === 1
        && $count($entry, 'initV110TargetedInteractions();') === 1
        && $count($entry, 'initV110MatchLifecycle();') === 1
        && str_contains($entry, "window.__MGW_REGRESSION_BUILD__ = 'v110-mvp14r3-invite-notification-speed'"),
    'Every retained v110 owner must initialize exactly once.'
);

$assert(
    !str_contains($entry, 'initV104InviteGameControls')
        && !str_contains($entry, 'initV104ResultInstant')
        && !str_contains($entry, 'initV105InviteLatency')
        && !str_contains($entry, 'initV109InviteSpeed')
        && !str_contains($entry, 'initV101FastInviteWatch')
        && str_contains($legacyV104InviteControls, 'initV104InviteGameControls')
        && str_contains($legacyV104Result, 'initV104ResultInstant')
        && str_contains($legacyV105Latency, 'initV105InviteLatency')
        && str_contains($legacyV109InviteSpeed, 'initV109InviteSpeed'),
    'The active v110 graph must retire every parallel invitation/result owner without deleting rollback assets.'
);

$assert(
    $count($shell, 'initGameInvites();') === 1
        && str_contains($shell, "from './games/game-invites.js?v=1105'")
        && str_contains($gameInvites, "document.addEventListener('click', handleDocumentClick, true)"),
    'games/game-invites.js must be the single active invitation action and rematch owner.'
);

$homePosition = strpos($lifecycle, "showScreen('home');");
$requestPosition = strpos($lifecycle, 'const result = await api.leaveGame(String(snapshot.id));');
$replayPosition = strpos($lifecycle, 'window.queueMicrotask(() => queuedButton.click());');
$assert(
    str_contains($lifecycle, "window.addEventListener('click', ownMatchLifecycleClick, true)")
        && str_contains($lifecycle, "origin.closest('#confirmLeaveGame')")
        && $homePosition !== false
        && $requestPosition !== false
        && $replayPosition !== false
        && $homePosition < $requestPosition
        && $requestPosition < $replayPosition,
    'Manual surrender must route home before the request and replay queued search only after release.'
);

$assert(
    str_contains($lifecycle, 'const SEARCH_START_IDS = new Set([')
        && str_contains($lifecycle, 'queueSearchAfterRelease(startButton);')
        && str_contains($lifecycle, "button.textContent = 'Запускаем поиск…';")
        && str_contains($lifecycle, 'const queuedButton = releaseQueuedSearchButton();')
        && str_contains($lifecycle, "document.dispatchEvent(new CustomEvent('mgw:game-dismissed'))")
        && !str_contains($lifecycle, 'renderPendingResult')
        && !str_contains($lifecycle, 'renderConfirmedResult')
        && !str_contains($lifecycle, 'data-v110-leave-pending')
        && !str_contains($lifecycle, 'openSheet('),
    'Manual surrender must use one home/queue transition and expose no blocked result overlay.'
);

$assert(
    str_contains($lifecycle, 'state.activeGame = null;')
        && str_contains($lifecycle, 'closeSheet();')
        && str_contains($lifecycle, 'clearGameView();')
        && str_contains($lifecycle, "source:'v110-surrender-home'")
        && str_contains($lifecycle, 'enterGame(snapshot, viewer);'),
    'The transition must clear the playable surface immediately and restore the authoritative game on failure.'
);

$assert(
    !str_contains($targeted, 'leavePending')
        && !str_contains($targeted, 'confirmLeaveGame')
        && !str_contains($targeted, 'api.leaveGame')
        && !str_contains($targeted, "showScreen('home')"),
    'The targeted interaction guard must not become a second surrender owner.'
);

$assert(
    str_contains($notifications, 'const seed = toastItem ? [cloneItem(toastItem)] : currentItems();')
        && str_contains($notifications, 'mergeItems(seedItems);')
        && str_contains($notifications, 'if (immediate.length) renderNotifications(immediate);')
        && str_contains($notifications, 'const visible = mergeNotificationItems(serverItems, currentItems());')
        && str_contains($notifications, 'void rawNotifications(true).catch(() => null);'),
    'Toast click must merge the exact live server item into the visible sheet before any network refresh.'
);

$assert(
    str_contains($notifications, "const fetcher = typeof speed?.rawFetch === 'function' ? speed.rawFetch")
        && str_contains($notifications, "cache:'no-store'")
        && str_contains($notifications, 'const result = await rawNotifications(false);')
        && !str_contains($notifications, 'api.notifications(true)')
        && !str_contains($notifications, 'optimisticNotificationRead'),
    'Notification opening must bypass the stale optimistic mark-read cache and use one no-store authoritative read.'
);

$assert(
    str_contains($notifications, "document.addEventListener('mgw:notification-sync'")
        && str_contains($notifications, "const token = String(item?.invite_token || '')")
        && str_contains($notifications, 'const actions = Array.isArray(item?.actions) ? item.actions : [];')
        && str_contains($notifications, "event.target instanceof Element ? event.target.closest('#notificationsOpen')"),
    'The notification owner must retain token/actions and own bell/toast/sheet opening once.'
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
    'Historical rollback files must remain unchanged while v110 is rebuilt in isolated assets.'
);

$assert(
    !str_contains($entry, 'initV105TicTacToeStability')
        && !str_contains($entry, 'production-v105-tictactoe-stability.js')
        && str_contains($legacyV105Ttt, 'window.fetch = stableFetch')
        && !str_contains($entry, 'initV109Notifications')
        && str_contains($legacyV109Notifications, 'function enrichInviteActions'),
    'Retired fetch and notification wrappers must remain outside the active v110 graph.'
);

$assert(
    str_contains($shell, "notifications-screen-v110.js?v=1105")
        && str_contains($shell, "games/game-invites.js?v=1105")
        && str_contains($entry, "production-v110-match-lifecycle.js?v=1104")
        && str_contains($php, 'production-clean-entry-v110.js?v=1105')
        && str_contains($php, 'main-v110.js?v=1105')
        && str_contains($php, 'data-hotfix-build="v110-mvp14r3-invite-notification-speed"'),
    'Every changed v110 browser owner must be reached through a fresh cache-busted URL.'
);

$assert(
    str_contains($runtime, "window.addEventListener('click', guardAndTrackTicTacToe, true)")
        && str_contains($runtime, 'String(authoritative?.turn || \'\') !== viewerId')
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
        && str_contains($runtime, 'secondary:type === \'domino\' ? \'Классика 0–6\''),
    'The accepted search summary layout must remain untouched.'
);

$assert(
    str_contains($presence, "if (\$action === 'ping' || \$action === 'status') \$presence->touch(\$accountId, \$sessionId);")
        && str_contains($presence, "if (\$sessionId === '') throw new RuntimeException('Сессия устройства не найдена.');")
        && strpos($presence, '$presence->touch') < strpos($presence, '$stats->build'),
    'Authenticated presence reads must still confirm the current session.'
);

$assert(
    str_contains($php, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0')
        && str_contains($launchUrl, "private const ENTRY_PATH = '/app/v110.php?v=110';")
        && str_contains($welcome, "require_once __DIR__ . '/WebAppLaunchUrl.php';")
        && str_contains($welcome, 'WebAppLaunchUrl::base($this->config)')
        && str_contains($welcome, 'WebAppLaunchUrl::invitation($this->config, $inviteToken)')
        && str_contains($invitesEndpoint, "require_once __DIR__ . '/helpers/WebAppLaunchUrl.php';")
        && str_contains($invitesEndpoint, 'return WebAppLaunchUrl::invitation($config, $token);')
        && !str_contains($welcome, '/app/?v=85')
        && !str_contains($invitesEndpoint, '/app/?v=85'),
    'Every Telegram start, menu, direct invite and shared fallback must use one canonical v110 URL builder.'
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