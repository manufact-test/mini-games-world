<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/production-v106-timer-mobile.js');
if (!is_string($source)) throw new RuntimeException('Cannot read v106 timer/mobile source.');

$tempDir = sys_get_temp_dir() . '/mgw_v106_ttt_' . bin2hex(random_bytes(6));
mkdir($tempDir, 0700, true);
$module = str_replace(
    ["'./state.js?v=27'", "'./telegram/telegram-app.js?v=27'", "'./session.js?v=27'"],
    ["'./state.mjs'", "'./telegram.mjs'", "'./session.mjs'"],
    $source
);
file_put_contents($tempDir . '/timer.mjs', $module);
file_put_contents($tempDir . '/state.mjs', <<<'JS'
export const state = {
  activeGame:{
    id:'g1', game_type:'tictactoe', status:'active', turn:'u1', board:'---------',
    board_size:3, time_left:57, move_timeout_sec:60, is_bot_game:true,
  },
};
JS);
file_put_contents($tempDir . '/telegram.mjs', "export const getInitData = () => 'signed';\n");
file_put_contents($tempDir . '/session.mjs', "export const getSessionId = () => 'session-1';\n");
file_put_contents($tempDir . '/test.mjs', <<<'JS'
import { state } from './state.mjs';

class FakeClassList {
  constructor(){ this.values = new Set(); }
  add(...items){ items.forEach(item => this.values.add(item)); }
  toggle(item, enabled){ enabled ? this.values.add(item) : this.values.delete(item); }
  contains(item){ return this.values.has(item); }
}
class FakeElement {
  closest(){ return null; }
}
class FakeButton extends FakeElement {
  constructor(){
    super();
    this.textContent = '';
    this.disabled = false;
    this.classList = new FakeClassList();
  }
}

const timer = {textContent:'57 сек'};
const cell = new FakeButton();
const documentListeners = new Map();
let intervalCallback = null;
let intervalMs = null;
let fetchCalls = 0;
let failNextClock = false;
let now = 1_000_000;

Date.now = () => now;
globalThis.Element = FakeElement;
globalThis.HTMLButtonElement = FakeButton;
globalThis.document = {
  visibilityState:'visible',
  addEventListener(type, callback){ documentListeners.set(type, callback); },
  getElementById(id){ return id === 'timerText' ? timer : null; },
  querySelector(selector){ return selector.includes('[data-game-cell="0"]') ? cell : null; },
};
globalThis.window = {
  location:{origin:'https://mgw.test'},
  addEventListener(){},
  setInterval(callback, ms){ intervalCallback = callback; intervalMs = ms; return 1; },
  requestAnimationFrame(callback){ return 1; },
  __MGW_V105_TICTACTOE__:{pending:null},
  __MGW_V100_GAME_RUNTIME__:{games:new Map()},
};
globalThis.fetch = async () => {
  fetchCalls++;
  if (failNextClock) throw new Error('offline');
  return new Response(JSON.stringify({
    ok:true,
    game:{...state.activeGame,time_left:60,move_timeout_sec:60},
  }), {status:200,headers:{'Content-Type':'application/json'}});
};

let assertions = 0;
function assert(condition, message){
  assertions++;
  if (!condition) throw new Error(message);
}

const { initV106TicTacToeTimerAndMobilePin } = await import('./timer.mjs');
initV106TicTacToeTimerAndMobilePin();
assert(intervalMs === 200 && typeof intervalCallback === 'function', 'A smooth 200 ms local timer tick must be installed.');

intervalCallback();
assert(timer.textContent === '60 сек', 'A newly shown empty bot match must start visibly at 60 seconds.');
await new Promise(resolve => setTimeout(resolve, 0));
assert(fetchCalls === 1, 'The authoritative first-turn clock must be armed exactly once.');

now += 1100;
intervalCallback();
assert(timer.textContent === '59 сек', 'The visible timer must continue ticking locally between server polls.');
assert(fetchCalls === 1, 'Repeated local ticks must not repeat the clock mutation.');

window.__MGW_V105_TICTACTOE__.pending = {gameId:'g1',cell:0,symbol:'X'};
cell.textContent = '';
cell.disabled = false;
intervalCallback();
assert(cell.textContent === '✕' && cell.disabled && cell.classList.contains('mgw-pending-action'),
  'An in-place mobile repaint must restore and lock the pending mark without an observer callback.');

state.activeGame = {
  id:'g2', game_type:'tictactoe', status:'active', turn:'u1', board:'---------',
  board_size:3, time_left:58, move_timeout_sec:60, is_bot_game:true,
};
window.__MGW_V105_TICTACTOE__.pending = null;
failNextClock = true;
intervalCallback();
await new Promise(resolve => setTimeout(resolve, 0));
const failedCount = fetchCalls;
intervalCallback();
assert(fetchCalls === failedCount, 'A failed clock request must not be retried every timer tick.');

console.log(`ProductionV106TicTacToeTimerRuntimeTest: ${assertions} assertions passed`);
JS);

$output = [];
$exitCode = 0;
exec('node ' . escapeshellarg($tempDir . '/test.mjs') . ' 2>&1', $output, $exitCode);
foreach (['test.mjs', 'timer.mjs', 'state.mjs', 'telegram.mjs', 'session.mjs'] as $file) {
    @unlink($tempDir . '/' . $file);
}
@rmdir($tempDir);
if ($exitCode !== 0) {
    throw new RuntimeException("V106 Tic Tac Toe timer/mobile runtime test failed:\n" . implode("\n", $output));
}
fwrite(STDOUT, implode("\n", $output) . "\n");
