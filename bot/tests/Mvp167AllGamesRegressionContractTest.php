<?php
declare(strict_types=1);

set_exception_handler(static function (Throwable $error): void {
    $message = str_replace(["\r", "\n"], ' ', $error->getMessage());
    fwrite(STDERR, "::error title=MVP-16.7 acceptance::{$message}\n");
    exit(1);
});

$root = dirname(__DIR__, 2);
require_once $root . '/bot/services/FeatureFlagService.php';
require_once $root . '/bot/services/GameCatalogService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

// MVP-16.7 is an acceptance layer only. Product game code remains frozen here.
// Use an explicit Tic-Tac-Toe size set so this test is deterministic and does not
// depend on a private staging config file.
$catalog = new GameCatalogService(['board_sizes' => [3, 5, 9]]);
$public = [];
foreach ($catalog->publicCatalog() as $game) {
    $public[(string)($game['id'] ?? '')] = $game;
}

$expected = [
    'tictactoe' => [
        'dir' => 'tictactoe',
        'sizes' => [3, 5, 9],
        'default' => 3,
        'columns' => 3,
        'rows' => 3,
        'fixture' => 'games_tictactoe_draw.json',
    ],
    'four_in_a_row' => [
        'dir' => 'four-in-a-row',
        'sizes' => [6, 7, 8],
        'default' => 7,
        'columns' => 7,
        'rows' => 6,
        'fixture' => 'games_four_in_a_row_win.json',
    ],
    'battleship' => [
        'dir' => 'battleship',
        'sizes' => [10],
        'default' => 10,
        'columns' => 10,
        'rows' => 10,
        'fixture' => 'games_battleship_final_shot.json',
    ],
    'checkers' => [
        'dir' => 'checkers',
        'sizes' => [8],
        'default' => 8,
        'columns' => 8,
        'rows' => 8,
        'fixture' => 'games_checkers_capture.json',
    ],
    'reversi' => [
        'dir' => 'reversi',
        'sizes' => [6, 8, 10],
        'default' => 8,
        'columns' => 8,
        'rows' => 8,
        'fixture' => 'games_reversi_count_finish.json',
    ],
    'chess' => [
        'dir' => 'chess',
        'sizes' => [8],
        'default' => 8,
        'columns' => 8,
        'rows' => 8,
        'fixture' => 'games_chess_timeout.json',
    ],
    'go' => [
        'dir' => 'go',
        'sizes' => [9, 13],
        'default' => 9,
        'columns' => 9,
        'rows' => 9,
        'fixture' => 'games_go_two_passes.json',
    ],
    'domino' => [
        'dir' => 'domino',
        'sizes' => [7],
        'default' => 7,
        'columns' => 7,
        'rows' => 1,
        'fixture' => 'games_domino_empty_hand.json',
    ],
];

$assert(count($public) === 8, 'Public catalog must expose exactly the eight accepted runtime games.');
$assert(array_keys($public) === array_keys($expected), 'Public catalog game order/identity changed.');

foreach ($expected as $gameType => $contract) {
    $assert(isset($public[$gameType]), 'Public catalog missing game: ' . $gameType);
    $game = $public[$gameType];

    $assert(($game['board_sizes'] ?? null) === $contract['sizes'], $gameType . ': board sizes changed.');
    $assert(($game['default_board_size'] ?? null) === $contract['default'], $gameType . ': default board size changed.');
    $assert(($game['board_columns'] ?? null) === $contract['columns'], $gameType . ': board columns changed.');
    $assert(($game['board_rows'] ?? null) === $contract['rows'], $gameType . ': board rows changed.');
    $assert($catalog->normalizeBoardSize($gameType, -1) === $contract['default'], $gameType . ': invalid-size normalization changed.');

    $frontendDir = $root . '/app/assets/js/games/' . $contract['dir'];
    foreach (['entry.js', 'meta.js', 'renderer.js', 'rules.js'] as $asset) {
        $path = $frontendDir . '/' . $asset;
        $assert(is_file($path) && filesize($path) > 0, $gameType . ': frontend asset missing/empty: ' . $asset);
    }

    $definition = $root . '/bot/games/' . $contract['dir'] . '/definition.php';
    $assert(is_file($definition) && filesize($definition) > 0, $gameType . ': backend definition missing/empty.');

    $fixture = $root . '/bot/tests/fixtures/mvp14r2/' . $contract['fixture'];
    $assert(is_file($fixture) && filesize($fixture) > 0, $gameType . ': frozen mechanics fixture missing/empty.');
}

// The frozen mechanics suite is the authoritative game-specific behavioral layer:
// all eight fixtures reach a terminal state, settle once, release both players and
// expose a human rematch. This acceptance contract requires those suites to remain.
foreach (['Mvp14r2GamesContractTest.php', 'Mvp14r2GamesBaselineTest.php'] as $suite) {
    $path = $root . '/bot/tests/' . $suite;
    $assert(is_file($path) && filesize($path) > 0, 'Required all-games mechanics suite missing: ' . $suite);
}

$api = file_get_contents($root . '/bot/api.php');
$invites = file_get_contents($root . '/bot/invites.php');
$leaveLifecycle = file_get_contents($root . '/app/assets/js/production-v110-match-lifecycle.js');
$rematchClient = file_get_contents($root . '/app/assets/js/games/game-invites-v110.js');
foreach (['api' => $api, 'invites' => $invites, 'leave lifecycle' => $leaveLifecycle, 'rematch client' => $rematchClient] as $name => $source) {
    $assert(is_string($source) && $source !== '', 'Shared game lifecycle source unavailable: ' . $name);
}

// Reconnect/restore is shared across engines: bootstrap returns active_game and
// game_state resolves the requested/current participant game rather than routing
// through a game-specific reconnect implementation.
$assert(str_contains($api, "'active_game' => \$active ? \$games->publicGame(\$active, \$userId) : null"), 'Bootstrap active-game restore contract changed.');
$assert(str_contains($api, "case 'game_state':"), 'Shared game_state reconnect route missing.');
$assert(str_contains($api, "\$game = \$games->findActiveGameForUser(\$data, \$userId);"), 'Shared active-game reconnect lookup changed.');

// Search/start and leave are shared lifecycle routes for every engine.
$assert(str_contains($api, "case 'start_search':"), 'Shared start_search route missing.');
$leaveCase = strpos($api, "case 'leave_game':");
$leaveSurrender = $leaveCase === false ? false : strpos($api, '\$game = \$games->surrenderGame(\$data, \$user, \$gameId);', $leaveCase);
$leaveRelease = $leaveSurrender === false ? false : strpos($api, '\$sessions->releaseIfCurrent(\$user, \$sessionId);', $leaveSurrender);
$assert(
    $leaveCase !== false && $leaveSurrender !== false && $leaveRelease !== false && $leaveCase < $leaveSurrender && $leaveSurrender < $leaveRelease,
    'Authoritative leave_game surrender/session-release order changed.'
);

$leaveOwner = strpos($leaveLifecycle, 'function surrenderToHome(game)');
$homeBeforeLeave = $leaveOwner === false ? false : strpos($leaveLifecycle, "showScreen('home');", $leaveOwner);
$leaveRequest = $homeBeforeLeave === false ? false : strpos($leaveLifecycle, 'const result = await api.leaveGame(String(snapshot.id));', $homeBeforeLeave);
$assert(
    $leaveOwner !== false && $homeBeforeLeave !== false && $leaveRequest !== false && $homeBeforeLeave < $leaveRequest,
    'Client leave must preserve immediate home transition before authoritative leave request.'
);
$assert(str_contains($leaveLifecycle, 'releaseBarrier:null'), 'Leave release barrier state missing.');
$assert(str_contains($leaveLifecycle, 'if (runtime.leavePending) return runtime.releaseBarrier;'), 'Repeated leave must reuse the release barrier.');
$assert(str_contains($leaveLifecycle, 'runtime.releaseBarrier = completion;'), 'Leave completion must publish the release barrier.');
$assert(str_contains($leaveLifecycle, 'if (runtime.releaseBarrier === completion) runtime.releaseBarrier = null;'), 'Leave completion must clear the release barrier.');
$assert(str_contains($leaveLifecycle, 'quarantineGameActions(snapshot.id);'), 'Leave must quarantine pending game actions.');
$assert(str_contains($leaveLifecycle, 'retireGameRuntime(snapshot.id);'), 'Successful leave must retire the old game runtime.');
$assert(str_contains($leaveLifecycle, 'releaseGameActionQuarantine(snapshot.id);'), 'Failed leave must release action quarantine.');
$assert(str_contains($leaveLifecycle, 'enterGame(snapshot, viewer);'), 'Failed leave must restore the game view.');
$assert(!str_contains($leaveLifecycle, 'queueSearchAfterRelease'), 'Removed queued-search leave implementation must not return.');
$assert(!str_contains($leaveLifecycle, 'window.queueMicrotask(() => queuedButton.click());'), 'Removed queued-search replay must not return.');

// Direct invite, accept/start and rematch all preserve gameType/boardSize in the
// shared invite service; there is no per-engine invite fork to validate separately.
foreach (['create_direct', 'accept', 'start', 'rematch'] as $inviteAction) {
    $assert(str_contains($invites, "case '{$inviteAction}':"), 'Shared invite lifecycle action missing: ' . $inviteAction);
}
$assert(str_contains($invites, "\$gameType = clean_string(\$payload['gameType'] ?? 'tictactoe', 60);"), 'Invite gameType propagation changed.');
$assert(str_contains($invites, "\$boardSize = (int)(\$payload['boardSize'] ?? 3);"), 'Invite boardSize propagation changed.');
$assert(str_contains($rematchClient, "inviteRequest('rematch', { gameId })"), 'Client rematch request owner changed.');

// Guard against accidentally treating the historical loader smoke as formal 16.7
// mechanics evidence: it is allowed to remain a loader smoke, while the suites above
// carry game-specific behavioral acceptance.
$loaderSmoke = file_get_contents($root . '/e2e/staging/phase-b-all-games-loader.spec.mjs');
$assert(is_string($loaderSmoke) && $loaderSmoke !== '', 'Historical all-games loader smoke missing.');
$assert(str_contains($loaderSmoke, 'boardSize:3'), 'Historical loader smoke shape changed; reassess MVP-16.7 evidence layering.');

fwrite(STDOUT, "Mvp167AllGamesRegressionContractTest passed: {$assertions} assertions.\n");
