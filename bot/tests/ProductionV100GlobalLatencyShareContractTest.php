<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v100 source: ' . $path);
    return $content;
};
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entry = $read('app/assets/js/production-clean-entry-v100.js');
$main = $read('app/assets/js/main-v100.js');
$search = $read('app/assets/js/screens/search-screen-v100.js');
$searchBridge = $read('app/assets/js/production-v100-search-event-bridge.js');
$game = $read('app/assets/js/screens/game-screen-v100.js');
$safeGame = $read('app/assets/js/screens/game-screen-v100-safe.js');
$models = $read('app/assets/js/production-v100-optimistic-models.js');
$share = $read('app/assets/js/production-v100-share-controller.js');
$phpEntry = $read('app/v100.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');

$assert(
    str_contains($entry, 'initV100ShareController();')
        && str_contains($entry, 'initV100SearchEventBridge();')
        && str_contains($entry, "v100-mvp14-global-latency-share")
        && !str_contains($entry, 'initProductionV97RuntimeOwner')
        && !str_contains($entry, 'initV98UiOwner'),
    'V100 entry must install one share owner and one event bridge without restoring historical runtime owners.'
);

$assert(
    str_contains($main, "./screens/search-screen-v100.js?v=100")
        && str_contains($main, "./screens/game-screen-v100-safe.js?v=100")
        && str_contains($main, "window.__MGW_BUILD__ = 'v100-mvp14-global-latency-share'")
        && !str_contains($main, "./screens/search-screen-v99.js")
        && !str_contains($main, "./screens/game-screen-v99.js"),
    'V100 must route ordinary matchmaking and invitation entry into the same safe game runtime.'
);

$assert(
    str_contains($search, "from './game-screen-v100-safe.js?v=100'")
        && str_contains($search, "window.__MGW_V100_SEARCH_RUNTIME__")
        && str_contains($search, "document.addEventListener('mgw:v100-search-request'")
        && !str_contains($search, "from './game-screen-v99.js"),
    'Matchmaking results must never bypass v100 through the retained v99 game screen.'
);

$assert(
    str_contains($searchBridge, "document.addEventListener('mgw:v99-search-request'")
        && str_contains($searchBridge, "new CustomEvent('mgw:v100-search-request'"),
    'Retained result-sheet actions must reach the v100 search owner exactly once.'
);

$assert(
    str_contains($safeGame, "from './game-screen-v100.js?v=100'")
        && str_contains($safeGame, 'if (item?.running || Number(item?.queue?.length || 0) > 0) return;')
        && str_contains($safeGame, 'enterBaseGame(game, me);'),
    'Repeated invite/search game-entry signals must not reset a pending local action queue.'
);

$assert(
    str_contains($game, "document.addEventListener('pointerdown'")
        && str_contains($game, 'invalidateInFlightPoll(runtime, activeId);')
        && str_contains($game, 'buildV100OptimisticGame(base, action, viewer.id, type)')
        && str_contains($game, 'pendingSurfaceDescriptor(game, type)')
        && str_contains($game, "node.classList.add(descriptor.className)")
        && str_contains($game, "surface.classList.add('mgw-action-pending')"),
    'One game runtime must invalidate in-flight polling and render immediate action state for every game.'
);

$assert(
    str_contains($models, "type === 'tictactoe'")
        && str_contains($models, "type === 'battleship'")
        && str_contains($models, 'normalizeSideSymbols(game, type)')
        && str_contains($models, 'buildOptimisticGame(modelGame, action, id, type)')
        && str_contains($models, 'optimistic.pending_fire_cell = cell;')
        && str_contains($models, 'invalidateInFlightPoll'),
    'The v100 model gateway must normalize side-based games, cover shared optimistic models and expose Battleship pending shots.'
);

$resetPosition = strpos($share, 'resetButton(attempt);');
$openPosition = strpos($share, 'openPreparedMessage(tg, preparedId, attempt);');
$assert(
    str_contains($share, "origin.closest('[data-create-link-invite]')")
        && str_contains($share, "origin.closest('[data-invite-friend]')")
        && str_contains($share, 'event.stopImmediatePropagation();')
        && $resetPosition !== false
        && $openPosition !== false
        && $resetPosition < $openPosition
        && !str_contains($share, 'Готовим ссылку')
        && !str_contains($share, 'Ждём результата отправки')
        && !str_contains($share, 'notifications-loading')
        && !str_contains($share, '✈️'),
    'Telegram sharing must keep the setup sheet visible and never paint the old airplane/loading sheet.'
);

$assert(
    str_contains($share, 'tg.shareMessage(preparedId, result => finish(Boolean(result)))')
        && str_contains($share, "if (sent === false)")
        && str_contains($share, "inviteRequest('discard_draft'")
        && str_contains($share, 'renderOwnerWaiting(attempt.draft);')
        && str_contains($share, 'const gameType = String(lastGameType'),
    'Share callback must reconcile send/cancel asynchronously and preserve the exact invited game type.'
);

$assert(
    str_contains($phpEntry, 'production-clean-entry-v100.js?v=100')
        && str_contains($phpEntry, 'main-v100.js?v=100')
        && str_contains($phpEntry, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0')
        && str_contains($welcome, '/app/v101.php?v=101'),
    'The retained no-store v100 entrypoint must remain valid while Telegram advances to v101.'
);

fwrite(STDOUT, "ProductionV100GlobalLatencyShareContractTest: {$assertions} assertions passed\n");
