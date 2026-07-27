<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v102 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entry = $read('app/assets/js/production-clean-entry-v102.js');
$main = $read('app/assets/js/main-v102.js');
$search = $read('app/assets/js/screens/search-screen-v102.js');
$game = $read('app/assets/js/screens/game-screen-v102.js');
$safe = $read('app/assets/js/screens/game-screen-v102-safe.js');
$router = $read('app/assets/js/games/game-router-v102.js');
$gateway = $read('app/assets/js/production-v100-optimistic-models.js');
$history = $read('app/assets/js/production-v102-history-controller.js');
$share = $read('app/assets/js/production-v102-share-controller.js');
$models = $read('app/assets/js/production-v102-battleship-models.js');
$actions = $read('bot/services/GameActionService.php');
$php = $read('app/v102.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');

$assert(
    str_contains($entry, 'initV102HistoryController();')
        && str_contains($entry, 'initV102ShareController();')
        && !str_contains($entry, 'initV101ShareController();')
        && str_contains($entry, 'initV101PollTuning();')
        && str_contains($entry, 'initV101SpeedRuntime();')
        && str_contains($entry, 'initV99ExplicitLockGuard();'),
    'v102 must replace only history/share targets while retaining accepted speed and session owners.'
);

$assert(
    str_contains($main, "./screens/search-screen-v102.js?v=102")
        && str_contains($main, "./screens/game-screen-v102-safe.js?v=102")
        && !str_contains($main, "./screens/search-screen-v100.js?v=100")
        && !str_contains($main, "./screens/game-screen-v100-safe.js?v=100"),
    'Every current launch path must enter the v102 search/game pair without parallel owners.'
);

$assert(
    str_contains($search, "./game-screen-v102-safe.js?v=102")
        && !str_contains($search, 'game-screen-v100-safe.js')
        && str_contains($search, "if (button.id === 'cancelSearch' || button.id === 'changeSearch')")
        && !str_contains($search, 'Поиск отменён'),
    'Ordinary search must preserve silent cancellation while routing to v102.'
);

$assert(
    str_contains($safe, 'item?.surrenderPending')
        && str_contains($safe, 'Number(item?.queue?.length || 0) > 0')
        && str_contains($game, 'coalesceReplaceableAction(item, action, type, base)')
        && str_contains($game, "String(action?.type || '') === 'randomize_fleet'")
        && str_contains($game, 'const protectedCount = item.running ? 1 : 0;'),
    'Duplicate game snapshots and repeated randomize clicks must not reset or multiply active work.'
);

$optimisticPosition = strpos($game, 'openResultSheet(optimistic, viewer, { pending:true, notify:false });');
$leaveAwaitPosition = strpos($game, 'const result = await api.leaveGame(id);');
$assert(
    $optimisticPosition !== false
        && $leaveAwaitPosition !== false
        && $optimisticPosition < $leaveAwaitPosition
        && str_contains($game, 'setResultActionsDisabled(false);')
        && str_contains($game, 'restoreAuthoritative(item);')
        && str_contains($game, 'startGamePolling(id);'),
    'Voluntary surrender must react before the server wait and safely restore the game on failure.'
);

$assert(
    str_contains($router, "if (gameTypeOf(game) === 'battleship')")
        && str_contains($router, 'renderBaseGameSurface({ game, me, container, onAction });')
        && !str_contains($router, "gameTypeOf(game) === 'tictactoe'")
        && !str_contains($router, "gameTypeOf(game) === 'chess'"),
    'The v102 renderer override must be isolated to Battleship only.'
);

$assert(
    str_contains($gateway, "String(window.__MGW_REGRESSION_BUILD__ || '') === 'v102-mvp14-targeted-regression-repair'")
        && str_contains($gateway, '? buildV102BattleshipSetupOptimistic(game, action)')
        && str_contains($gateway, ': buildBattleshipSetupOptimistic(game, action);'),
    'The shared optimistic gateway must preserve v100/v101 behavior and activate the new fleet model only for v102.'
);

$assert(
    str_contains($history, "origin.closest('#balanceHistoryBtn')")
        && str_contains($history, "origin.closest('#matchHistoryBtn')")
        && str_contains($history, 'event.stopImmediatePropagation();')
        && str_contains($history, 'requestHistoryWithRetry()')
        && str_contains($history, "const text = await response.text();")
        && str_contains($history, "replace(/^\\uFEFF/, '').trim()")
        && !str_contains($history, 'Загружаем историю'),
    'History must validate text responses, retry empty 200 once and never paint the old loader.'
);

$assert(
    str_contains($share, "telegram.onEvent('activated', restoreActiveShareSurface)")
        && str_contains($share, "document.visibilityState === 'visible'")
        && str_contains($share, 'restoreShareSurface(attempt.surface);')
        && str_contains($share, "overlay.classList.add('active')")
        && !str_contains($share, 'Ждём результата отправки')
        && !str_contains($share, '✈️'),
    'Telegram return must restore the prior sheet without adding a loading surface.'
);

$assert(
    str_contains($models, 'game.remaining_to_place =')
        && str_contains($models, 'game.fleet_placed =')
        && str_contains($models, "type === 'randomize_fleet'")
        && str_contains($models, 'createV102RandomFleet()')
        && str_contains($models, 'validShipGeometry(cells)')
        && str_contains($actions, "'battleship' => \$this->applyBattleshipAction")
        && str_contains($actions, "['type' => 'clear_fleet']")
        && str_contains($actions, "'type' => 'place_ship'"),
    'Battleship must update visible summaries immediately and validate the exact random fleet through existing server placement rules.'
);

$assert(
    str_contains($php, 'production-clean-entry-v102.js?v=102')
        && str_contains($php, 'main-v102.js?v=102')
        && str_contains($php, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0')
        && str_contains($welcome, '/app/v102.php?v=102'),
    'Only new no-store Telegram launches may activate v102.'
);

fwrite(STDOUT, "ProductionV102TargetedRegressionContractTest: {$assertions} assertions passed\n");
