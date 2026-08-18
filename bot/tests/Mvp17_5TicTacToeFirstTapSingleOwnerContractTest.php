<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$repoRoot = dirname($root);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$targeted = file_get_contents($repoRoot . '/app/assets/js/production-v110-targeted-interactions.js');
$acceptance = file_get_contents($repoRoot . '/app/assets/js/production-v110-acceptance-runtime.js');
$renderer = file_get_contents($repoRoot . '/app/assets/js/games/tictactoe/renderer.js');
$gameScreen = file_get_contents($repoRoot . '/app/assets/js/screens/game-screen-v102.js');
$manifest = require $repoRoot . '/app/runtime/client/version-manifest.php';

$assert(is_string($targeted), 'Targeted interaction runtime must be readable.');
$assert(!str_contains((string)$targeted, 'ticTacToeActionIsCurrent'), 'Targeted interaction runtime must not own a second Tic Tac Toe move validator.');
$assert(!str_contains((string)$targeted, '#gameBoard[data-game-type="tictactoe"] [data-game-cell]'), 'Targeted interaction runtime must not capture Tic Tac Toe board clicks.');
$assert(str_contains((string)$targeted, 'PLAY_IDS.has'), 'Targeted interaction runtime must retain its accepted session-lock play-button ownership.');

$assert(is_string($acceptance), 'Acceptance runtime must be readable.');
$assert(substr_count((string)$acceptance, "window.addEventListener('click', guardAndTrackTicTacToe, true);") === 1, 'Acceptance runtime must have exactly one registered Tic Tac Toe capture owner.');
$assert(str_contains((string)$acceptance, 'const TTT_FIRST_TAP_GRACE_MS = 400;'), 'First-tap grace must be explicitly bounded to 400ms.');
$assert(str_contains((string)$acceptance, 'const game = state.activeGame;'), 'Tic Tac Toe validation must start from the visible active game snapshot.');
$assert(!str_contains((string)$acceptance, 'const authoritative = item?.authoritative || game;'), 'First-tap validation must not revalidate against a potentially stale secondary authoritative snapshot.');
$assert(str_contains((string)$acceptance, "button.disabled || button.classList.contains('locked') || button.textContent.trim() !== ''"), 'The single owner must trust the visible enabled/unlocked/empty cell contract.');
$assert(str_contains((string)$acceptance, "String(game?.turn || '') !== viewerId"), 'The single owner must still enforce the visible viewer turn.');
$assert(str_contains((string)$acceptance, "board[cell] !== '-'"), 'The single owner must still enforce an empty visible board cell.');
$assert(str_contains((string)$acceptance, 'queueDeferredTicTacToeTap(boardControl, game);'), 'A near-boundary pre-start Tic Tac Toe tap must be retained instead of silently discarded.');
$assert(str_contains((string)$acceptance, 'if (delay === null || delay > TTT_FIRST_TAP_GRACE_MS) return false;'), 'Deferred taps must only be retained inside the bounded first-turn edge window.');
$assert(str_contains((string)$acceptance, 'live.click();'), 'A retained first tap must be replayed once through the normal DOM click path.');
$assert(str_contains((string)$acceptance, 'if (!(live instanceof HTMLButtonElement) || !validTicTacToeMove(live)) return;'), 'Deferred replay must revalidate the live cell before dispatch.');

$assert(is_string($renderer) && str_contains($renderer, "button.addEventListener('click', () =>"), 'Tic Tac Toe renderer must remain the normal click-to-action bridge.');
$assert(is_string($renderer) && str_contains($renderer, "onAction?.({"), 'Tic Tac Toe renderer must continue sending the move to the canonical game action owner.');

$assert(is_string($gameScreen) && str_contains($gameScreen, 'runtime.pointerHoldUntil = Date.now() + 700;'), 'Physical board pointerdown must retain the 700ms poll hold.');
$assert(is_string($gameScreen) && str_contains($gameScreen, 'invalidateInFlightPoll(runtime, activeId);'), 'Physical board pointerdown must still invalidate an in-flight poll before action dispatch.');
$assert(400 < 700, 'Deferred first-tap grace must remain shorter than the existing pointer/poll hold window.');
$assert(is_string($gameScreen) && str_contains($gameScreen, 'buildV100OptimisticGame(base, action, viewer.id, type)'), 'Canonical optimistic move owner must remain unchanged.');

$targetedUrl = (string)($manifest['imports']['./assets/js/production-v110-targeted-interactions.js?v=1102'] ?? '');
$acceptanceUrl = (string)($manifest['imports']['./assets/js/production-v110-acceptance-runtime.js?v=110'] ?? '');
$resultUrl = (string)($manifest['imports']['./assets/js/screens/game-screen-v102.js?v=102'] ?? '');
$reconnectUrl = (string)($manifest['imports']['@mgw/main'] ?? '');

$assert(str_contains($targetedUrl, 'v=1105&zone=unified&ttt=single-owner'), 'Active targeted-interaction URL must preserve its accepted prefix and publish the single-owner fix.');
$assert(str_contains($acceptanceUrl, 'v=130&clock=battleship-setup-single-owner&launch=ready-gated-v2&terminal=clock-stable&input=first-tap-v1'), 'Active acceptance runtime must preserve accepted timing/terminal prefixes and publish the first-tap fix.');
$assert(str_contains($resultUrl, 'v=106&clock=phase-b-single-writer&battleship=leave-guard&mvp17=result-history-economy&live=owner-v3&result=compact-fast-v1'), 'Accepted Result owner/cache identity must remain unchanged.');
$assert($reconnectUrl === './assets/js/main-v110-reconnect-v174.js?v=2', 'Accepted reconnect wrapper must remain frozen.');

fwrite(STDOUT, "Mvp17_5TicTacToeFirstTapSingleOwnerContractTest: {$assertions} assertions passed\n");
