<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v103 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entry = $read('app/assets/js/production-clean-entry-v103.js');
$main = $read('app/assets/js/main-v103.js');
$guard = $read('app/assets/js/production-v103-targeted-interactions.js');
$stats = $read('bot/services/StatsService.php');
$presence = $read('bot/services/PresenceService.php');
$php = $read('app/v103.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');

$assert(
    str_contains($entry, 'initV103TargetedInteractions();')
        && str_contains($entry, 'initV102BattleshipBridge();')
        && str_contains($entry, 'initV102HistoryController();')
        && str_contains($entry, 'initV102ShareController();')
        && str_contains($entry, 'initV101SpeedRuntime();'),
    'v103 must add one targeted guard while retaining accepted v101/v102 owners.'
);

$assert(
    str_contains($main, "window.__MGW_BUILD__ = 'v103-mvp14-targeted-ui-turn-lock'")
        && str_contains($main, "./screens/search-screen-v102.js?v=102")
        && str_contains($main, "./screens/game-screen-v102-safe.js?v=102")
        && !str_contains($main, 'game-screen-v99.js'),
    'v103 must preserve the accepted v102 search and game owners.'
);

foreach ([
    'playTicTacToe','playFourInARow','playBattleship','playCheckers',
    'playReversi','playChess','playGo','playDomino',
] as $playId) {
    $assert(str_contains($guard, "'{$playId}'"), "Main-menu lock must cover {$playId}.");
}

$assert(
    str_contains($guard, 'currentV99PassiveLock()')
        && str_contains($guard, 'event.stopImmediatePropagation();')
        && str_contains($guard, "PLAY_IDS.has(String(button.id || ''))")
        && !str_contains($guard, '[data-invite-friend]')
        && !str_contains($guard, '[data-invite-action]'),
    'The retained v103 guard must stop only main Play buttons; v104 owns invitation entry blocking.'
);

$assert(
    str_contains($guard, 'new MutationObserver')
        && str_contains($guard, "document.getElementById('weeklyMatchInfo')")
        && str_contains($guard, "actions.classList.remove('single')")
        && str_contains($guard, '>Подробнее</button>'),
    'The Match-room details control must be restored after every room-card repaint on desktop and mobile.'
);

$assert(
    str_contains($guard, "button.matches('#gameBoard[data-game-type=\"tictactoe\"] [data-game-cell]')")
        && str_contains($guard, 'const authoritative = item?.authoritative || game;')
        && str_contains($guard, "String(authoritative?.turn || '') !== viewerId")
        && str_contains($guard, 'item?.running || Number(item?.queue?.length || 0) > 0')
        && str_contains($guard, "board[cell] !== '-'"),
    'Tic Tac Toe taps must be admitted only from the latest authoritative turn and an empty cell.'
);

$homePosition = strpos($guard, "showScreen('home');");
$requestPosition = strpos($guard, 'await api.leaveGame(String(snapshot.id));');
$dismissPosition = strpos($guard, "new CustomEvent('mgw:game-dismissed')");
$assert(
    $homePosition !== false
        && $requestPosition !== false
        && $homePosition < $requestPosition
        && $dismissPosition > $requestPosition
        && str_contains($guard, 'runtime.leavePending = true;')
        && str_contains($guard, 'enterGame(snapshot, null);'),
    'Surrender must leave the visible game immediately, block new starts locally, and publish dismissal only after server confirmation with rollback on failure.'
);

$assert(
    str_contains($guard, 'abortBackgroundReads();')
        && str_contains($guard, "controller.abort('v103-leave-game')")
        && str_contains($guard, 'if (!result?.session?.locked) clearV99PassiveLock();')
        && str_contains($main, 'window.__MGW_V103_TARGETED_INTERACTIONS__?.leavePending'),
    'Surrender must prevent an obsolete passive stats response from restoring a stale second-device lock.'
);

$assert(
    str_contains($stats, '$this->presence->onlineAccountIds()')
        && str_contains($stats, '$onlineAccounts[$accountId] = true;')
        && str_contains($stats, 'str_starts_with($accountId, \'bot_\')')
        && str_contains($stats, '\'online_players\' => count($onlineAccounts)')
        && str_contains($presence, 'private const ONLINE_WINDOW_SEC = 75;'),
    'Unique-account online counting must remain while Telegram backgrounding uses a bounded isolated presence window.'
);

$assert(
    str_contains($php, 'production-clean-entry-v103.js?v=103')
        && str_contains($php, 'main-v103.js?v=103')
        && str_contains($php, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0')
        && str_contains($welcome, '/app/v104.php?v=104'),
    'The retained no-store v103 entrypoint must remain valid while Telegram advances to v104.'
);

fwrite(STDOUT, "ProductionV103TargetedInteractionContractTest: {$assertions} assertions passed\n");
