<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/production-v102-battleship-models.js');
if (!is_string($source)) throw new RuntimeException('Cannot read v102 Battleship models.');

$tempDir = sys_get_temp_dir() . '/mgw_v102_battleship_' . bin2hex(random_bytes(6));
mkdir($tempDir, 0700, true);
file_put_contents($tempDir . '/models.mjs', $source);
file_put_contents($tempDir . '/test.mjs', <<<'JS'
import {
  buildV102BattleshipSetupOptimistic,
  createV102RandomFleet,
  createV102RandomizeAction,
  isCompleteV102Fleet,
} from './models.mjs';

let assertions = 0;
function assert(condition, message){ assertions++; if (!condition) throw new Error(message); }
function remaining(game, size){
  return Number((game.remaining_to_place || []).find(item => Number(item.size) === size)?.count || 0);
}
function noTouch(fleet){
  const occupied = new Map();
  for (let index = 0; index < fleet.length; index++) {
    for (const cell of fleet[index].cells) occupied.set(cell, index);
  }
  for (let index = 0; index < fleet.length; index++) {
    for (const cell of fleet[index].cells) {
      const row = Math.floor(cell / 10);
      const col = cell % 10;
      for (let dr = -1; dr <= 1; dr++) for (let dc = -1; dc <= 1; dc++) {
        const nextRow = row + dr;
        const nextCol = col + dc;
        if (nextRow < 0 || nextRow >= 10 || nextCol < 0 || nextCol >= 10) continue;
        const other = occupied.get(nextRow * 10 + nextCol);
        if (other !== undefined && other !== index) return false;
      }
    }
  }
  return true;
}

const base = {
  id:'g1', status:'active', phase:'setup',
  my_fleet:[], my_board:Array(100).fill('water'),
  fleet_placed:[
    {size:4, placed:0, required:1}, {size:3, placed:0, required:2},
    {size:2, placed:0, required:3}, {size:1, placed:0, required:4},
  ],
  remaining_to_place:[{size:4,count:1},{size:3,count:2},{size:2,count:3},{size:1,count:4}],
};

const first = buildV102BattleshipSetupOptimistic(base, {type:'place_ship', size:4, cell:0, orientation:'h'});
assert(first.my_fleet.length === 1 && first.my_board.slice(0,4).every(value => value === 'ship'), 'A completed four-cell ship must appear immediately.');
assert(remaining(first, 4) === 0 && remaining(first, 3) === 2, 'Fleet summaries must update in the same optimistic frame.');

const second = buildV102BattleshipSetupOptimistic(first, {type:'place_ship', size:3, cell:20, orientation:'h'});
assert(second?.my_fleet.length === 2, 'A valid next ship must be placeable immediately without waiting for the server response.');
assert(remaining(second, 3) === 1, 'The next ship counter must decrement immediately.');

for (let index = 0; index < 12; index++) {
  const fleet = createV102RandomFleet();
  const counts = fleet.reduce((result, ship) => ({...result, [ship.size]:(result[ship.size] || 0) + 1}), {});
  assert(isCompleteV102Fleet(fleet), 'Every generated fleet must pass the complete-fleet validator.');
  assert(counts[4] === 1 && counts[3] === 2 && counts[2] === 3 && counts[1] === 4, 'Random fleet counts must remain 1/2/3/4.');
  assert(noTouch(fleet), 'Random ships must not touch, including diagonally.');
}

const randomAction = createV102RandomizeAction();
const randomized = buildV102BattleshipSetupOptimistic(second, randomAction);
assert(randomAction.ships.length === 10, 'Randomize must send one exact ten-ship fleet to the server.');
assert(randomized.my_fleet.length === 10 && randomized.remaining_to_place.length === 0, 'Randomize must paint a complete fleet immediately.');
assert(randomized.fleet_placed.every(item => item.placed === item.required), 'Randomize must update all visible fleet counters immediately.');

const cleared = buildV102BattleshipSetupOptimistic(randomized, {type:'clear_fleet'});
assert(cleared.my_fleet.length === 0 && remaining(cleared, 4) === 1 && remaining(cleared, 1) === 4, 'Clear must remain immediate and restore every counter.');

console.log(`ProductionV102BattleshipModelsRuntimeTest: ${assertions} assertions passed`);
JS);

$output = [];
$exitCode = 0;
exec('node ' . escapeshellarg($tempDir . '/test.mjs') . ' 2>&1', $output, $exitCode);
@unlink($tempDir . '/test.mjs');
@unlink($tempDir . '/models.mjs');
@rmdir($tempDir);
if ($exitCode !== 0) throw new RuntimeException("V102 Battleship model test failed:\n" . implode("\n", $output));
fwrite(STDOUT, implode("\n", $output) . "\n");
