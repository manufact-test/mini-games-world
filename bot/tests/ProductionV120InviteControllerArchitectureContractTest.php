<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read rollback source: ' . $path);
    return $content;
};
$blobSha = static fn(string $content): string => sha1('blob ' . strlen($content) . "\0" . $content);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$v110 = $read('app/v110.php');
$main110 = $read('app/assets/js/main-v110.js');
$shell110 = $read('app/assets/js/main-v110-handoff-shell.js');
$v120 = $read('app/v120.php');
$main120 = $read('app/assets/js/main-v120.js');
$shell120 = $read('app/assets/js/main-v120-invite-controller-shell.js');
$controller = $read('app/assets/js/games/invite-controller-v120.js');
$state = $read('app/assets/js/games/invite-controller-state-v120.js');
$legacyInvites = $read('app/assets/js/games/game-invites-v110.js');
$legacyNotifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$legacyTerminal = $read('app/assets/js/games/invite-terminal-actions-v110r12.js');
$legacyLink = $read('app/assets/js/games/invite-link-entry-v110r12.js');

$assert(
    str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1123';")
        && str_contains($launch, "// private const ENTRY_PATH = '/app/v120.php?v=1200';")
        && str_contains($v110, 'main-v110.js?v=1123')
        && str_contains($main110, 'main-v110-handoff-shell.js?v=1123'),
    'Emergency rollback must make the accepted v110 graph active again.'
);

$assert(
    str_contains($shell110, 'notifications-screen-v110r12.js?v=1122')
        && str_contains($shell110, 'invite-terminal-actions-v110r12.js?v=1123')
        && str_contains($shell110, 'game-invites-v110.js?v=1114')
        && str_contains($shell110, 'invite-link-entry-v110r12.js?v=1123'),
    'The restored active shell must load the accepted v110 invitation owners.'
);

$assert(
    str_contains($v120, 'main-v120.js?v=1200')
        && str_contains($main120, 'main-v120-invite-controller-shell.js?v=1200')
        && str_contains($shell120, 'invite-controller-v120.js?v=1200')
        && str_contains($controller, 'createInviteControllerState(incomingToken())')
        && str_contains($state, 'localAuthority:new Map()')
        && !str_contains($main110, 'main-v120-invite-controller-shell.js')
        && !str_contains($shell110, 'invite-controller-v120.js'),
    'The rejected v120 experiment may remain for postmortem, but it must be dormant in the active graph.'
);

$assert(
    $blobSha($shell110) === '5c88f75179063945c15bf4f409bb32812b98cca8'
        && $blobSha($legacyInvites) === '4377c912b2a85d2e0144c5631115dcea96b5d6b1'
        && $blobSha($legacyNotifications) === '98df0056932f94b7fac1bea99be75ba842cb5880'
        && $blobSha($legacyTerminal) === '893817d00dd00b720b260f8ddb6625bdbcdd5ef7'
        && $blobSha($legacyLink) === 'b9697cb1d18b8c3b5f4398923d53ab58fb27beab',
    'Rollback must restore the accepted v110 shell and owners byte-for-byte.'
);

fwrite(STDOUT, "ProductionV120InviteControllerArchitectureContractTest: {$assertions} rollback assertions passed\n");
