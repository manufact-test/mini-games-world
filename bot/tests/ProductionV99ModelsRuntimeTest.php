<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/production-v99-models.js');
if (!is_string($source)) throw new RuntimeException('Cannot read v99 models.');

$tempDir = sys_get_temp_dir() . '/mgw_v99_models_' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Cannot create v99 model test directory.');
}
$module = $tempDir . '/models.mjs';
$test = $tempDir . '/test.mjs';
file_put_contents($module, $source);
file_put_contents($test, <<<'JS'
import { buildBattleshipSetupOptimistic, pollResultIsCurrent } from './models.mjs';

let assertions = 0;
function assert(condition, message){
  assertions++;
  if (!condition) throw new Error(message);
}

const empty = {
  id:'sea-1',
  status:'active',
  phase:'setup',
  my_board:Array(100).fill('water'),
  my_fleet:[],
};
const two = buildBattleshipSetupOptimistic(empty, {
  type:'place_ship', size:2, cell:22, orientation:'h',
});
assert(two !== null, 'One two-cell ship must be accepted as one action.');
assert(two.my_board[22] === 'ship' && two.my_board[23] === 'ship', 'Both cells of one ship must appear together.');
assert(two.my_fleet.length === 1 && two.my_fleet[0].cells.join(',') === '22,23', 'Adjacent candidate cells must belong to one fleet item.');
assert(empty.my_board[22] === 'water', 'Optimistic placement must not mutate server state.');

const touching = buildBattleshipSetupOptimistic(two, {
  type:'place_ship', size:1, cell:24, orientation:'h',
});
assert(touching === null, 'A separate ship touching the completed ship must be rejected.');

const vertical = buildBattleshipSetupOptimistic(two, {
  type:'place_ship', size:2, cell:40, orientation:'v',
});
assert(vertical !== null && vertical.my_board[40] === 'ship' && vertical.my_board[50] === 'ship', 'Separated vertical ship must appear immediately.');

assert(pollResultIsCurrent(4, 4, false) === true, 'Current polling result may reconcile the board.');
assert(pollResultIsCurrent(4, 5, false) === false, 'Polling started before an optimistic action must be discarded.');
assert(pollResultIsCurrent(5, 5, true) === false, 'Polling must not repaint while an action is pending.');

console.log(`ProductionV99ModelsRuntimeTest: ${assertions} assertions passed`);
JS);

$output = [];
$exitCode = 0;
exec('node ' . escapeshellarg($test) . ' 2>&1', $output, $exitCode);
@unlink($test);
@unlink($module);
@rmdir($tempDir);
if ($exitCode !== 0) {
    throw new RuntimeException("V99 model runtime test failed:\n" . implode("\n", $output));
}
fwrite(STDOUT, implode("\n", $output) . "\n");
