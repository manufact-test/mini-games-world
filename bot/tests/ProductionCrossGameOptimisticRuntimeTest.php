<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sourcePath = $root . '/app/assets/js/production-cross-game-optimistic.js';
$source = file_get_contents($sourcePath);
if (!is_string($source)) {
    throw new RuntimeException('Cannot read cross-game optimistic module.');
}

$tempDir = sys_get_temp_dir() . '/mgw_cross_game_' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Cannot create temporary cross-game test directory.');
}

$modulePath = $tempDir . '/optimistic.mjs';
$testPath = $tempDir . '/test.mjs';
file_put_contents($modulePath, $source);

$test = <<<'JS'
import { buildOptimisticGame } from './optimistic.mjs';

let assertions = 0;
function assert(condition, message){
  assertions++;
  if (!condition) throw new Error(message);
}
const players = (a = 'X', b = 'O') => [
  { id:'u1', name:'One', symbol:a },
  { id:'u2', name:'Two', symbol:b },
];
const active = extra => ({ id:'g1', status:'active', turn:'u1', players:players(), move_timeout_sec:60, ...extra });

{
  const game = active({
    game_type:'four_in_a_row',
    board_columns:7,
    board_rows:6,
    board:'-'.repeat(42),
    players:players('R','Y'),
  });
  const next = buildOptimisticGame(game, { type:'column', column:3 }, 'u1', 'four_in_a_row');
  assert(next.board[38] === 'R', 'Four in a Row disc must drop into the lowest empty cell.');
  assert(next.turn === 'u2', 'Four in a Row must pass the optimistic turn.');
}

{
  const board = Array(64).fill('');
  board[40] = 'w';
  const game = active({
    game_type:'checkers',
    board,
    legal_moves:[{ from:40, to:33, capture:false }],
  });
  const next = buildOptimisticGame(game, { type:'move', from:40, to:33 }, 'u1', 'checkers');
  assert(next.board[40] === '' && next.board[33] === 'w', 'Checkers piece must move immediately.');
  assert(next.legal_moves.length === 0 && next.turn === 'u2', 'Checkers must lock stale legal moves and pass the turn.');
}

{
  const board = Array(64).fill('-');
  board[27] = 'W';
  board[28] = 'B';
  board[35] = 'B';
  board[36] = 'W';
  const game = active({
    game_type:'reversi',
    board_size:8,
    board:board.join(''),
    players:players('B','W'),
  });
  const next = buildOptimisticGame(game, { type:'cell', cell:19 }, 'u1', 'reversi');
  assert(next.board[19] === 'B' && next.board[27] === 'B', 'Reversi must place and flip immediately.');
  assert(next.black_count === 4 && next.white_count === 1, 'Reversi counts must match the optimistic board.');
}

{
  const board = Array(64).fill('');
  board[52] = 'wP';
  const game = active({
    game_type:'chess',
    board,
    legal_moves:[{ from:52, to:36, capture:false, promotion_required:false, castle:'', en_passant:false }],
  });
  const next = buildOptimisticGame(game, { type:'chess_move', from:52, to:36 }, 'u1', 'chess');
  assert(next.board[52] === '' && next.board[36] === 'wP', 'Chess move must paint immediately.');
  assert(next.last_move.from === 52 && next.last_move.to === 36, 'Chess last move must be published.');
}

{
  const game = active({
    game_type:'go',
    board_size:9,
    board:'-'.repeat(81),
    players:players('B','W'),
  });
  const next = buildOptimisticGame(game, { type:'cell', cell:0 }, 'u1', 'go');
  assert(next.board[0] === 'B', 'Go stone must appear immediately.');
  assert(next.last_move.cell === 0 && next.turn === 'u2', 'Go must publish the move and pass the turn.');
}

{
  const tile = { id:'6-3', a:6, b:3 };
  const game = active({
    game_type:'domino',
    viewer_hand:[tile, { id:'1-1', a:1, b:1 }],
    chain:[{ tile:'6-6', left:6, right:6, player_id:'u2', move_number:1 }],
    open_left:6,
    open_right:6,
    move_count:1,
  });
  const next = buildOptimisticGame(game, { type:'play', tile:'6-3', side:'right' }, 'u1', 'domino');
  assert(next.viewer_hand.length === 1 && next.chain.length === 2, 'Domino tile must leave the hand and enter the chain.');
  assert(next.open_right === 3 && next.turn === 'u2', 'Domino open end and turn must update.');
}

{
  const game = active({
    game_type:'battleship',
    my_board:Array(100).fill('water'),
    my_fleet:[],
  });
  const next = buildOptimisticGame(game, { type:'place_ship', size:2, cell:0, orientation:'h' }, 'u1', 'battleship');
  assert(next.my_board[0] === 'ship' && next.my_board[1] === 'ship', 'Battleship placement must appear immediately.');
  assert(next.my_fleet.length === 1, 'Battleship fleet preview must include the placed ship.');
}

console.log(`ProductionCrossGameOptimisticRuntimeTest: ${assertions} assertions passed`);
JS;

file_put_contents($testPath, $test);
$output = [];
$exitCode = 0;
exec('node ' . escapeshellarg($testPath) . ' 2>&1', $output, $exitCode);

@unlink($testPath);
@unlink($modulePath);
@rmdir($tempDir);

if ($exitCode !== 0) {
    throw new RuntimeException("Cross-game optimistic runtime test failed:\n" . implode("\n", $output));
}

fwrite(STDOUT, implode("\n", $output) . "\n");
