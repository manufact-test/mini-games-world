<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v105 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entry = $read('app/assets/js/production-clean-entry-v105.js');
$main = $read('app/assets/js/main-v105.js');
$ttt = $read('app/assets/js/production-v105-tictactoe-stability.js');
$invite = $read('app/assets/js/production-v105-invite-latency.js');
$watch = $read('bot/invite-watch.php');
$php = $read('app/v105.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');

$v105InvitePosition = strpos($entry, 'initV105InviteLatency();');
$v104InvitePosition = strpos($entry, 'initV104InviteGameControls();');
$assert(
    $v105InvitePosition !== false
        && $v104InvitePosition !== false
        && $v105InvitePosition < $v104InvitePosition
        && str_contains($entry, 'initV105TicTacToeStability();')
        && !str_contains($entry, 'initV104TicTacToeStability();'),
    'v105 must own direct/cancel clicks before v104 and replace only the self-triggering Tic Tac Toe layer.'
);

$assert(
    str_contains($main, "window.__MGW_BUILD__ = 'v105-mvp14-ttt-invite-latency'")
        && str_contains($main, "./screens/game-screen-v102-safe.js?v=102")
        && str_contains($main, "./games/game-invites.js?v=85"),
    'v105 must retain the accepted game screen and base invitation owner.'
);

$assert(
    str_contains($ttt, 'runtime.observer.observe(board, { childList:true });')
        && !str_contains($ttt, 'subtree:true')
        && !str_contains($ttt, 'attributes:true')
        && !str_contains($ttt, 'characterData:true')
        && str_contains($ttt, 'runtime.paintScheduled'),
    'Tic Tac Toe must observe only direct board replacement and coalesce repaint work.'
);

$optimisticPosition = strpos($invite, 'renderOptimisticOwnerSheet(context, opponentName, requestId);');
$directAwaitPosition = strpos($invite, "await inviteRequest('create_direct'");
$assert(
    $optimisticPosition !== false
        && $directAwaitPosition !== false
        && $optimisticPosition < $directAwaitPosition
        && str_contains($invite, 'Приглашение отправлено')
        && str_contains($invite, 'WATCH_INTERVAL_MS = 500'),
    'Direct player invitations must transition immediately and use a fast read-only recipient watch.'
);

$closePosition = strpos($invite, 'closeSheet();');
$cancelAwaitPosition = strpos($invite, "await inviteRequest('cancel'");
$assert(
    $closePosition !== false
        && $cancelAwaitPosition !== false
        && $closePosition < $cancelAwaitPosition
        && str_contains($invite, 'rollbackHtml')
        && str_contains($invite, 'openSheet(rollbackHtml)'),
    'Cancel must close before server wait and restore the exact prior sheet only on failure.'
);

$assert(
    str_contains($watch, '$db->readOnly')
        && !str_contains($watch, '$db->transaction')
        && !str_contains($watch, 'ensureUser(')
        && !str_contains($watch, '=&')
        && str_contains($watch, "(string)(\$invite['status'] ?? '') !== 'pending'"),
    'Fast invite watch must remain read-only and return only a currently pending incoming invite.'
);

$assert(
    str_contains($php, 'production-clean-entry-v105.js?v=105')
        && str_contains($php, 'main-v105.js?v=105')
        && str_contains($php, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0')
        && str_contains($welcome, '/app/v105.php?v=105'),
    'Only new no-store Telegram launches may activate v105.'
);

fwrite(STDOUT, "ProductionV105InviteLatencyContractTest: {$assertions} assertions passed\n");
