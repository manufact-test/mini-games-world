<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v106 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entry = $read('app/assets/js/production-clean-entry-v106.js');
$main = $read('app/assets/js/main-v106.js');
$invite = $read('app/assets/js/production-v106-invite-actions.js');
$timer = $read('app/assets/js/production-v106-timer-mobile.js');
$clock = $read('bot/game-clock.php');
$registry = $read('bot/runtime/ProductionPrimaryApplicationEntrypoints.php');
$php = $read('app/v106.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');

$assert(
    str_contains($entry, "import './production-clean-entry-v105.js?v=105';")
        && str_contains($entry, 'initV106InviteActions();')
        && str_contains($entry, 'initV106TicTacToeTimerAndMobilePin();')
        && str_contains($entry, "v106-mvp14-invite-timer-mobile-stability"),
    'v106 must retain v105 and add only the focused invite/timer/mobile layer.'
);

$assert(
    str_contains($main, "import './main-v105.js?v=105';")
        && str_contains($main, "window.__MGW_BUILD__ = 'v106-mvp14-invite-timer-mobile-stability'"),
    'v106 main must retain the accepted v105 application graph.'
);

$listener = strpos($invite, "window.addEventListener('click', ownInviteAction, true)");
$startSurface = strpos($invite, 'showPendingGameLaunch(summary);');
$awaitAction = strpos($invite, 'await inviteRequest(action, {token});');
$assert(
    $listener !== false
        && $startSurface !== false
        && $awaitAction !== false
        && $startSurface < $awaitAction
        && str_contains($invite, "['accept','start','decline','cancel']")
        && str_contains($invite, "enterGame(result.game, result.me || null);"),
    'Accepted invite actions must be owned at window capture, transition immediately and use the current safe game entry.'
);

$assert(
    str_contains($invite, "tg.onEvent('activated'")
        && str_contains($invite, "inviteRequest('open_link', {token:launchToken})")
        && str_contains($invite, "inviteRequest('sync', {})")
        && str_contains($invite, 'renderIncomingInvite(invite);')
        && str_contains($invite, 'renderOwnerReady(invite);'),
    'An already-open Mini App must refresh a newly activated link invitation without requiring a restart.'
);

$assert(
    str_contains($invite, 'suppressSelfConfirmation(action);')
        && str_contains($invite, 'installSelfToastFilter();')
        && str_contains($invite, 'SELF_CONFIRMATIONS')
        && !str_contains($invite, "toast(action === 'decline'"),
    'User-initiated cancel/decline confirmations must stay silent while errors and external events remain visible.'
);

$assert(
    str_contains($timer, 'const TICK_MS = 200;')
        && str_contains($timer, 'CLOCK_URL')
        && str_contains($timer, "runtime.clockRequests.get(String(game.id)) === undefined")
        && str_contains($timer, 'window.requestAnimationFrame(frame)')
        && str_contains($timer, 'window.__MGW_V105_TICTACTOE__?.pending')
        && !str_contains($timer, 'new MutationObserver'),
    'The bot clock must be locally smooth, arm once and pin the mobile mark without another observer loop.'
);

$assert(
    str_contains($clock, 'StorageFactory::createJson(')
        && str_contains($clock, "'v106_first_turn_clock_armed_at'")
        && str_contains($clock, "\$game['turn_started_at'] = \$now;")
        && str_contains($clock, "\$games->publicGame(\$game, \$userId)")
        && str_contains($registry, "'bot/game-clock.php' => 'game_clock'"),
    'The first bot clock must use the guarded production storage context and return an authoritative public game.'
);

$assert(
    str_contains($php, 'production-clean-entry-v106.js?v=106')
        && str_contains($php, 'main-v106.js?v=106')
        && str_contains($php, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0')
        && str_contains($welcome, '/app/v106.php?v=106')
        && str_contains($welcome, '/app/v105.php?v=105'),
    'New Telegram launches must activate no-store v106 while retaining an explicit v105 rollback reference.'
);

fwrite(STDOUT, "ProductionV106InviteTimerContractTest: {$assertions} assertions passed\n");
