<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read production v110 root source: ' . $path);
    return $content;
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$watch = $read('bot/game-watch.php');
$sync = $read('app/assets/js/production-v110-readonly-game-sync.js');
$presence = $read('app/assets/js/production-v110-presence.js');
$legacyPresence = $read('app/assets/js/production-v109-presence.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110-root.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$entry = $read('app/assets/js/production-clean-entry-v110.js');
$launch = $read('bot/helpers/WebAppLaunchUrl.php');

ob_start();
require $root . '/app/v110.php';
$html = ob_get_clean();
if (!is_string($html)) throw new RuntimeException('Cannot render v110 Telegram entrypoint.');

$jsonFastPath = strpos($watch, "if (\$driver === 'json')");
$storageFallback = strpos($watch, 'StorageFactory::create($config)');
$assert(
    $jsonFastPath !== false
        && $storageFallback !== false
        && $jsonFastPath < $storageFallback
        && str_contains($watch, "'games.json'")
        && str_contains($watch, "fopen(\$path, 'rb')")
        && str_contains($watch, 'flock($handle, LOCK_SH)')
        && str_contains($watch, 'stream_get_contents($handle)')
        && !str_contains($watch, 'app.lock'),
    'Production JSON game watch must read only games.json under its own shared file lock.'
);

$assert(
    str_contains($watch, "json_response([\n        'ok' => true")
        && !str_contains($watch, 'api_ok(['),
    'The read-only game watch must not execute general API success hooks.'
);

$assert(
    str_contains($watch, "in_array(\$userId, \$participants, true)")
        && str_contains($watch, '$games->publicGame($candidate, $userId)'),
    'Only an authenticated match participant may receive a public game projection.'
);

$assert(
    str_contains($sync, 'const WATCH_INTERVAL_MS = 250;')
        && str_contains($sync, 'const FALLBACK_GAME_POLL_MS = 1500;')
        && str_contains($sync, "typeof speed?.rawFetch === 'function'")
        && str_contains($sync, 'enterGame(game, result.me || null);'),
    'PvP freshness must use the lock-free watch while the existing game screen remains renderer/result owner.'
);

$assert(
    str_contains($sync, 'if (game?.is_bot_game) return false;')
        && str_contains($sync, 'actionIsBusy(gameRuntimeItem(gameId))')
        && !str_contains($sync, 'openSheet(')
        && !str_contains($sync, 'finishGame('),
    'The transport must not race local actions, alter bot games or create another result surface.'
);

$assert(
    !str_contains($entry, 'initV109Presence')
        && !str_contains($entry, "from './production-v109-presence.js")
        && str_contains($legacyPresence, 'export function initV109Presence'),
    'Legacy presence must remain available only as an inactive rollback asset.'
);

$presenceInit = strpos($shell, 'initV110Presence();');
$bootCall = strrpos($shell, 'boot();');
$assert(
    substr_count($shell, 'initV110Presence();') === 1
        && str_contains($shell, "from './production-v110-presence.js?v=1107'")
        && $presenceInit !== false
        && $bootCall !== false
        && $presenceInit < $bootCall,
    'Exactly one v110 presence owner must start before application boot.'
);

$assert(
    str_contains($presence, '// Start immediately.')
        && str_contains($presence, 'startPresence();')
        && str_contains($presence, "typeof speed?.rawFetch === 'function'")
        && !str_contains($presence, 'mgwPrefetch'),
    'Invite presence must start immediately and never be an abortable background-prefetch request.'
);

$toastStart = strpos($notifications, 'async function openToastNotification()');
$toastPaint = strpos($notifications, 'renderNotifications(mergeNotificationItems([item], currentItems()));', $toastStart ?: 0);
$toastDismiss = strpos($notifications, 'dismissToast();', $toastStart ?: 0);
$toastRefresh = strpos($notifications, 'void refreshOpenSheet();', $toastStart ?: 0);
$assert(
    $toastStart !== false
        && $toastPaint !== false
        && $toastDismiss !== false
        && $toastRefresh !== false
        && $toastPaint < $toastDismiss
        && $toastDismiss < $toastRefresh,
    'The exact blue-toast item must be painted before dismissal and before any server list refresh.'
);

$assert(
    str_contains($notifications, 'if (!visible.length && (Number(result?.unread_count || 0) > 0 || unreadHint > 0))')
        && str_contains($notifications, 'renderLoading();')
        && str_contains($notifications, 'await delay(EMPTY_RETRY_MS);'),
    'An unread hint may show loading/retry but never a false empty notification list.'
);

$assert(
    str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1107';")
        && str_contains($html, './assets/js/production-clean-entry-v110.js?v=1107')
        && str_contains($html, './assets/js/main-v110.js?v=1107')
        && str_contains($html, 'data-hotfix-build="v110-mvp14r3-pvp-lockfree-presence-root"'),
    'Telegram must open a genuinely fresh outer and inner v110 browser revision.'
);

$assert(
    str_contains($shell, "notifications-screen-v110-root.js?v=1107")
        && str_contains($shell, "production-v110-readonly-game-sync.js?v=1107")
        && substr_count($shell, 'initNotificationsScreen();') === 1
        && substr_count($shell, 'initV110ReadonlyGameSync();') === 1,
    'The fresh graph must retain one notification owner and one non-rendering PvP transport.'
);

fwrite(STDOUT, "ProductionV110PvpResultNotificationRootContractTest: {$assertions} assertions passed\n");
