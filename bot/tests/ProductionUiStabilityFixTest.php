<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read stability source: ' . $path);
    return $content;
};

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$index = $read('app/index.html');
$entry = $read('app/assets/js/production-regression-fix-entry.js');
$stability = $read('app/assets/js/production-ui-stability-fix.js');
$coordinator = $read('app/assets/js/production-cross-game-coordinator.js');
$optimistic = $read('app/assets/js/production-cross-game-optimistic.js');
$ticTacToe = $read('app/assets/js/production-tictactoe-turn-fix.js');
$icons = $read('app/assets/js/production-deterministic-icons.js');
$css = $read('app/assets/css/production-v95-consistency.css');

$entryPosition = strpos($index, 'production-regression-fix-entry.js?v=102');
$mainPosition = strpos($index, 'main.js?v=98');
$assert(
    $entryPosition !== false
        && $mainPosition !== false
        && $entryPosition < $mainPosition
        && !str_contains($index, 'main.js?v=96')
        && str_contains($index, 'production-v95-consistency.css?v=95')
        && str_contains($index, 'data-hotfix-build="v98-mvp14-notification-canonical-owner"'),
    'The retained v96 stabilization layer and current consistency stylesheet must load before the active v97 app entry.'
);

$sessionInit = strpos($entry, 'initSessionOwnershipFix();');
$stabilityInit = strpos($entry, 'initProductionUiStabilityFix();');
$coordinatorInit = strpos($entry, 'initCrossGameCoordinator();');
$iconsInit = strpos($entry, 'initDeterministicGameIcons();');
$avatarInit = strpos($entry, 'initStandardAvatarPolicy();');
$assert(
    $sessionInit !== false
        && $stabilityInit !== false
        && $coordinatorInit !== false
        && $iconsInit !== false
        && $avatarInit !== false
        && $sessionInit < $stabilityInit
        && $stabilityInit < $avatarInit
        && str_contains($entry, "window.__MGW_REGRESSION_BUILD__ = 'v96-mvp14-root-cause-stabilization'"),
    'Session ownership, API/UI coordination and deterministic icons must initialize before legacy UI handlers.'
);

$assert(
    str_contains($stability, 'window.fetch = resilientReadFetch;')
        && str_contains($stability, "['bootstrap', 'profile', 'history'].includes(action)")
        && str_contains($stability, "url.pathname.endsWith('/bot/notifications.php')")
        && str_contains($stability, "meta.kind === 'bootstrap'")
        && str_contains($stability, 'degraded_read:true'),
    'Transient read failures must retain the bounded per-user fallback contract.'
);

foreach ([
    'startSearchBtn',
    'startFourSearchBtn',
    'startBattleshipSearchBtn',
    'startCheckersSearchBtn',
    'startReversiSearchBtn',
    'startChessSearchBtn',
    'startGoSearchBtn',
    'startDominoSearchBtn',
] as $buttonId) {
    $assert(str_contains($coordinator, "'{$buttonId}'"), "Missing immediate search transition for {$buttonId}.");
}

$assert(
    str_contains($coordinator, 'api.startSearch = coordinatedStartSearch;')
        && str_contains($coordinator, 'api.gameState = coordinatedGameState;')
        && str_contains($coordinator, 'api.gameAction = coordinatedGameAction;')
        && str_contains($coordinator, 'scheduleCrossGameCoordinatorAfterMain')
        && str_contains($coordinator, 'runtimeByGame')
        && str_contains($coordinator, 'runtime.queue.push(item);')
        && str_contains($coordinator, 'normalizeViewer(result?.me)'),
    'The first frame and pending actions must use one authoritative viewer-aware API owner.'
);

foreach ([
    "type === 'four_in_a_row'",
    "type === 'checkers'",
    "type === 'reversi'",
    "type === 'chess'",
    "type === 'go'",
    "type === 'domino'",
    "type === 'battleship'",
] as $gameBranch) {
    $assert(str_contains($optimistic, $gameBranch), "Missing optimistic action branch: {$gameBranch}.");
}

$assert(
    str_contains($coordinator, 'renderGameSnapshot(optimistic, viewer, true);')
        && str_contains($coordinator, "return 'Ход принят…';")
        && str_contains($coordinator, 'canContinueImmediately(type, game, me)')
        && str_contains($coordinator, 'onAction:nextAction => submitRenderedAction(game.id, nextAction)')
        && str_contains($coordinator, 'mgw-pending-shot'),
    'Every non-Tic-Tac-Toe action must paint feedback while retaining chained interaction callbacks.'
);

$assert(
    str_contains($coordinator, 'installPlayerPickerTransitionGuard();')
        && str_contains($coordinator, "origin.closest('[data-open-player-picker]')")
        && str_contains($coordinator, "document.body.classList.add('mgw-player-picker-transition')")
        && str_contains($css, 'body.mgw-player-picker-transition #sheet .notifications-loading'),
    'The player picker must not paint its intermediate loading card.'
);

$assert(
    str_contains($ticTacToe, "viewerId = String(game.turn || '')")
        && str_contains($ticTacToe, '!button.disabled')
        && str_contains($ticTacToe, "button.textContent.trim() === ''")
        && str_contains($ticTacToe, 'renderBoard(optimisticGame, viewer, cell);'),
    'The first enabled Tic Tac Toe tap must remain immediate.'
);

$assert(
    str_contains($css, '.cell.x::before')
        && str_contains($css, '.cell.x::after')
        && str_contains($css, '.cell.o::before')
        && str_contains($css, '--mgw-ttt-mark-size:36%')
        && str_contains($css, 'transform:translate(-50%,-50%)'),
    'X and O must share one centered deterministic geometry box.'
);

$assert(
    str_contains($css, '.close::before')
        && str_contains($css, '.close::after')
        && str_contains($css, 'width:38px !important')
        && str_contains($css, 'font-size:0 !important'),
    'All close buttons must use one centered fixed-size CSS cross.'
);

foreach (['tictactoe','four_in_a_row','battleship','checkers','reversi','chess','go','domino'] as $gameType) {
    $assert(str_contains($icons, "{$gameType}:"), "Missing deterministic SVG icon for {$gameType}.");
}
$assert(
    str_contains($icons, 'new MutationObserver')
        && str_contains($icons, "icon.querySelector('svg')")
        && str_contains($css, '.game-icon svg'),
    'Game icons must be restored whenever legacy copy rendering erases the actual SVG.'
);

$assert(
    str_contains($stability, 'TECHNICAL_ERROR_PATTERN')
        && str_contains($stability, 'Не удалось связаться с сервером. Подключение восстановится автоматически.'),
    'Raw transport errors must remain normalized.'
);

fwrite(STDOUT, "ProductionUiStabilityFixTest: {$assertions} assertions passed\n");
