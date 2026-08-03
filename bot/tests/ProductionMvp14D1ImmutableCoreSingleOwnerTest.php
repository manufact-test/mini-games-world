<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$htaccess = file_get_contents($root . '/app/.htaccess');
$main = file_get_contents($root . '/app/assets/js/main.js');
$residual = file_get_contents($root . '/app/assets/js/residual-ui-game-race-fix-v114.js');
$smoke = file_get_contents($root . '/e2e/staging/frontend-immutable-core.spec.mjs');
$notifications = file_get_contents($root . '/app/assets/js/screens/notifications-screen-v99.js');
$invites = file_get_contents($root . '/app/assets/js/games/game-invites.js');
if (!is_string($entry) || !is_string($htaccess) || !is_string($main) || !is_string($residual)
    || !is_string($smoke) || !is_string($notifications) || !is_string($invites)) {
    throw new RuntimeException('Missing v115 integrated immutable-core sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($htaccess, 'DirectoryIndex v114.php index.html'),
    'The staging app root must continue through the no-cache PHP entry before index.html.');

$assert(str_contains($entry, 'type="importmap"')
    && str_contains($entry, '"./assets/js/api/client.js?v=47": "./assets/js/api/client.js?v=114"')
    && str_contains($entry, '"./assets/js/session.js?v=21": "./assets/js/session.js?v=114"')
    && str_contains($entry, '"./assets/js/session.js?v=27": "./assets/js/session.js?v=114"'),
    'The import map must retain one compatible API/session contract across historical specifiers.');

$assert(str_contains($entry, '"./assets/js/residual-ui-game-race-fix.js?v=91": "./assets/js/residual-ui-game-race-fix-v114.js?v=114"')
    && str_contains($entry, '"./assets/js/screens/notifications-screen-v99.js?v=99": "./assets/js/screens/notifications-screen-v99.js?v=114"')
    && str_contains($entry, '"./assets/js/games/game-invites.js?v=85": "./assets/js/games/game-invites.js?v=114"'),
    'The immutable map must retain the proven canonical notification, Share and residual modules.');

$assert(str_contains($entry, 'data-hotfix-build="v115-mvp14-d1-feedback-integration"')
    && str_contains($entry, './assets/js/main.js?v=115')
    && str_contains($entry, 'X-MGW-Frontend-Build: v115-mvp14-d1-feedback-integration')
    && str_contains($entry, "Cache-Control: no-store, no-cache, must-revalidate, max-age=0"),
    'The entry must expose and deliver the exact no-cache integrated v115 build.');

$assert(substr_count($entry, 'notification-empty-frame-guard-v115.js?v=115') === 1
    && substr_count($entry, 'notification-bell-first-click-v115.js?v=115') === 1
    && substr_count($entry, 'opponents-native-fetch-v115.js?v=115') === 1
    && substr_count($entry, 'opponents-empty-cache-guard-v115.js?v=115') === 1,
    'Each non-owning first-paint/retry/cache guard must be published exactly once.');

$assert(str_contains($residual, 'window.__MGW_RESIDUAL_V114__')
    && str_contains($residual, 'uiOwner:false')
    && str_contains($residual, 'notificationOwner:false')
    && str_contains($residual, 'shareOwner:false')
    && str_contains($residual, 'inviteActionOwner:false')
    && str_contains($residual, 'gameMoveOwner:false')
    && str_contains($residual, 'gameStateCoalescing:true'),
    'The residual marker must still declare read-only request ownership.');

$assert(!str_contains($residual, "addEventListener('click'")
    && !str_contains($residual, 'notificationsOpen')
    && !str_contains($residual, 'data-create-link-invite')
    && !str_contains($residual, 'data-copy-invite-link')
    && !str_contains($residual, 'data-invite-action')
    && !str_contains($residual, 'openSheet(')
    && !str_contains($residual, 'closeSheet('),
    'The residual layer must never regain notification, Share or invitation UI ownership.');

$assert(str_contains($residual, 'api.gameState = coalescedGameState;')
    && str_contains($residual, 'gameStateInFlightByKey')
    && str_contains($residual, 'baseGameState(gameId)'),
    'The residual layer may retain only same-game state request coalescing.');

$assert(str_contains($notifications, "document.addEventListener('click', event =>")
    && str_contains($notifications, "event.target.closest('#notificationsOpen')")
    && str_contains($notifications, 'event.stopImmediatePropagation();')
    && str_contains($notifications, 'openNotificationsSheet();'),
    'The canonical notification screen must remain the sole delegated bell renderer.');

$assert(str_contains($invites, "document.querySelector('[data-create-link-invite]')?.addEventListener")
    && str_contains($invites, 'data-copy-invite-link')
    && str_contains($invites, 'data-discard-draft'),
    'The canonical invite coordinator must remain the sole Share owner.');

$assert(str_contains($main, "import { initV115Presence } from './presence-v115.js?v=115';")
    && str_contains($main, "import { initInviteTerminalActions } from './games/invite-terminal-actions-v115.js?v=115';")
    && str_contains($main, "import { openIncomingInviteFromTelegram } from './games/invite-link-entry-v115.js?v=115';")
    && substr_count($main, 'initV115Presence();') === 1
    && substr_count($main, 'initInviteTerminalActions();') === 1
    && substr_count($main, 'openIncomingInviteFromTelegram();') === 1,
    'The integrated main entry must initialize each new runtime owner exactly once.');

$assert(str_contains($smoke, 'APP_ROUTE = `${STAGING_ORIGIN}/app/?mgw_e2e_frontend=v115`')
    && str_contains($smoke, "EXPECTED_BUILD = 'v115-mvp14-d1-feedback-integration'")
    && str_contains($smoke, "response.headers()['x-mgw-frontend-build']")
    && str_contains($smoke, "toHaveAttribute('data-hotfix-build', EXPECTED_BUILD)")
    && str_contains($smoke, 'Frontend module graph failed before bootstrap')
    && str_contains($smoke, "'/assets/js/main.js?v=115'")
    && str_contains($smoke, "'/assets/js/presence-v115.js?v=115'"),
    'The live smoke test must prove the integrated v115 root and module graph.');

$assert(!str_contains($entry, 'mini-games-world.com')
    && !str_contains($smoke, 'mini-games-world.com')
    && !str_contains($smoke, 'setup_secret')
    && !str_contains($smoke, 'staging_test_auth_secret'),
    'The D1 integration must remain staging-only and contain no long-lived secret.');

fwrite(STDOUT, "ProductionMvp14D1ImmutableCoreSingleOwnerTest: {$assertions} assertions passed\n");
