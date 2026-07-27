<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/production-v97-models.js');
if (!is_string($source)) {
    throw new RuntimeException('Cannot read v97 model module.');
}

$tempDir = sys_get_temp_dir() . '/mgw_v97_models_' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Cannot create v97 model test directory.');
}

$modulePath = $tempDir . '/models.mjs';
$testPath = $tempDir . '/test.mjs';
file_put_contents($modulePath, $source);

$test = <<<'JS'
import {
  buildTicTacToeOptimistic,
  gameSurfaceFingerprint,
  validateBattleshipPlacement,
} from './models.mjs';

let assertions = 0;
function assert(condition, message){
  assertions++;
  if (!condition) throw new Error(message);
}

const ttt = {
  id:'g1',
  status:'active',
  turn:'u1',
  board:'---------',
  move_timeout_sec:60,
  players:[
    { id:'u1', symbol:'X' },
    { id:'u2', symbol:'O' },
  ],
};
const moved = buildTicTacToeOptimistic(ttt, { type:'cell', cell:4 }, 'u1');
assert(moved?.board === '----X----', 'Tic Tac Toe mark must be painted immediately.');
assert(moved?.turn === 'u2', 'Tic Tac Toe optimistic state must pass the turn.');
assert(ttt.board === '---------', 'Optimistic model must not mutate the authoritative input.');
assert(buildTicTacToeOptimistic(ttt, { type:'cell', cell:4 }, 'u2') === null, 'Wrong player must not receive an optimistic move.');

const fingerprintA = gameSurfaceFingerprint({ ...moved, time_left:59, updated_at:'a' }, 'u1');
const fingerprintB = gameSurfaceFingerprint({ ...moved, time_left:40, updated_at:'b' }, 'u1');
assert(fingerprintA === fingerprintB, 'Timer-only polling must not replace the game board.');

const sea = {
  status:'active',
  phase:'setup',
  my_board:Array(100).fill('water'),
  my_fleet:[{ id:'ship-a', size:2, cells:[0,1] }],
};
sea.my_board[0] = 'ship';
sea.my_board[1] = 'ship';
assert(
  validateBattleshipPlacement(sea, { type:'place_ship', size:1, cell:0, orientation:'h' }) === false,
  'Battleship placement must reject overlap.'
);
assert(
  validateBattleshipPlacement(sea, { type:'place_ship', size:1, cell:2, orientation:'h' }) === false,
  'Battleship placement must reject horizontal touching.'
);
assert(
  validateBattleshipPlacement(sea, { type:'place_ship', size:1, cell:11, orientation:'h' }) === false,
  'Battleship placement must reject diagonal touching.'
);
assert(
  validateBattleshipPlacement(sea, { type:'place_ship', size:1, cell:20, orientation:'h' }) === true,
  'Battleship placement must allow a separated valid ship.'
);
assert(
  validateBattleshipPlacement(sea, { type:'place_ship', size:4, cell:8, orientation:'h' }) === false,
  'Battleship placement must reject row overflow.'
);

console.log(`ProductionV97ModelsRuntimeTest: ${assertions} assertions passed`);
JS;

file_put_contents($testPath, $test);
$output = [];
$exitCode = 0;
exec('node ' . escapeshellarg($testPath) . ' 2>&1', $output, $exitCode);

@unlink($testPath);
@unlink($modulePath);
@rmdir($tempDir);

if ($exitCode !== 0) {
    throw new RuntimeException("V97 model runtime test failed:\n" . implode("\n", $output));
}

fwrite(STDOUT, implode("\n", $output) . "\n");
