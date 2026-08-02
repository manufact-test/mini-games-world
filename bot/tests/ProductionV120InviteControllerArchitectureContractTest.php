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
$v120 = $read('app/v120.php');
$main110 = $read('app/assets/js/main-v110.js');
$shell110 = $read('app/assets/js/main-v110-handoff-shell.js');
$main120 = $read('app/assets/js/main-v120.js');
$controller120 = $read('app/assets/js/games/invite-controller-v120.js');

$legacyInvites = $read('app/assets/js/games/game-invites-v110.js');
$legacyNotifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$legacyTerminal = $read('app/assets/js/games/invite-terminal-actions-v110r12.js');
$legacyLink = $read('app/assets/js/games/invite-link-entry-v110r12.js');

$assert(
    str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1123';")
        && str_contains($v120, "\$target = '/app/v110.php?v=1123';")
        && str_contains($v120, "header('Location: ' . \$target, true, 302);")
        && !str_contains($v120, 'main-v120.js')
        && !str_contains($v120, 'index.html'),
    'The rejected v120 entry must be a redirect-only tombstone and can never execute its controller.'
);

$assert(
    str_contains($main110, 'main-v110-handoff-shell.js?v=1123')
        && !str_contains($main110, 'main-v120-invite-controller-shell.js')
        && !str_contains($shell110, 'invite-controller-v120.js')
        && !str_contains($shell110, 'main-v120.js'),
    'Only the accepted v110 shell may be active after rollback.'
);

$assert(
    str_contains($main120, 'main-v120-invite-controller-shell.js?v=1200')
        && str_contains($controller120, 'data-invite-controller="v120"'),
    'Rejected v120 source may remain for postmortem, but only behind the redirect tombstone.'
);

$assert(
    $blobSha($shell110) === '5c88f75179063945c15bf4f409bb32812b98cca8'
        && $blobSha($legacyInvites) === '4377c912b2a85d2e0144c5631115dcea96b5d6b1'
        && $blobSha($legacyNotifications) === '98df0056932f94b7fac1bea99be75ba842cb5880'
        && $blobSha($legacyTerminal) === '893817d00dd00b720b260f8ddb6625bdbcdd5ef7'
        && $blobSha($legacyLink) === 'b9697cb1d18b8c3b5f4398923d53ab58fb27beab',
    'The accepted v110 invitation graph must remain byte-for-byte unchanged.'
);

fwrite(STDOUT, "ProductionV120InviteControllerArchitectureContractTest: {$assertions} assertions passed\n");
