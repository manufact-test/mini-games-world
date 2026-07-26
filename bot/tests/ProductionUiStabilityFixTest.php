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
$ticTacToe = $read('app/assets/js/production-tictactoe-turn-fix.js');
$css = $read('app/assets/css/production-v94-stability.css');

$entryPosition = strpos($index, 'production-regression-fix-entry.js?v=94');
$mainPosition = strpos($index, 'main.js?v=92');
$assert(
    $entryPosition !== false
        && $mainPosition !== false
        && $entryPosition < $mainPosition
        && str_contains($index, 'production-v94-stability.css?v=94')
        && str_contains($index, 'data-hotfix-build="v94-mvp14-ui-stability-fix"'),
    'The v94 stability layer and stylesheet must be cache-busted before the v92 app starts.'
);

$stabilityInit = strpos($entry, 'initProductionUiStabilityFix();');
$avatarInit = strpos($entry, 'initStandardAvatarPolicy();');
$assert(
    $stabilityInit !== false
        && $avatarInit !== false
        && $stabilityInit < $avatarInit
        && str_contains($entry, "window.__MGW_REGRESSION_BUILD__ = 'v94-mvp14-ui-stability-fix'"),
    'The resilient read layer must be installed before the legacy module graph captures fetch.'
);

$assert(
    str_contains($stability, 'window.fetch = resilientReadFetch;')
        && str_contains($stability, "['bootstrap', 'profile', 'history'].includes(action)")
        && str_contains($stability, "url.pathname.endsWith('/bot/notifications.php')")
        && str_contains($stability, "url.pathname.endsWith('/bot/invite-opponents.php')")
        && str_contains($stability, "meta.kind === 'bootstrap'")
        && str_contains($stability, 'degraded_read:true'),
    'Transient read failures must use bounded per-user fallbacks without masking bootstrap authentication.'
);

$showSearch = strpos($stability, "showScreen('search');");
$startSearch = strpos($stability, 'const result = await api.startSearch(');
$assert(
    $showSearch !== false
        && $startSearch !== false
        && $showSearch < $startSearch
        && str_contains($stability, "target.id === 'newOpponent'")
        && str_contains($stability, 'clearGameSurface();')
        && str_contains($stability, 'startSearchPolling();'),
    'Rematch must clear the finished board and open search before the network request.'
);

$assert(
    str_contains($stability, "target.id === 'goHome'")
        && str_contains($stability, "if (!gameScreen.classList.contains('active')) clearGameSurface();")
        && str_contains($stability, "board.replaceChildren();")
        && str_contains($stability, "state.activeGame = null;"),
    'Leaving a game must remove stale board and player DOM before another screen can paint.'
);

$assert(
    str_contains($ticTacToe, "viewerId = String(game.turn || '')")
        && str_contains($ticTacToe, '!button.disabled')
        && str_contains($ticTacToe, "button.textContent.trim() === ''")
        && str_contains($ticTacToe, 'renderBoard(optimisticGame, viewer, cell);'),
    'The first enabled Tic Tac Toe tap must render immediately without waiting for another poll.'
);

$assert(
    str_contains($css, '.cell.o::before')
        && str_contains($css, 'border-radius:50%')
        && str_contains($css, 'font-size:0 !important')
        && str_contains($css, '.size-5 .cell.o::before')
        && str_contains($css, '.size-9 .cell.o::before'),
    'Tic Tac Toe circles must use proportional CSS geometry on every board size.'
);

$assert(
    str_contains($stability, 'TECHNICAL_ERROR_PATTERN')
        && str_contains($stability, 'Не удалось связаться с сервером. Подключение восстановится автоматически.'),
    'Raw English transport errors must not remain visible in the Mini App.'
);

fwrite(STDOUT, "ProductionUiStabilityFixTest: {$assertions} assertions passed\n");
