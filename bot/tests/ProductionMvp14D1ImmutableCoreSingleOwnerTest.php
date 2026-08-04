<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$htaccess = file_get_contents($root . '/app/.htaccess');
$main = file_get_contents($root . '/app/assets/js/main.js');
$residual = file_get_contents($root . '/app/assets/js/residual-ui-game-race-fix-v114.js');
$smoke = file_get_contents($root . '/e2e/staging/frontend-immutable-core.spec.mjs');
$background = file_get_contents($root . '/app/assets/js/screens/notifications-passive-v121.js');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v121.js');
$invites = file_get_contents($root . '/app/assets/js/games/game-invites.js');
if (!is_string($entry) || !is_string($htaccess) || !is_string($main) || !is_string($residual)
    || !is_string($smoke) || !is_string($background) || !is_string($owner) || !is_string($invites)) {
    throw new RuntimeException('Missing v121 integrated immutable-core sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($htaccess, 'DirectoryIndex v114.php index.html'),
    'The staging app root must continue through the no-cache PHP entry.');
$assert(str_contains($entry, 'type="importmap"')
        && str_contains($entry, '"./assets/js/api/client.js?v=47": "./assets/js/api/client.js?v=114"')
        && str_contains($entry, '"./assets/js/session.js?v=27": "./assets/js/session.js?v=114"'),
    'The import map must retain compatible API/session objects.');
$assert(str_contains($entry, '"./assets/js/residual-ui-game-race-fix.js?v=91": "./assets/js/residual-ui-game-race-fix-v114.js?v=114"')
        && str_contains($entry, '"./assets/js/screens/notifications-screen-v99.js?v=99": "./assets/js/screens/notifications-passive-v121.js?v=121"')
        && str_contains($entry, '"./assets/js/games/game-invites.js?v=85": "./assets/js/games/game-invites.js?v=114"'),
    'The immutable map must route background notifications to the passive v121 object while retaining Share and residual modules.');
$assert(str_contains($entry, 'data-hotfix-build="v121-mvp14-notification-short-input-owner"')
        && str_contains($entry, './assets/js/main.js?v=115')
        && str_contains($entry, 'X-MGW-Frontend-Build: v121-mvp14-notification-short-input-owner')
        && str_contains($entry, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'),
    'The entry must expose the no-cache v121 shell while retaining the reviewed v115 main graph.');
$assert(substr_count($entry, 'notification-window-owner-v121.js?v=121') === 1
        && !str_contains($entry, 'notification-window-owner-v119.js?v=119')
        && !str_contains($entry, 'notification-empty-frame-guard-v115.js?v=115')
        && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116')
        && !str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117'),
    'One canonical v121 notification input owner must replace every retired owner.');
$assert(substr_count($entry, 'opponents-native-fetch-v115.js?v=115') === 1
        && substr_count($entry, 'opponents-empty-cache-guard-v115.js?v=115') === 1
        && substr_count($entry, 'opponents-authoritative-confirm-v117.js?v=117') === 1,
    'The isolated Bug A branch must retain the reviewed opponent transport graph.');
$assert(str_contains($residual, 'window.__MGW_RESIDUAL_V114__')
        && str_contains($residual, 'notificationOwner:false')
        && str_contains($residual, 'shareOwner:false')
        && str_contains($residual, 'gameStateCoalescing:true'),
    'The residual marker must still declare no UI ownership.');
$assert(!str_contains($residual, "addEventListener('click'")
        && !str_contains($residual, 'notificationsOpen')
        && !str_contains($residual, 'data-invite-action')
        && !str_contains($residual, 'openSheet('),
    'The residual layer must never regain notification, Share or invitation UI ownership.');
$assert(str_contains($owner, "window.addEventListener('pointerdown'")
        && str_contains($owner, "window.addEventListener('pointerup'")
        && str_contains($owner, "document.addEventListener('mgw:notification-sync'")
        && str_contains($owner, 'event.stopImmediatePropagation();')
        && str_contains($owner, 'openFromUserInput();'),
    'The v121 owner must exclusively own the real notification input sequence and rendering.');
$assert(str_contains($background, 'refreshNotificationBadge(false)')
        && str_contains($background, 'notificationPoll = window.setInterval')
        && str_contains($background, 'setUnreadCount(')
        && !str_contains($background, 'openNotificationsSheet('),
    'The passive background module may poll badges and render toast content but cannot open the sheet.');
$assert(str_contains($invites, "document.querySelector('[data-create-link-invite]')?.addEventListener")
        && str_contains($invites, 'data-copy-invite-link')
        && str_contains($invites, 'data-discard-draft'),
    'The canonical invite coordinator must remain the sole Share owner.');
$assert(str_contains($main, "import { initV115Presence } from './presence-v115.js?v=115';")
        && str_contains($main, "import { initInviteTerminalActions } from './games/invite-terminal-actions-v115.js?v=115';")
        && substr_count($main, 'initV115Presence();') === 1
        && substr_count($main, 'initInviteTerminalActions();') === 1,
    'The integrated main entry must initialize each non-notification owner once.');
$assert(str_contains($smoke, 'APP_ROUTE = `${STAGING_ORIGIN}/app/?mgw_e2e_frontend=v121`')
        && str_contains($smoke, "EXPECTED_BUILD = 'v121-mvp14-notification-short-input-owner'")
        && str_contains($smoke, "'/assets/js/screens/notification-window-owner-v121.js?v=121'")
        && str_contains($smoke, "'/assets/js/screens/notifications-passive-v121.js?v=121'")
        && str_contains($smoke, "'/assets/js/screens/notification-window-owner-v119.js?v=119'"),
    'The live smoke test must prove the v121 graph and explicitly reject the retired v119 resource.');
$assert(!str_contains($entry, 'mini-games-world.com')
        && !str_contains($smoke, 'setup_secret')
        && !str_contains($smoke, 'staging_test_auth_secret'),
    'The D1 integration must remain staging-only and contain no long-lived secret.');

fwrite(STDOUT, "ProductionMvp14D1ImmutableCoreSingleOwnerTest: {$assertions} assertions passed\n");
