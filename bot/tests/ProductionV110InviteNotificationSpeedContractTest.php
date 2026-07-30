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

$invites = $read('app/assets/js/games/game-invites-v110.js');
$legacyInvites = $read('app/assets/js/games/game-invites.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$entry = $read('app/assets/js/production-clean-entry-v110.js');

ob_start();
require $root . '/app/v110.php';
$html = ob_get_clean();
if (!is_string($html)) throw new RuntimeException('Cannot render v110 Telegram entrypoint.');

$assert(
    str_contains($invites, 'const WATCH_URL = `${window.location.origin}/bot/invite-watch.php`;')
        && str_contains($invites, 'const WATCH_INTERVAL_MS = 400;')
        && str_contains($invites, 'await watchIncomingInvite();')
        && str_contains($invites, 'showIncomingInvite(invite);'),
    'The single active invitation owner must consume the existing lightweight signal endpoint within 400 ms.'
);

$assert(
    str_contains($invites, 'const ACTIVE_SYNC_INTERVAL_MS = 500;')
        && str_contains($invites, 'const IDLE_SYNC_INTERVAL_MS = 1500;')
        && str_contains($invites, 'return currentInvite?.token ? ACTIVE_SYNC_INTERVAL_MS : IDLE_SYNC_INTERVAL_MS;'),
    'Only an active invitation may use the faster authoritative sync cadence.'
);

$directPaint = strpos($invites, 'showDirectInvitePending(context, opponentName);');
$directRequest = strpos($invites, "const result = await inviteRequest('create_direct'");
$assert(
    $directPaint !== false && $directRequest !== false && $directPaint < $directRequest,
    'The inviter must see the final owner surface before the direct-invite network request starts.'
);

$actionStart = strpos($invites, 'async function performInviteAction');
$actionRequest = strpos($invites, 'const result = await inviteRequest(action, { token });', $actionStart ?: 0);
$acceptPaint = strpos($invites, 'showInviteeWaiting({', $actionStart ?: 0);
$closePaint = strpos($invites, 'closeSheet();', $actionStart ?: 0);
$assert(
    $actionStart !== false
        && $actionRequest !== false
        && $acceptPaint !== false
        && $closePaint !== false
        && $acceptPaint < $actionRequest
        && $closePaint < $actionRequest,
    'Accept, decline and cancel must change the visible surface before their authoritative request completes.'
);

$preRequestBranch = $actionStart !== false && $actionRequest !== false
    ? substr($invites, $actionStart, $actionRequest - $actionStart)
    : '';
$assert(
    $preRequestBranch !== ''
        && str_contains($preRequestBranch, "action === 'accept'")
        && str_contains($preRequestBranch, "action === 'decline' || action === 'cancel'")
        && !str_contains($preRequestBranch, "action === 'start'"),
    'The final Start game action must remain authoritative for the future pre-match synchronization screen.'
);

$seedMerge = strpos($notifications, 'mergeItems(seedItems);');
$existingPromise = strpos($notifications, 'if (openingSheetPromise) return openingSheetPromise;');
$assert(
    $seedMerge !== false
        && $existingPromise !== false
        && $seedMerge < $existingPromise
        && !str_contains($notifications, 'if (openingSheet) return;'),
    'A toast seed must never be discarded by an older notification-sheet request.'
);

$assert(
    str_contains($notifications, 'if (immediate.length) renderNotifications(immediate);')
        && str_contains($notifications, 'const visible = mergeNotificationItems(serverItems, currentItems());')
        && str_contains($notifications, 'if (!currentItems().length) renderError();'),
    'Live notification items must remain visible while the authoritative no-store list resolves.'
);

$assert(
    substr_count($shell, 'initGameInvites();') === 1
        && substr_count($shell, 'initNotificationsScreen();') === 1
        && str_contains($shell, "./games/game-invites-v110.js?v=1105")
        && str_contains($shell, "./screens/notifications-screen-v110.js?v=1105")
        && !str_contains($entry, 'initV105InviteLatency')
        && !str_contains($entry, 'initV109InviteSpeed')
        && str_contains($legacyInvites, 'const SYNC_INTERVAL_MS = 1500;')
        && !str_contains($legacyInvites, '/bot/invite-watch.php'),
    'The active graph must retain exactly one isolated invite owner and one notification owner without changing rollback assets.'
);

$assert(
    str_contains($html, './assets/js/production-clean-entry-v110.js?v=1105')
        && str_contains($html, './assets/js/main-v110.js?v=1105')
        && str_contains($html, 'data-hotfix-build="v110-mvp14r3-invite-notification-speed"'),
    'Telegram v110 must publish the exact cache-busted invite speed build.'
);

fwrite(STDOUT, "ProductionV110InviteNotificationSpeedContractTest: {$assertions} assertions passed\n");