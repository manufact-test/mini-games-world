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

$watch = $read('bot/game-watch.php');
$sync = $read('app/assets/js/production-v110-readonly-game-sync.js');
$presence = $read('app/assets/js/production-v110-presence.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r5.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$launch = $read('bot/helpers/WebAppLaunchUrl.php');

$jsonFastPath = strpos($watch, "if (\$driver === 'json')");
$storageFallback = strpos($watch, 'StorageFactory::create($config)');
$assert($jsonFastPath !== false
    && $storageFallback !== false
    && $jsonFastPath < $storageFallback
    && str_contains($watch, "fopen(\$path, 'rb')")
    && str_contains($watch, 'flock($handle, LOCK_SH)')
    && !str_contains($watch, 'app.lock'),
    'The accepted lock-free PvP watch must remain unchanged.');

$assert(str_contains($sync, 'const WATCH_INTERVAL_MS = 250;')
    && str_contains($sync, 'const FALLBACK_GAME_POLL_MS = 1500;')
    && str_contains($sync, "typeof speed?.rawFetch === 'function'")
    && !str_contains($sync, 'openSheet('),
    'PvP freshness must remain a non-rendering transport.');

$assert(str_contains($presence, '// Start immediately.')
    && str_contains($presence, 'startPresence();')
    && !str_contains($presence, 'mgwPrefetch'),
    'The single client presence owner must still start before application boot.');

$toastStart = strpos($notifications, 'async function openToastNotification()');
$toastPaint = strpos($notifications, 'renderNotifications(mergeNotificationItems([item], currentItems()));', $toastStart ?: 0);
$toastRefresh = strpos($notifications, 'void refreshOpenSheet();', $toastStart ?: 0);
$assert($toastStart !== false
    && $toastPaint !== false
    && $toastRefresh !== false
    && $toastPaint < $toastRefresh,
    'The exact blue-toast item must still paint before authoritative refresh.');

$assert(!str_contains($shell, 'NotificationPreflight')
    && substr_count($shell, 'initNotificationsScreen();') === 1
    && substr_count($shell, 'initV110ReadonlyGameSync();') === 1,
    'The active graph must retain one notification owner and one non-rendering PvP transport.');
$assert(str_contains($launch, '/app/v110.php?v=1110'),
    'Telegram launches must use the corrected outer revision.');

fwrite(STDOUT, 'ProductionV110PvpResultNotificationRootContractTest: ' . $assertions . " assertions passed\n");
