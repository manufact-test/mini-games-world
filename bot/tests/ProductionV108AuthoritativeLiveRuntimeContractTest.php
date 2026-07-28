<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v108 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entry = $read('app/assets/js/production-clean-entry-v108.js');
$main = $read('app/assets/js/main-v108.js');
$live = $read('app/assets/js/production-v108-live-game.js');
$notifications = $read('app/assets/js/production-v108-notifications.js');
$share = $read('app/assets/js/production-v108-share.js');
$endpoint = $read('bot/game-live-v108.php');
$registry = $read('bot/runtime/ProductionPrimaryApplicationEntrypoints.php');
$php = $read('app/v108.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');

$assert(
    str_contains($entry, 'initV108LiveGame();')
        && str_contains($entry, 'initV108Notifications();')
        && str_contains($entry, 'initV108Share();')
        && !str_contains($entry, 'initV107TicTacToeStability')
        && !str_contains($entry, 'production-v107-timer-pvp'),
    'v108 must replace the v107 timer owner while retaining the focused invite owner.'
);

$assert(
    str_contains($main, "import './main-v105.js?v=105';")
        && str_contains($main, "window.__MGW_BUILD__ = 'v108-mvp14-authoritative-live-runtime'"),
    'v108 main must retain the accepted application graph with a fresh build identity.'
);

$assert(
    str_contains($endpoint, "'v108_ready_player_ids'")
        && str_contains($endpoint, "'v108_clock_started'")
        && str_contains($endpoint, "'clock_waiting_for_players'")
        && str_contains($endpoint, "'turn_deadline_ms'")
        && str_contains($endpoint, "'server_now_ms'"),
    'The live endpoint must gate the first turn on both players and publish one authoritative server deadline.'
);

$assert(
    str_contains($live, 'const SYNC_MS = 250;')
        && str_contains($live, 'const TICK_MS = 80;')
        && str_contains($live, 'estimatedServerNow')
        && str_contains($live, "new CustomEvent('mgw:v101-finished-response'")
        && str_contains($live, 'needsRepair')
        && str_contains($live, "board.classList.contains('is-pending-launch')"),
    'The client must render one smooth server clock, surface finishes quickly and repair blank rematch entry.'
);

$assert(
    str_contains($notifications, "peekV101CachedJson('notifications', 60000)")
        && str_contains($notifications, 'horizontalSwipe')
        && str_contains($notifications, 'Math.abs(dx) > Math.abs(dy) * 1.25')
        && !str_contains($notifications, 'translate3d('),
    'Notifications must render cached content immediately and accept only a deliberate horizontal dismiss gesture.'
);

$assert(
    str_contains($share, 'prepareMessage:false')
        && str_contains($share, 'https://t.me/share/url?url=')
        && str_contains($share, 'openTelegramLink')
        && !str_contains($share, '.shareMessage('),
    'Mobile link invites must bypass the unstable prepared-message chooser and use the mature Telegram share URL flow.'
);

$assert(
    str_contains($registry, "'bot/game-live-v108.php' => 'game_live_v108'")
        && str_contains($php, 'production-clean-entry-v108.js?v=108')
        && str_contains($php, 'main-v108.js?v=108')
        && str_contains($php, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'),
    'The guarded production registry and no-store v108 entrypoint must expose the new runtime.'
);

$assert(
    str_contains($welcome, '/app/v108.php?v=108')
        && str_contains($welcome, 'v107'),
    'New Telegram launches must activate v108 while retaining v107 as an explicit rollback reference.'
);

fwrite(STDOUT, "ProductionV108AuthoritativeLiveRuntimeContractTest: {$assertions} assertions passed\n");
