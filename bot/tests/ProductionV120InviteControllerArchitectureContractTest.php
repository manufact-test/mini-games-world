<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v120 source: ' . $path);
    return $content;
};
$blobSha = static fn(string $content): string => sha1('blob ' . strlen($content) . "\0" . $content);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$v120 = $read('app/v120.php');
$main = $read('app/assets/js/main-v120.js');
$shell = $read('app/assets/js/main-v120-invite-controller-shell.js');
$controller = $read('app/assets/js/games/invite-controller-v120.js');
$state = $read('app/assets/js/games/invite-controller-state-v120.js');

$legacyShell = $read('app/assets/js/main-v110-handoff-shell.js');
$legacyInvites = $read('app/assets/js/games/game-invites-v110.js');
$legacyNotifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$legacyTerminal = $read('app/assets/js/games/invite-terminal-actions-v110r12.js');
$legacyLink = $read('app/assets/js/games/invite-link-entry-v110r12.js');

$assert(
    str_contains($launch, "private const ENTRY_PATH = '/app/v120.php?v=1200';")
        && str_contains($v120, 'production-clean-entry-v110.js?v=1120')
        && str_contains($v120, 'main-v120.js?v=1200')
        && str_contains($v120, 'v120-mvp14r12-single-invite-controller')
        && str_contains($main, "main-v120-invite-controller-shell.js?v=1200"),
    'Telegram and browser launch must select the isolated v120 entry while retaining the accepted clean regression entry.'
);

$assert(
    substr_count($shell, "from './games/invite-controller-v120.js?v=1200'") === 1
        && substr_count($shell, 'initInviteController();') === 1
        && substr_count($shell, 'await openInviteEntry();') === 1
        && !str_contains($shell, 'notifications-screen-v110r12.js')
        && !str_contains($shell, 'invite-terminal-actions-v110r12.js')
        && !str_contains($shell, 'game-invites-v110.js')
        && !str_contains($shell, 'invite-link-entry-v110r12.js')
        && !str_contains($shell, 'initNotificationsScreen')
        && !str_contains($shell, 'initInviteTerminalActions')
        && !str_contains($shell, 'initGameInvites'),
    'The v120 active graph must have one invitation/notification/deep-link owner and no legacy owners.'
);

$assert(
    substr_count($controller, "window.addEventListener('click', handleClick, true)") === 1
        && !str_contains($controller, "document.addEventListener('click'")
        && str_contains($controller, 'createInviteControllerState(incomingToken())')
        && str_contains($controller, 'beginEntryResolution(model);')
        && str_contains($controller, 'if (shouldStartBackgroundLoops(model)) startLoops();')
        && str_contains($controller, 'const invite = applyEntrySnapshot(model, result);')
        && str_contains($controller, 'showIncomingInvite(invite, { entry:true });'),
    'One controller must own clicks and resolve the Telegram entry before background loops can start.'
);

$openEntryPosition = strpos($controller, 'export async function openInviteEntry()');
$showEntryPosition = strpos($controller, 'showIncomingInvite(invite, { entry:true });', $openEntryPosition ?: 0);
$startLoopPosition = strpos($controller, 'if (appReady) startLoops();', $showEntryPosition ?: 0);
$assert(
    $openEntryPosition !== false
        && $showEntryPosition !== false
        && $startLoopPosition !== false
        && $openEntryPosition < $showEntryPosition
        && $showEntryPosition < $startLoopPosition,
    'The full Telegram invite sheet must be opened before notification/sync loops are released.'
);

$assert(
    str_contains($controller, "const immediate = visibleNotifications();")
        && str_contains($controller, "else if (!model.notificationsLoaded) renderNotificationLoading();")
        && str_contains($controller, 'pinnedNotifications.set(notificationIdentity(item), item)')
        && str_contains($controller, "if (source === 'toast') await waitForPaint(generation);")
        && str_contains($state, 'localAuthority:new Map()')
        && str_contains($state, 'expiresAt:Date.now() + 12000')
        && str_contains($state, 'if (!sequence || sequence < state.requestApplied[key]) return false;'),
    'The notification first frame must pin exact data, show loading instead of false empty, and reject stale responses.'
);

$assert(
    str_contains($controller, "const terminal = action === 'decline' || action === 'cancel';")
        && str_contains($controller, 'removeInviteNotifications(model, token);')
        && str_contains($controller, 'closeSheet();')
        && !str_contains($controller, "toast('Приглашение отклонено")
        && !str_contains($controller, "toast('Приглашение отменено")
        && !str_contains($controller, 'terminalNotificationItem('),
    'Decline/cancel must close and remove actor state without any actor-side success toast or confirmation card.'
);

$assert(
    $blobSha($legacyShell) === '5c88f75179063945c15bf4f409bb32812b98cca8'
        && $blobSha($legacyInvites) === '4377c912b2a85d2e0144c5631115dcea96b5d6b1'
        && $blobSha($legacyNotifications) === '98df0056932f94b7fac1bea99be75ba842cb5880'
        && $blobSha($legacyTerminal) === '893817d00dd00b720b260f8ddb6625bdbcdd5ef7'
        && $blobSha($legacyLink) === 'b9697cb1d18b8c3b5f4398923d53ab58fb27beab',
    'Every legacy owner and the accepted v110 shell must remain byte-for-byte available for rollback.'
);

fwrite(STDOUT, "ProductionV120InviteControllerArchitectureContractTest: {$assertions} assertions passed\n");
