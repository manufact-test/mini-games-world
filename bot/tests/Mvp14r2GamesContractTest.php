<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paths = [
    'action' => $root . '/bot/services/GameActionService.php',
    'legacy' => $root . '/bot/services/GameService.php',
    'settlement' => $root . '/bot/services/GameSettlementService.php',
    'four' => $root . '/bot/services/FourInARowService.php',
    'battleship' => $root . '/bot/games/battleship/BattleshipService.php',
    'checkers' => $root . '/bot/games/checkers/CheckersService.php',
    'reversi' => $root . '/bot/games/reversi/ReversiService.php',
    'chess' => $root . '/bot/games/chess/ChessService.php',
    'go' => $root . '/bot/games/go/GoService.php',
    'domino' => $root . '/bot/games/domino/DominoService.php',
    'runtime' => $root . '/bot/services/ChessRuntimeService.php',
    'invite_action' => $root . '/bot/services/invites/GameInviteActionTrait.php',
    'baseline' => $root . '/bot/baseline/JsonGamesBaselineScenario.php',
    'classic' => $root . '/bot/baseline/JsonGamesClassicTrait.php',
    'strategy' => $root . '/bot/baseline/JsonGamesStrategyTrait.php',
    'baseline_settlement' => $root . '/bot/baseline/JsonGamesSettlementTrait.php',
];

$sources = [];
foreach ($paths as $name => $path) {
    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Games source contract file is unavailable: ' . $name . '.');
    }
    $sources[$name] = $source;
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$contains = static fn(string $source, string $needle): bool => str_contains($source, $needle);

foreach ([
    "'tictactoe' =>",
    "'four_in_a_row' =>",
    "'battleship' =>",
    "'checkers' =>",
    "'reversi' =>",
    "'chess' =>",
    "'go' =>",
    "'domino' =>",
] as $route) {
    $assert($contains($sources['action'], $route), 'GameActionService engine route changed: ' . $route);
}
$assert($contains($sources['action'], "if ((string)(\$game['status'] ?? '') === 'finished')"), 'Finished-game action short-circuit changed.');
$assert($contains($sources['action'], "return \$game;"), 'Finished-game action must return stored game state.');
$assert($contains($sources['action'], "throw new RuntimeException('Вы не участвуете в этой игре.');"), 'Game participant guard changed.');

$assert($contains($sources['settlement'], "if (!empty(\$game['payout_done']))"), 'Settlement payout_done guard changed.');
$assert($contains($sources['settlement'], "if ((\$game['status'] ?? '') === 'finished')"), 'Settlement finished guard changed.');
$assert($contains($sources['settlement'], "ceil(\$bank * (float)(\$this->config['commission_rate'] ?? 0.10))"), 'Settlement commission formula changed.');
$assert($contains($sources['settlement'], "'game_refund'"), 'Draw refund ledger category changed.');
$assert($contains($sources['settlement'], "'game_win'"), 'Winner ledger category changed.');
$assert($contains($sources['settlement'], "'type' => 'game_finish'"), 'game_finish transaction contract changed.');
$assert($contains($sources['settlement'], "\$db['system']['fees_' . \$room]"), 'System fee accumulation changed.');
$assert($contains($sources['settlement'], "\$db['users'][\$pid]['status'] = 'idle'"), 'Player release status changed.');
$assert($contains($sources['settlement'], "\$db['users'][\$pid]['current_game_id'] = null"), 'Player current-game release changed.');
$assert($contains($sources['settlement'], "\$game['payout_done'] = true"), 'Settlement completion marker changed.');

$assert($contains($sources['legacy'], "throw new RuntimeException('Сейчас не ваш ход.');"), 'Tic-tac-toe turn guard changed.');
$assert($contains($sources['legacy'], "throw new RuntimeException('Клетка недоступна.');"), 'Tic-tac-toe occupied-cell guard changed.');
$assert($contains($sources['legacy'], "\$this->checkWinner"), 'Tic-tac-toe winner detection changed.');
$assert($contains($sources['legacy'], "\$this->finishGame(\$db, \$game, null, 'draw')"), 'Tic-tac-toe draw finish changed.');
$assert($contains($sources['legacy'], "'time_left' => \$timeLeft"), 'Tic-tac-toe public timer changed.');

$assert($contains($sources['four'], 'private const CONNECT_LENGTH = 4;'), 'Four in a Row connect length changed.');
$assert($contains($sources['four'], "throw new RuntimeException('Выберите доступный столбец.');"), 'Four in a Row column guard changed.');
$assert($contains($sources['four'], "throw new RuntimeException('Этот столбец уже заполнен.');"), 'Four in a Row full-column guard changed.');
$assert($contains($sources['four'], "\$this->winningCells"), 'Four in a Row winner detection changed.');
$assert($contains($sources['four'], "'winning_cells'"), 'Four in a Row public winning cells changed.');

foreach (['randomize_fleet', 'clear_fleet', 'place_ship', 'remove_ship', 'ready', 'fire'] as $action) {
    $assert($contains($sources['battleship'], "'{$action}'"), 'Battleship action changed: ' . $action);
}
$assert($contains($sources['battleship'], "throw new RuntimeException('Вы уже стреляли в эту клетку.');"), 'Battleship repeat-shot guard changed.');
$assert($contains($sources['battleship'], "\$game['turn'] = \$targetId"), 'Battleship miss turn handoff changed.');
$assert($contains($sources['battleship'], "\$game['turn'] = \$shooterId"), 'Battleship hit keeps shooter turn contract changed.');
$assert($contains($sources['battleship'], "\$this->allShipsSunk"), 'Battleship final-ship detection changed.');
$assert($contains($sources['battleship'], "'last_result'"), 'Battleship public last-result field changed.');

$assert($contains($sources['checkers'], "if (\$type !== 'move')"), 'Checkers action type changed.');
$assert($contains($sources['checkers'], "throw new RuntimeException('Сейчас ход соперника.');"), 'Checkers turn guard changed.');
$assert($contains($sources['checkers'], "Есть обязательное взятие. Выберите подсвеченный ход."), 'Checkers mandatory-capture guard changed.');
$assert($contains($sources['checkers'], "'last_captured_cells'"), 'Checkers public captured-cell trace changed.');
$assert($contains($sources['checkers'], 'NO_PROGRESS_DRAW_PLIES = 80'), 'Checkers no-progress draw threshold changed.');

$assert($contains($sources['reversi'], 'private const ALLOWED_SIZES = [6, 8, 10];'), 'Reversi board-size contract changed.');
$assert($contains($sources['reversi'], "['cell', 'place']"), 'Reversi action aliases changed.');
$assert($contains($sources['reversi'], "Сюда нельзя поставить фишку. Выберите подсвеченную клетку."), 'Reversi illegal-placement guard changed.');
$assert($contains($sources['reversi'], "foreach (\$flips as \$flippedCell)"), 'Reversi flip application changed.');
$assert($contains($sources['reversi'], "\$this->finishByCount"), 'Reversi count settlement changed.');

$assert($contains($sources['chess'], "if (\$type !== 'chess_move')"), 'Chess action type changed.');
$assert($contains($sources['chess'], "private const PROMOTIONS = ['q', 'r', 'b', 'n'];"), 'Chess promotion options changed.');
$assert($contains($sources['chess'], "Выберите фигуру для превращения пешки."), 'Chess promotion guard changed.');
$assert($contains($sources['chess'], "Король не должен оставаться под шахом."), 'Chess legal-move/check guard changed.');
$assert($contains($sources['chess'], "'chess_end_reason'"), 'Chess public end-reason field changed.');
$assert($contains($sources['chess'], "\$game['chess_end_reason'] = 'timeout'"), 'Chess timeout end reason changed.');

$assert($contains($sources['go'], 'private const ALLOWED_SIZES = [9, 13];'), 'Go board-size contract changed.');
$assert($contains($sources['go'], 'private const KOMI = 6.5;'), 'Go komi changed.');
$assert($contains($sources['go'], "if (\$type === 'pass')"), 'Go pass action changed.');
$assert($contains($sources['go'], "throw new RuntimeException('Эта точка уже занята.');"), 'Go occupied-point guard changed.');
$assert($contains($sources['go'], "У этой группы не останется свобод."), 'Go suicide guard changed.');
$assert($contains($sources['go'], "Сначала сделайте ход в другом месте."), 'Go ko guard changed.');
$assert($contains($sources['go'], "'final_score'"), 'Go public final-score field changed.');

$assert($contains($sources['domino'], 'private const HAND_SIZE = 7;'), 'Domino hand size changed.');
$assert($contains($sources['domino'], "if (\$type === 'draw')"), 'Domino draw action changed.');
$assert($contains($sources['domino'], "Этой костяшки нет в вашей руке."), 'Domino hand ownership guard changed.');
$assert($contains($sources['domino'], "Эта костяшка не подходит к открытым концам."), 'Domino legal-side guard changed.');
$assert($contains($sources['domino'], "'final_points'"), 'Domino public final-points field changed.');
$assert($contains($sources['domino'], "'opponent_hand' => \$finished"), 'Domino hidden-opponent-hand contract changed.');

foreach (['applyCheckersAction', 'applyReversiAction', 'applyChessAction', 'applyGoAction', 'applyDominoAction'] as $method) {
    $assert($contains($sources['runtime'], 'function ' . $method), 'ChessRuntimeService route changed: ' . $method);
}
$assert($contains($sources['invite_action'], "status'] ?? '') !== 'finished'"), 'Rematch finished-game guard changed.');
$assert($contains($sources['invite_action'], "Реванш доступен только после завершённой партии."), 'Rematch finished-game error changed.');
$assert($contains($sources['invite_action'], "Реванш доступен только с живым соперником."), 'Rematch bot-game guard changed.');
$assert($contains($sources['invite_action'], "findOpenRematchIndex"), 'Open-rematch reuse changed.');

foreach (['tictactoe', 'four_in_a_row', 'battleship', 'checkers', 'reversi', 'chess', 'go', 'domino'] as $gameType) {
    $joined = $sources['baseline'] . $sources['classic'] . $sources['strategy'];
    $assert($contains($joined, "'{$gameType}'"), 'Games baseline scenario missing game type: ' . $gameType);
}
$assert($contains($sources['baseline'], "'latency' => ["), 'Games baseline latency marker missing.');
$assert($contains($sources['baseline'], "'measured' => false"), 'Games baseline must not claim latency evidence.');
$assert($contains($sources['baseline'], "Rejected game action mutated state."), 'Rejected-action mutation guard missing.');
$assert($contains($sources['baseline_settlement'], "if (!empty(\$game['payout_done']) || (string)(\$game['status'] ?? '') === 'finished')"), 'Settlement idempotency guard missing.');
$assert($contains($sources['baseline_settlement'], "'available' => \$available"), 'Games rematch projection missing.');

fwrite(STDOUT, "Mvp14r2GamesContractTest passed: {$assertions} assertions.\n");
