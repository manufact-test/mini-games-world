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
$terminal = $read('app/assets/js/games/invite-terminal-actions-v110r12.js');
$linkEntry = $read('app/assets/js/games/invite-link-entry-v110r12.js');

$assert(
    str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1123';")
        && str_contains($welcome, "Active canonical path: '/app/v110.php?v=1123'.")
        && str_contains($php, 'main-v110.js?v=1124')
        && str_contains($main, 'main-v110-handoff-shell.js?v=1124'),
    'The canonical Telegram URL must select the final v1124 browser graph.'
);
$assert(
    str_contains($shell, 'notifications-screen-v110r12.js?v=1122')
        && str_contains($shell, 'invite-terminal-actions-v110r12.js?v=1123')
        && str_contains($shell, 'invite-link-entry-v110r12.js?v=1123')
        && str_contains($shell, 'production-v110-presence.js?v=1121')
        && str_contains($shell, 'stats-owner-v110.js?v=1121')
        && str_contains($shell, 'search-screen-v102.js?v=103')
        && str_contains($shell, 'search-invite-reconciliation-v110r12.js?v=1124'),
    'Only the outer graph, serialized search owner and reconciliation bridge advance for v1124; validated owners keep their revisions.'
);
$assert(
    str_contains($terminal, "window.addEventListener('click', handleTerminalAction, true)")
        && !str_contains($terminal, "document.addEventListener('click', handleTerminalAction, true)")
        && !str_contains($terminal, "toast('Приглашение отклонено")
        && !str_contains($terminal, "toast('Приглашение отменено"),
    'The final terminal owner must consume decline/cancel before legacy document handlers and never emit an actor success toast.'
);
$tryStart = strpos($terminal, '  try {');
$catchStart = strpos($terminal, '  } catch (error) {', $tryStart ?: 0);
$successBlock = $tryStart !== false && $catchStart !== false
    ? substr($terminal, $tryStart, $catchStart - $tryStart)
    : '';
$assert(
    $successBlock !== ''
        && !str_contains($successBlock, "new CustomEvent('mgw:notifications-refresh'")
        && str_contains($successBlock, "new CustomEvent('mgw:game-dismissed'"),
    'Successful decline/cancel must not start a stale notifications repaint.'
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
    str_contains($php, 'production-clean-entry-v110.js?v=1120')
        && str_contains($php, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'),
    'The accepted clean entry owner and no-store response must remain unchanged.'
);

fwrite(STDOUT, "ProductionV110R12V1123PublicationContractTest: {$assertions} assertions passed\n");
