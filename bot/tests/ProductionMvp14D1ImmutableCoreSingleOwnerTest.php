<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$htaccess = file_get_contents($root . '/app/.htaccess');
$main = file_get_contents($root . '/app/assets/js/main.js');
$residual = file_get_contents($root . '/app/assets/js/residual-ui-game-race-fix-v114.js');
$smoke = file_get_contents($root . '/e2e/staging/frontend-immutable-core.spec.mjs');
$notifications = file_get_contents($root . '/app/assets/js/screens/notifications-screen-v99.js');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v119.js');
$opponents = file_get_contents($root . '/app/assets/js/opponents-authoritative-confirm-v122.js');
$endpoint = file_get_contents($root . '/bot/invite-opponents.php');
if (!is_string($entry) || !is_string($htaccess) || !is_string($main) || !is_string($residual)
    || !is_string($smoke) || !is_string($notifications) || !is_string($owner)
    || !is_string($opponents) || !is_string($endpoint)) {
    throw new RuntimeException('Missing v122 integrated immutable-core sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($htaccess, 'DirectoryIndex v114.php index.html'),
    'The staging root must continue through the no-cache PHP entry.');
$assert(str_contains($entry, 'type="importmap"')
        && str_contains($entry, '"./assets/js/api/client.js?v=47": "./assets/js/api/client.js?v=114"')
        && str_contains($entry, '"./assets/js/session.js?v=27": "./assets/js/session.js?v=114"'),
    'The immutable API/session module graph must remain compatible.');
$assert(str_contains($entry, '"./assets/js/screens/notifications-screen-v99.js?v=99": "./assets/js/screens/notifications-screen-v99.js?v=114"')
        && str_contains($entry, '"./assets/js/games/game-invites.js?v=85": "./assets/js/games/game-invites.js?v=114"'),
    'The isolated Bug B branch must retain accepted notification and invite modules.');
$assert(str_contains($entry, 'data-hotfix-build="v122-mvp14-opponents-authoritative-source"')
        && str_contains($entry, './assets/js/main.js?v=115')
        && str_contains($entry, 'X-MGW-Frontend-Build: v122-mvp14-opponents-authoritative-source')
        && str_contains($entry, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'),
    'The entry must expose the no-cache v122 shell while retaining v115 main.');
$assert(substr_count($entry, 'notification-window-owner-v119.js?v=119') === 1
        && !str_contains($entry, 'notification-empty-frame-guard-v115.js?v=115')
        && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116')
        && !str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117'),
    'One canonical v119 notification owner must remain unchanged.');
$assert(substr_count($entry, 'opponents-native-fetch-v115.js?v=115') === 1
        && substr_count($entry, 'opponents-empty-cache-guard-v115.js?v=115') === 1
        && substr_count($entry, 'opponents-authoritative-confirm-v122.js?v=122') === 1
        && !str_contains($entry, 'opponents-authoritative-confirm-v117.js?v=117'),
    'The active player-picker graph must contain v122 confirmation exactly once.');
$assert(str_contains($residual, 'notificationOwner:false')
        && str_contains($residual, 'shareOwner:false')
        && str_contains($residual, 'inviteActionOwner:false')
        && str_contains($residual, 'gameStateCoalescing:true')
        && !str_contains($residual, 'notificationsOpen')
        && !str_contains($residual, 'openSheet('),
    'The residual layer must retain request coalescing without UI ownership.');
$assert(str_contains($owner, "window.addEventListener('click'")
        && str_contains($owner, "document.addEventListener('mgw:notification-sync'")
        && str_contains($owner, 'event.stopImmediatePropagation();')
        && str_contains($owner, 'openNotificationsSheet();'),
    'The isolated Bug B branch must preserve accepted v119 notification ownership.');
$assert(str_contains($notifications, 'refreshNotificationBadge(false);')
        && str_contains($notifications, 'notificationPoll = window.setInterval')
        && str_contains($notifications, 'setUnreadCount('),
    'Notification badge polling must remain intact.');
$assert(str_contains($opponents, 'REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES = 2')
        && str_contains($opponents, "payload?.storage_driver === 'database'")
        && !str_contains($opponents, 'openSheet('),
    'The v122 transport must require DB-primary confirmation without rendering UI.');
$assert(str_contains($endpoint, 'new DatabasePrimaryStateStorageAdapter(')
        && str_contains($endpoint, '$storage->readOnly(')
        && str_contains($endpoint, "'authoritative' => true")
        && str_contains($endpoint, "'storage_driver' => \$storage->driver()"),
    'The staging endpoint must read the canonical database state without mutation.');
$assert(str_contains($main, "import { initV115Presence } from './presence-v115.js?v=115';")
        && substr_count($main, 'initV115Presence();') === 1
        && substr_count($main, 'initInviteTerminalActions();') === 1,
    'Each unrelated runtime owner must remain initialized once.');
$assert(str_contains($smoke, 'mgw_e2e_frontend=v122')
        && str_contains($smoke, "EXPECTED_BUILD = 'v122-mvp14-opponents-authoritative-source'")
        && str_contains($smoke, "'/assets/js/screens/notification-window-owner-v119.js?v=119'")
        && str_contains($smoke, "'/assets/js/opponents-authoritative-confirm-v122.js?v=122'")
        && str_contains($smoke, "'/assets/js/opponents-authoritative-confirm-v117.js?v=117'"),
    'The live smoke must prove v122 while rejecting the retired v117 opponent resource.');
$assert(!str_contains($entry, 'mini-games-world.com')
        && !str_contains($smoke, 'setup_secret')
        && !str_contains($smoke, 'staging_test_auth_secret'),
    'The D1 branch must remain staging-only and secret-free.');

fwrite(STDOUT, "ProductionMvp14D1ImmutableCoreSingleOwnerTest: {$assertions} assertions passed\n");
