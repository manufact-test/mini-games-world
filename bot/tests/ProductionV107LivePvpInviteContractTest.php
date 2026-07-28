<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v107 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entry = $read('app/assets/js/production-clean-entry-v107.js');
$main = $read('app/assets/js/main-v107.js');
$timer = $read('app/assets/js/production-v107-timer-pvp.js');
$invite = $read('app/assets/js/production-v107-invite-actions.js');
$php = $read('app/v107.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');

$assert(
    str_contains($entry, 'initV107InviteActions();')
        && str_contains($entry, 'initV107TicTacToeStability();')
        && !str_contains($entry, 'initV105TicTacToeStability')
        && !str_contains($entry, 'initV106TicTacToeTimerAndMobilePin'),
    'v107 must replace the competing v105/v106 Tic Tac Toe repaint owners.'
);

$assert(
    str_contains($main, "import './main-v105.js?v=105';")
        && str_contains($main, "window.__MGW_BUILD__ = 'v107-mvp14-live-pvp-timer-invite-repair'"),
    'v107 main must retain the accepted application graph with a new cache identity.'
);

$assert(
    str_contains($timer, 'source.hidden = isActiveTicTacToe')
        && str_contains($timer, "stable.id = 'timerTextV107'")
        && str_contains($timer, 'serverLeft < localLeft - 1.05'),
    'The visible Tic Tac Toe timer must have one monotonic writer and never jump upward on a stale poll.'
);

$assert(
    str_contains($timer, 'if (!game?.is_bot_game')
        && str_contains($timer, 'runtime.botPending')
        && str_contains($timer, 'paintBotPending(game);')
        && !str_contains($timer, 'new MutationObserver'),
    'Only bot matches may receive the temporary mobile mark pin; live PvP must use the core optimistic surface.'
);

$assert(
    str_contains($invite, 'let launch = activeGameFrom(result);')
        && str_contains($invite, 'launch = await recoverStartedGame(token)')
        && str_contains($invite, "inviteRequest('sync', {token})")
        && str_contains($invite, 'result?.game || result?.active_game'),
    'Invite start must recover the authoritative active game instead of rolling the inviter back after server creation.'
);

$assert(
    str_contains($invite, 'runtime.actionBusy')
        && str_contains($invite, 'runtime.syncGeneration++')
        && str_contains($invite, "String(invite.status || '') === 'awaiting_start'")
        && !str_contains($invite, "String(invite.status || '') === 'accepted'"),
    'Activation sync must not race an invite action and must use the backend awaiting_start status.'
);

$assert(
    str_contains($php, 'production-clean-entry-v107.js?v=107')
        && str_contains($php, 'main-v107.js?v=107')
        && str_contains($php, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'),
    'v107 must have a no-store entrypoint with fresh module URLs.'
);

$assert(
    str_contains($welcome, '/app/v107.php?v=107')
        && str_contains($welcome, '/app/v106.php?v=106'),
    'New Telegram launches must activate v107 while keeping v106 as an explicit rollback entrypoint.'
);

fwrite(STDOUT, "ProductionV107LivePvpInviteContractTest: {$assertions} assertions passed\n");
