<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'models' => $root . '/app/assets/js/production-v100-optimistic-models.js',
    'cross' => $root . '/app/assets/js/production-cross-game-optimistic.js',
    'v97' => $root . '/app/assets/js/production-v97-models.js',
    'v99' => $root . '/app/assets/js/production-v99-models.js',
    'v102_battleship' => $root . '/app/assets/js/production-v102-battleship-models.js',
];
foreach ($files as $name => $path) {
    if (!is_file($path)) throw new RuntimeException("Cannot read v100 dependency: {$name}");
}

$tempDir = sys_get_temp_dir() . '/mgw_v100_models_' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Cannot create v100 model test directory.');
}

$models = (string)file_get_contents($files['models']);
$models = str_replace("'./production-cross-game-optimistic.js?v=96'", "'./cross.mjs'", $models);
$models = str_replace("'./production-v97-models.js?v=97'", "'./v97.mjs'", $models);
$models = str_replace("'./production-v99-models.js?v=99'", "'./v99.mjs'", $models);
$models = str_replace("'./production-v102-battleship-models.js?v=102'", "'./v102-battleship.mjs'", $models);
file_put_contents($tempDir . '/models.mjs', $models);
file_put_contents($tempDir . '/cross.mjs', (string)file_get_contents($files['cross']));
file_put_contents($tempDir . '/v97.mjs', (string)file_get_contents($files['v97']));
file_put_contents($tempDir . '/v99.mjs', (string)file_get_contents($files['v99']));
file_put_contents($tempDir . '/v102-battleship.mjs', (string)file_get_contents($files['v102_battleship']));

file_put_contents($tempDir . '/test.mjs', <<<'JS'
import { buildV100OptimisticGame, invalidateInFlightPoll, pendingSurfaceDescriptor } from './models.mjs';

let assertions = 0;
function assert(condition, message){
  assertions++;
  if (!condition) throw new Error(message);
}

const players = [
  { id:'me', symbol:'X', side:'black' },
  { id:'other', symbol:'O', side:'white' },
];
const common = { id:'g', status:'active', turn:'me', players, move_timeout_sec:60 };

const ttt = buildV100OptimisticGame({ ...common, board:'---------', board_size:3 }, { type:'cell', cell:0 }, 'me', 'tictactoe');
assert(ttt?.board?.[0] === 'X', 'Tic Tac Toe must paint the mark before the server response.');

const four = buildV100OptimisticGame({ ...common, board:'-'.repeat(42), board_columns:7, board_rows:6 }, { type:'column', column:2 }, 'me', 'four_in_a_row');
assert(four?.board?.[37] !== '-', 'Four in a Row must drop a token immediately.');

const checkersBoard = Array(64).fill('');
checkersBoard[42] = 'w';
const checkers = buildV100OptimisticGame({ ...common, board:checkersBoard, board_size:8, legal_moves:[{ from:42, to:33, capture:false }] }, { type:'move', from:42, to:33 }, 'me', 'checkers');
assert(checkers?.board?.[42] === '' && checkers?.board?.[33] === 'w', 'Checkers must move a piece immediately.');

const reversi = buildV100OptimisticGame({ ...common, board:'-----WB--BW-----', board_size:4, viewer_side:'black', legal_moves:[{ cell:4 }] }, { type:'cell', cell:4 }, 'me', 'reversi');
assert(reversi?.board?.[4] === 'B' && reversi?.board?.[5] === 'B', 'Reversi must place and flip immediately.');

const chessBoard = Array(64).fill('');
chessBoard[52] = 'wP';
const chess = buildV100OptimisticGame({ ...common, board:chessBoard, legal_moves:[{ from:52, to:44 }] }, { type:'move', from:52, to:44 }, 'me', 'chess');
assert(chess?.board?.[52] === '' && chess?.board?.[44] === 'wP', 'Chess must move a piece immediately.');

const go = buildV100OptimisticGame({ ...common, board:'-'.repeat(81), board_size:9, viewer_side:'black' }, { type:'cell', cell:10 }, 'me', 'go');
assert(go?.board?.[10] === 'B', 'Go must place a stone immediately.');

const domino = buildV100OptimisticGame({ ...common, viewer_hand:[{ id:'6-5', a:6, b:5 }], chain:[], open_left:6, open_right:6, move_count:0 }, { type:'play', tile:'6-5', side:'left' }, 'me', 'domino');
assert(domino?.viewer_hand?.length === 0 && domino?.chain?.length === 1, 'Domino must place a tile immediately.');

const setup = buildV100OptimisticGame({ ...common, phase:'setup', my_board:Array(100).fill('water'), my_fleet:[] }, { type:'place_ship', size:2, cell:22, orientation:'h' }, 'me', 'battleship');
assert(setup?.my_board?.[22] === 'ship' && setup?.my_board?.[23] === 'ship', 'Battleship setup must place one complete ship immediately.');

const battle = buildV100OptimisticGame({ ...common, phase:'battle', enemy_board:Array(100).fill('unknown') }, { type:'fire', cell:55 }, 'me', 'battleship');
assert(battle?.pending_fire_cell === 55, 'Battleship battle must expose an immediate pending shot.');
assert(pendingSurfaceDescriptor(battle, 'battleship')?.selector === '[data-battleship-cell="55"]', 'Pending shot must map to the clicked cell.');

const runtime = { games:new Map([['g', { generation:3, interactionGeneration:0 }]]) };
assert(invalidateInFlightPoll(runtime, 'g') === true, 'Pointer interaction must invalidate an in-flight poll.');
assert(runtime.games.get('g').generation === 4, 'Poll generation must advance before click dispatch.');

console.log(`ProductionV100ModelsRuntimeTest: ${assertions} assertions passed`);
JS);

$output = [];
$exitCode = 0;
exec('node ' . escapeshellarg($tempDir . '/test.mjs') . ' 2>&1', $output, $exitCode);
foreach (glob($tempDir . '/*') ?: [] as $file) @unlink($file);
@rmdir($tempDir);
if ($exitCode !== 0) {
    throw new RuntimeException("V100 model runtime test failed:\n" . implode("\n", $output));
}
fwrite(STDOUT, implode("\n", $output) . "\n");
