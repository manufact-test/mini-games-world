<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v108 rollback source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$rejectedEntry = $read('app/assets/js/production-clean-entry-v108.js');
$rejectedMain = $read('app/assets/js/main-v108.js');
$rejectedLive = $read('app/assets/js/production-v108-live-game.js');
$rejectedNotifications = $read('app/assets/js/production-v108-notifications.js');
$rejectedShare = $read('app/assets/js/production-v108-share.js');
$endpoint = $read('bot/game-live-v108.php');
$registry = $read('bot/runtime/ProductionPrimaryApplicationEntrypoints.php');
$rollbackEntry = $read('app/assets/js/production-clean-entry-v105-fast-notifications.js');
$fastNotifications = $read('app/assets/js/production-v105-fast-notifications.js');
$php = $read('app/v108.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');

$assert(
    str_contains($rejectedEntry, 'initV108LiveGame();')
        && str_contains($rejectedEntry, 'initV108Notifications();')
        && str_contains($rejectedEntry, 'initV108Share();'),
    'The rejected v108 implementation must remain available only for investigation and explicit comparison.'
);

$assert(
    str_contains($rejectedMain, "import './main-v105.js?v=105';")
        && str_contains($rejectedMain, "window.__MGW_BUILD__ = 'v108-mvp14-authoritative-live-runtime'"),
    'The isolated rejected v108 source must remain reproducible while inactive.'
);

$assert(
    str_contains($endpoint, "'v108_ready_player_ids'")
        && str_contains($endpoint, "'turn_deadline_ms'")
        && str_contains($endpoint, "'server_now_ms'"),
    'The rejected live endpoint source must remain auditable without being loaded by production.'
);

$assert(
    str_contains($rejectedLive, 'const SYNC_MS = 250;')
        && str_contains($rejectedNotifications, 'horizontalSwipe')
        && str_contains($rejectedShare, 'https://t.me/share/url?url='),
    'The rejected v108 client sources must remain available for root-cause analysis.'
);

$assert(
    str_contains($registry, "'bot/game-live-v108.php' => 'game_live_v108'"),
    'The registered endpoint remains guarded even while the rejected v108 client is disabled.'
);

$assert(
    str_contains($rollbackEntry, "window.__MGW_REGRESSION_BUILD__ = 'v105-mvp14-emergency-rollback-fast-notifications'")
        && str_contains($rollbackEntry, 'initV105FastNotifications();')
        && str_contains($rollbackEntry, 'initV105InviteLatency();')
        && str_contains($rollbackEntry, 'initV105TicTacToeStability();')
        && !str_contains($rollbackEntry, 'initV108LiveGame')
        && !str_contains($rollbackEntry, 'initV108Share'),
    'Production rollback must retain the v105 graph and add only the isolated fast notification opener.'
);

$assert(
    str_contains($fastNotifications, "peekV101CachedJson('notifications', 60000)")
        && str_contains($fastNotifications, "window.addEventListener('click', ownNotificationOpen, true)")
        && str_contains($fastNotifications, 'const result = await api.notifications(true);')
        && !str_contains($fastNotifications, "window.addEventListener('pointerdown'")
        && !str_contains($fastNotifications, "window.addEventListener('pointermove'")
        && !str_contains($fastNotifications, "window.addEventListener('pointerup'")
        && !str_contains($fastNotifications, 'horizontalSwipe')
        && !str_contains($fastNotifications, 'translate3d('),
    'The retained notification improvement must only open cached content instantly and must not own swipe or drag gestures.'
);

$assert(
    str_contains($php, 'production-clean-entry-v105-fast-notifications.js?v=1051')
        && str_contains($php, 'main-v105.js?v=105')
        && str_contains($php, 'v105-mvp14-emergency-rollback-fast-notifications')
        && !str_contains($php, 'production-clean-entry-v108.js?v=108')
        && str_contains($php, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'),
    'Every existing v108 URL must serve the v105 rollback graph plus only instant notification opening.'
);

$assert(
    str_contains($welcome, '/app/v105.php?v=105')
        && str_contains($welcome, '/app/v108.php?v=108')
        && str_contains($welcome, '/app/v107.php?v=107'),
    'New Telegram launches must use v105 while later entrypoints remain explicit investigation references.'
);

fwrite(STDOUT, "ProductionV108AuthoritativeLiveRuntimeContractTest: {$assertions} assertions passed\n");
