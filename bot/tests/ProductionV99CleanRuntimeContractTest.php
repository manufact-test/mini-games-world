<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v99 source: ' . $path);
    return $content;
};
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entry = $read('app/assets/js/production-clean-entry-v99.js');
$main = $read('app/assets/js/main-v99.js');
$search = $read('app/assets/js/screens/search-screen-v99.js');
$game = $read('app/assets/js/screens/game-screen-v99.js');
$transport = $read('app/assets/js/production-v99-session-transport.js');
$picker = $read('app/assets/js/production-v99-invite-picker-hold.js');
$phpEntry = $read('app/v99.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');

$forbiddenOwners = [
    'initInteractionLatencyCoordinator',
    'initResidualUiGameRaceFixEarly',
    'initCrossGameCoordinator',
    'initTicTacToeTurnFix',
    'initProductionV97RuntimeOwner',
    'initV98UiOwner',
];
foreach ($forbiddenOwners as $owner) {
    $assert(!str_contains($entry . $main, $owner), "V99 clean graph must exclude historical owner: {$owner}");
}

$assert(
    str_contains($entry, 'initSessionOwnershipFix();')
        && str_contains($entry, 'initV99SessionTransport();')
        && str_contains($entry, 'initV99InvitePickerHold();')
        && str_contains($entry, "v99-mvp14-clean-runtime"),
    'V99 entry must contain only identity/passive transport and visual-only helpers.'
);

$assert(
    str_contains($main, "window.__MGW_BUILD__ = 'v99-mvp14-clean-runtime'")
        && str_contains($main, "./screens/search-screen-v99.js?v=99")
        && str_contains($main, "./screens/game-screen-v99.js?v=99")
        && !str_contains($main, 'first-interaction-readiness')
        && !str_contains($main, 'request-guard'),
    'V99 main must load the clean search/game screens without old timing wrappers.'
);

$assert(
    str_contains($search, 'const epoch = ++searchRuntime.epoch;')
        && str_contains($search, 'api.leaveSearch().catch(() => null);')
        && str_contains($search, 'function cancelSearch()')
        && !str_contains($search, "toast('Поиск отменён.')")
        && !str_contains($search, "toast('Поиск остановлен."),
    'Search cancellation must invalidate late responses and stay visually silent.'
);

$assert(
    str_contains($game, 'if (item.pollBusy || item.running || item.queue.length) return;')
        && str_contains($game, 'pollResultIsCurrent(generation, item.generation')
        && str_contains($game, 'item.queue.push({ action });')
        && str_contains($game, 'renderGame(optimistic, viewer, true);')
        && !str_contains($game, 'startLegacyGamePolling'),
    'One queue must own actions and stale polling must never repaint over optimistic state.'
);

$assert(
    str_contains($game, "if (type === 'tictactoe') return buildTicTacToeOptimistic")
        && str_contains($game, "type === 'battleship'")
        && str_contains($game, 'buildBattleshipSetupOptimistic(game, action)')
        && str_contains($game, "window.setTimeout(() => openResultSheet(game, me), 80)"),
    'TTT, Battleship setup and finished-game UI must resolve inside the clean owner without another poll.'
);

$assert(
    str_contains($transport, "PASSIVE_API_ACTIONS")
        && str_contains($transport, 'data.session = passiveSession(data.session);')
        && str_contains($transport, 'inviteExpectationActive()')
        && str_contains($transport, "sessionStorage.setItem(INVITE_EXPECTATION_KEY")
        && str_contains($transport, 'clearInviteExpectation();')
        && str_contains($transport, 'window.setTimeout(() => {')
        && !str_contains($transport, 'queueMicrotask(')
        && !str_contains($transport, 'toast('),
    'Passive reads must stay silent, stale invite intent must be cleared on lock and game entry must run after the legacy invite handler completes.'
);

$assert(
    str_contains($picker, "hold.className = 'sheet mgw-player-picker-hold'")
        && str_contains($picker, "sheet.querySelector('.invite-player-list')")
        && str_contains($picker, "sheet.querySelector('.invite-empty-state')"),
    'The current setup sheet must cover the opponent loader until the final picker exists.'
);

$assert(
    str_contains($phpEntry, 'production-clean-entry-v99.js?v=99')
        && str_contains($phpEntry, 'main-v99.js?v=99')
        && str_contains($phpEntry, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0')
        && str_contains($welcome, '/app/v99.php?v=99'),
    'Telegram launches must use the no-store v99 clean entrypoint.'
);

fwrite(STDOUT, "ProductionV99CleanRuntimeContractTest: {$assertions} assertions passed\n");
