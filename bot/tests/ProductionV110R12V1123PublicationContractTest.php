<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$php = $read('app/v110.php');
$main = $read('app/assets/js/main-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');
$historicalTerminal = $read('app/assets/js/games/invite-terminal-actions-v110r12.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$linkEntry = $read('app/assets/js/games/invite-link-entry-v110r12.js');

$assert(
    str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1123';")
        && str_contains($welcome, "Active canonical path: '/app/v110.php?v=1123'.")
        && str_contains($php, 'main-v110.js?v=1130')
        && str_contains($main, 'main-v110-handoff-shell.js?v=1130'),
    'The canonical Telegram URL must select the final v1130 browser graph.'
);
$assert(
    str_contains($shell, 'game-invites-v110.js?v=1130')
        && str_contains($shell, 'notifications-screen-v110r12.js?v=1126')
        && !str_contains($shell, 'invite-terminal-actions-v110r12.js')
        && !str_contains($shell, 'initInviteTerminalActions')
        && str_contains($shell, 'invite-link-entry-v110r12.js?v=1123')
        && str_contains($shell, 'production-v110-presence.js?v=1121')
        && str_contains($shell, 'stats-owner-v110.js?v=1121')
        && str_contains($shell, 'search-screen-v102.js?v=103')
        && str_contains($shell, 'search-invite-reconciliation-v110r12.js?v=1124'),
    'The v1130 graph must advance the canonical invite owner while retaining validated notification, link, presence and search owners.'
);
$assert(
    str_contains($historicalTerminal, "window.addEventListener('click', handleTerminalAction, true)")
        && !str_contains($shell, 'invite-terminal-actions-v110r12.js'),
    'The retired terminal interceptor may remain as historical source but must not execute in the accepted graph.'
);

$performStart = strpos($invites, 'async function performInviteAction(');
$performEnd = strpos($invites, 'async function createRematch(', $performStart ?: 0);
$perform = $performStart !== false && $performEnd !== false
    ? substr($invites, $performStart, $performEnd - $performStart)
    : '';
$assert(
    $perform !== ''
        && !str_contains($perform, "action === 'decline' || action === 'cancel') {\n    closeSheet();")
        && !str_contains($perform, "toast('Приглашение отклонено.')")
        && str_contains($perform, "new CustomEvent('mgw:notification-sync'")
        && str_contains($perform, 'showTerminalInvite(terminalInvite);')
        && !str_contains($perform, "new CustomEvent('mgw:notifications-refresh'"),
    'Successful decline/cancel must terminalize the current surface without closing, actor toast or stale repaint.'
);
$assert(
    str_contains($linkEntry, 'const invite = result?.opened_invite || null;')
        && str_contains($linkEntry, 'showIncomingInvite(invite);')
        && str_contains($linkEntry, 'data-invite-action="accept"')
        && str_contains($linkEntry, 'data-invite-action="decline"')
        && !str_contains($linkEntry, 'currentInvite ='),
    'A Telegram link must paint one complete non-blocking invite sheet.'
);
$assert(
    str_contains($php, 'production-clean-entry-v110.js?v=1121')
        && str_contains($php, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'),
    'The accepted clean entry owner and no-store response must remain unchanged.'
);

fwrite(STDOUT, "ProductionV110R12V1123PublicationContractTest: {$assertions} assertions passed\n");
