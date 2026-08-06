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

$activeInvites = $read('app/assets/js/games/game-invites-v110.js');
$activeNotifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$historicalTerminal = $read('app/assets/js/games/invite-terminal-actions-v110r12.js');
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
    str_contains($main110, 'main-v110-handoff-shell.js?v=1133')
        && str_contains($shell110, 'game-invites-v110.js?v=1133')
        && !str_contains($main110, 'main-v120-invite-controller-shell.js')
        && !str_contains($shell110, 'invite-controller-v120.js')
        && !str_contains($shell110, 'main-v120.js'),
    'Only the accepted v1130 v110 shell may be active after rollback.'
);

$assert(
    str_contains($main120, 'main-v120-invite-controller-shell.js?v=1200')
        && str_contains($controller120, 'data-invite-controller="v120"'),
    'Rejected v120 source may remain for postmortem, but only behind the redirect tombstone.'
);

$assert(
    substr_count($shell110, 'initGameInvites();') === 1
        && substr_count($shell110, 'initNotificationsScreen();') === 1
        && !str_contains($shell110, 'initInviteTerminalActions')
        && !str_contains($shell110, 'invite-terminal-actions-v110r12.js')
        && str_contains($historicalTerminal, "window.addEventListener('click', handleTerminalAction, true)"),
    'The accepted v1130 graph must not reactivate the historical terminal interceptor.'
);

$performStart = strpos($activeInvites, 'async function performInviteAction(');
$performEnd = strpos($activeInvites, 'async function createRematch(', $performStart ?: 0);
$perform = $performStart !== false && $performEnd !== false
    ? substr($activeInvites, $performStart, $performEnd - $performStart)
    : '';
$assert(
    $perform !== ''
        && !str_contains($perform, "action === 'decline' || action === 'cancel') {\n    closeSheet();")
        && str_contains($perform, "new CustomEvent('mgw:notification-sync'")
        && str_contains($perform, 'showTerminalInvite(terminalInvite);')
        && str_contains($activeNotifications, "document.addEventListener('mgw:notification-sync'")
        && str_contains($activeNotifications, 'data-notification-type="${escapeHtml(item.type)}"'),
    'The current v110 owner may evolve after the v120 rollback, but decline/cancel must stay canonical and in place.'
);

$assert(
    $blobSha($historicalTerminal) === '893817d00dd00b720b260f8ddb6625bdbcdd5ef7'
        && $blobSha($legacyLink) === 'b9697cb1d18b8c3b5f4398923d53ab58fb27beab',
    'The rejected historical terminal and link source files must remain intact for postmortem evidence even though the terminal file is inactive.'
);

fwrite(STDOUT, "ProductionV120InviteControllerArchitectureContractTest: {$assertions} assertions passed\n");
