<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/production-cross-game-optimistic.js');
if (!is_string($source)) throw new RuntimeException('Cannot read cross-game optimistic module.');

$tempDir = sys_get_temp_dir() . '/mgw_checkers_chain_' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Cannot create temporary checkers chain test directory.');
}

$modulePath = $tempDir . '/optimistic.mjs';
$testPath = $tempDir . '/test.mjs';
file_put_contents($modulePath, $source);
file_put_contents($testPath, <<<'JS'
import { buildOptimisticGame } from './optimistic.mjs';
let assertions = 0;
function assert(condition, message){ assertions++; if (!condition) throw new Error(message); }

const board = Array(64).fill('');
board[42] = 'w';
board[35] = 'b';
board[19] = 'b';
const game = {
  id:'chain-1',
  status:'active',
  turn:'u1',
  move_timeout_sec:60,
  players:[
    { id:'u1', name:'One', symbol:'○', side:'white' },
    { id:'u2', name:'Two', symbol:'●', side:'black' },
  ],
  board,
  legal_moves:[{ from:42, to:28, capture:true, captured:35 }],
  pending_captures:[],
  capture_required:true,
  forced_piece:null,
};

const first = buildOptimisticGame(game, { type:'move', from:42, to:28 }, 'u1', 'checkers');
assert(first.turn === 'u1', 'The same player must retain the turn during a capture chain.');
assert(first.forced_piece === 28, 'The landing checker must become the forced chain piece.');
assert(first.pending_captures.length === 1 && first.pending_captures[0] === 35, 'The first captured checker must stay pending until the chain ends.');
assert(first.board[35] === 'b', 'A pending captured checker must remain on the board during the chain.');
assert(first.legal_moves.length === 1 && first.legal_moves[0].from === 28 && first.legal_moves[0].to === 10, 'The second capture must be immediately selectable.');

const second = buildOptimisticGame(first, { type:'move', from:28, to:10 }, 'u1', 'checkers');
assert(second.turn === 'u2', 'The turn must pass only after the full capture chain ends.');
assert(second.forced_piece === null && second.pending_captures.length === 0, 'Chain state must clear after the last capture.');
assert(second.board[35] === '' && second.board[19] === '', 'All captured checkers must be removed together after the chain.');
assert(String(second.board[10]).toLowerCase() === 'w', 'The moving checker must remain on the final landing square.');

console.log(`ProductionCheckersChainOptimisticRuntimeTest: ${assertions} assertions passed`);
JS);

$output = [];
$exitCode = 0;
exec('node ' . escapeshellarg($testPath) . ' 2>&1', $output, $exitCode);
@unlink($testPath);
@unlink($modulePath);
@rmdir($tempDir);

if ($exitCode !== 0) {
    throw new RuntimeException("Checkers chain optimistic runtime test failed:\n" . implode("\n", $output));
}

fwrite(STDOUT, implode("\n", $output) . "\n");
