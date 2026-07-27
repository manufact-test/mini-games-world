<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v97 source: ' . $path);
    return $content;
};
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entry = $read('app/assets/js/production-regression-fix-entry-v97.js');
$runtime = $read('app/assets/js/production-v97-runtime-owner.js');
$models = $read('app/assets/js/production-v97-models.js');
$bridge = $read('app/assets/js/production-v97-game-poll-bridge.js');
$phpEntry = $read('app/v97.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');
$battleship = $read('bot/games/battleship/BattleshipService.php');

$assert(
    str_contains($entry, "v97-mvp14-single-runtime-owner")
        && str_contains($entry, 'initProductionV97RuntimeOwner();')
        && !str_contains($entry, 'initCrossGameCoordinator')
        && !str_contains($entry, 'initTicTacToeTurnFix'),
    'V97 entry must start one runtime owner and exclude the v95/v94 action owners.'
);

$assert(
    str_contains($runtime, "button.id === 'cancelSearch'")
        && str_contains($runtime, '++searchEpoch;')
        && str_contains($runtime, "toast('Поиск отменён.')")
        && str_contains($runtime, 'rawLeaveSearch().then'),
    'The retained v97 cancellation path must still invalidate the pending start before leaving the server queue.'
);

$assert(
    str_contains($runtime, "button.id === 'notificationsOpen'")
        && str_contains($runtime, 'ownedNotifications(true)')
        && str_contains($runtime, 'latestNotifications')
        && str_contains($runtime, 'event.stopImmediatePropagation();'),
    'The notification sheet must be owned by one fresh snapshot path instead of the stale v92 cache.'
);

$assert(
    str_contains($runtime, 'gameStateInFlight')
        && str_contains($runtime, 'runtime.generation !== generation')
        && str_contains($runtime, 'hasPendingActions(runtime)')
        && str_contains($runtime, 'gameSurfaceFingerprint')
        && str_contains($runtime, 'syncGameChrome'),
    'Polling must preserve optimistic boards and avoid a timer-only DOM replacement.'
);

$assert(
    str_contains($runtime, "document.addEventListener('pointerdown'")
        && str_contains($runtime, 'waitForBoardInteractionWindow()')
        && str_contains($runtime, 'boardInteractionHoldUntil'),
    'Mobile pointer interaction must finish before polling may replace a board surface.'
);

$assert(
    str_contains($runtime, 'validateBattleshipPlacement(base, action)')
        && str_contains($models, 'horizontal touching') === false
        && str_contains($models, 'occupied.has(r * 10 + c)'),
    'Battleship optimistic actions must repeat the server no-touch placement rule.'
);

$assert(
    str_contains($battleship, 'private function canPlaceCells')
        && str_contains($battleship, 'if (isset($occupied[$r * 10 + $c])) return false;'),
    'Battleship server authority must continue rejecting overlap and adjacency.'
);

$assert(
    str_contains($runtime, 'gateSessionResult')
        && str_contains($runtime, 'active_game:null')
        && str_contains($runtime, 'enforceSessionLock')
        && str_contains($runtime, "showScreen('home')"),
    'The retained v97 fallback must still prevent a locked secondary session from rendering a match.'
);

$assert(
    str_contains($bridge, 'window.__MGW_V97_START_GAME_POLLING__ = startGamePolling;')
        && !str_contains($bridge, 'addEventListener'),
    'A found game must start one canonical polling loop.'
);

$assert(
    str_contains($phpEntry, 'production-regression-fix-entry-v97.js?v=97')
        && str_contains($phpEntry, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0')
        && str_contains($phpEntry, 'v97-mvp14-single-runtime-owner'),
    'V97 must retain a no-cache fallback entrypoint.'
);

$assert(
    str_contains($welcome, '/app/v99.php?v=99')
        && !str_contains($welcome, "/app/?v=96"),
    'Telegram launch buttons must advance from the retained v97 fallback to the current v99 entrypoint.'
);

fwrite(STDOUT, "ProductionV97SingleRuntimeOwnerTest: {$assertions} assertions passed\n");
