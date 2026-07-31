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

$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$main = $read('app/assets/js/main-v110.js');
$entry = $read('app/assets/js/production-clean-entry-v110.js');
$sync = $read('app/assets/js/production-v110-readonly-game-sync.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110-root.js');
$watch = $read('bot/game-watch.php');
$v110 = $read('app/v110.php');

$assert(
    str_contains($v110, 'production-clean-entry-v110.js?v=1106')
        && str_contains($v110, 'main-v110.js?v=1106')
        && str_contains($v110, 'data-hotfix-build="v110-mvp14r3-pvp-result-notification-root"')
        && str_contains($main, "main-v110-handoff-shell.js?v=1106")
        && str_contains($entry, "window.__MGW_REGRESSION_BUILD__ = 'v110-mvp14r3-pvp-result-notification-root'"),
    'Telegram v110 must publish one fresh PvP result and notification root build.'
);

$assert(
    substr_count($shell, 'initV110ReadonlyGameSync();') === 1
        && substr_count($shell, 'initGameScreen();') === 1
        && str_contains($shell, "production-v110-readonly-game-sync.js?v=1106")
        && str_contains($shell, "notifications-screen-v110-root.js?v=1106")
        && !str_contains($shell, "notifications-screen-v110.js?v=1105"),
    'Active v110 must initialize one read-only transport and one replacement notification owner.'
);

$assert(
    str_contains($sync, 'const WATCH_INTERVAL_MS = 250;')
        && str_contains($sync, 'const FALLBACK_GAME_POLL_MS = 1500;')
        && str_contains($sync, 'APP_CONFIG.gameIntervalMs = Math.max')
        && str_contains($sync, 'await watchCurrentGame();'),
    'PvP freshness must use a fast shared-lock watch while the heavy game_state poll stays a slower fallback.'
);

$assert(
    str_contains($sync, 'if (game?.is_bot_game) return false;')
        && str_contains($sync, "String(screen?.dataset.screen || '') === 'game'")
        && str_contains($sync, 'item?.running || Number(item?.queue?.length || 0) > 0 || item?.surrenderPending'),
    'The read-only transport must target visible PvP only and never race a local action or surrender.'
);

$assert(
    str_contains($sync, "import { enterGame } from './screens/game-screen-v102-safe.js?v=102';")
        && str_contains($sync, 'enterGame(game, result.me || null);')
        && !str_contains($sync, 'openResultSheet')
        && !str_contains($sync, 'openSheet('),
    'The transport may supply a projection but the existing game screen must remain the only result/render owner.'
);

$assert(
    str_contains($watch, '$storage = StorageFactory::create($config);')
        && str_contains($watch, '$storage->readOnly(')
        && str_contains($watch, "in_array(\$userId, \$participants, true)")
        && !str_contains($watch, '->transaction('),
    'The watch endpoint must use authenticated participant-only shared-lock reads without a write transaction.'
);

$assert(
    !str_contains($watch, '$sessions->touch')
        && !str_contains($watch, 'cleanup(')
        && !str_contains($watch, 'releaseIfCurrent')
        && str_contains($watch, "header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');"),
    'The watch endpoint must not mutate session, game cleanup or production state.'
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
    'A blue-toast click must paint the exact live notification before hiding the toast or starting a list request.'
);

$assert(
    str_contains($notifications, 'void openToastNotification();')
        && str_contains($notifications, 'upsert(item);')
        && str_contains($notifications, 'const item = toastItem ? cloneItem(toastItem)')
        && str_contains($notifications, 'event.stopImmediatePropagation();'),
    'The toast element must route directly through the synchronous live-item path.'
);

$assert(
    str_contains($notifications, 'const EMPTY_RETRY_MS = 160;')
        && str_contains($notifications, 'Number(result?.unread_count || 0) > 0 || unreadHint > 0')
        && str_contains($notifications, 'renderLoading();')
        && str_contains($notifications, 'await delay(EMPTY_RETRY_MS);'),
    'An unread hint may show loading and retry, but must not flash a false empty state.'
);

fwrite(STDOUT, "ProductionV110PvpResultNotificationRootContractTest: {$assertions} assertions passed\n");
