<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/production-v105-tictactoe-stability.js');
if (!is_string($source)) throw new RuntimeException('Cannot read v105 Tic Tac Toe source.');

$tempDir = sys_get_temp_dir() . '/mgw_v105_ttt_' . bin2hex(random_bytes(6));
mkdir($tempDir, 0700, true);
file_put_contents($tempDir . '/ttt.mjs', str_replace("'./state.js?v=27'", "'./state.mjs'", $source));
file_put_contents($tempDir . '/state.mjs', <<<'JS'
export const state = {
  user:{id:'u1'},
  activeGame:{
    id:'g1', game_type:'tictactoe', status:'active', turn:'u1', board:'---------',
    players:[{id:'u1',symbol:'X'},{id:'u2',symbol:'O'}],
  },
};
JS);
file_put_contents($tempDir . '/test.mjs', <<<'JS'
class FakeClassList {
  constructor(){ this.values = new Set(); }
  add(...values){ values.forEach(value => this.values.add(value)); }
  remove(...values){ values.forEach(value => this.values.delete(value)); }
  contains(value){ return this.values.has(value); }
}
class FakeElement {
  constructor(){ this.dataset = {}; this.classList = new FakeClassList(); }
  closest(selector){ return this.matchesSelector === selector ? this : null; }
}
class FakeButton extends FakeElement {
  constructor(cell){
    super();
    this.dataset.gameCell = String(cell);
    this.textContent = '';
    this.disabled = false;
    this.matchesSelector = '#gameBoard[data-game-type="tictactoe"] [data-game-cell]';
  }
}
class FakeDocument {
  constructor(){
    this.listeners = new Map();
    this.cell = new FakeButton(0);
    this.board = new FakeElement();
  }
  addEventListener(type, callback){
    if (!this.listeners.has(type)) this.listeners.set(type, []);
    this.listeners.get(type).push(callback);
  }
  dispatch(type, target){
    const event = { target };
    for (const callback of this.listeners.get(type) || []) callback(event);
  }
  getElementById(id){ return id === 'gameBoard' ? this.board : null; }
  querySelector(selector){ return selector.includes('[data-game-cell="0"]') ? this.cell : null; }
}

globalThis.Element = FakeElement;
globalThis.HTMLButtonElement = FakeButton;
globalThis.document = new FakeDocument();
globalThis.__observerCallback = null;
globalThis.__observerOptions = null;
globalThis.MutationObserver = class {
  constructor(callback){ globalThis.__observerCallback = callback; }
  observe(_target, options){ globalThis.__observerOptions = options; }
};
globalThis.window = {
  location:{href:'https://mgw.test/app/v105.php?v=105'},
  requestAnimationFrame:callback => callback(),
  setTimeout,
  clearTimeout,
  fetch:null,
  __MGW_V100_GAME_RUNTIME__:{
    games:new Map([['g1', {
      authoritative:{
        id:'g1', game_type:'tictactoe', status:'active', turn:'u1', board:'---------',
        players:[{id:'u1',symbol:'X'},{id:'u2',symbol:'O'}],
      },
      viewer:{id:'u1'}, queue:[], running:false, surrenderPending:false,
    }]]),
  },
};
let responseGame = {
  id:'g1', game_type:'tictactoe', status:'active', turn:'u2', board:'X--------',
  players:[{id:'u1',symbol:'X'},{id:'u2',symbol:'O'}],
};
window.fetch = async () => new Response(JSON.stringify({ok:true, game:responseGame}), {
  status:200,
  headers:{'Content-Type':'application/json'},
});
globalThis.fetch = (...args) => window.fetch(...args);

let assertions = 0;
function assert(condition, message){
  assertions++;
  if (!condition) throw new Error(message);
}

const { initV105TicTacToeStability } = await import('./ttt.mjs');
initV105TicTacToeStability();

assert(globalThis.__observerOptions?.childList === true, 'The board observer must watch direct child replacement.');
assert(!globalThis.__observerOptions?.subtree && !globalThis.__observerOptions?.attributes && !globalThis.__observerOptions?.characterData,
  'The observer must not watch its own text, class or disabled mutations.');

document.dispatch('click', document.cell);
await Promise.resolve();
assert(document.cell.textContent === '✕', 'A valid local X must be painted immediately.');
assert(document.cell.disabled === true && document.cell.classList.contains('mgw-pending-action'), 'The pending cell must be locked.');

document.cell.textContent = '';
document.cell.classList.values.clear();
document.cell.disabled = false;
globalThis.__observerCallback?.();
assert(document.cell.textContent === '✕', 'A complete stale board repaint must restore the pending mark once.');

let timerTicked = false;
setTimeout(() => { timerTicked = true; }, 0);
for (let index = 0; index < 1000; index++) globalThis.__observerCallback?.();
await new Promise(resolve => setTimeout(resolve, 5));
assert(timerTicked, 'Repeated board replacement notifications must not starve the main event loop.');

await window.fetch('https://mgw.test/bot/api.php', {
  method:'POST',
  body:JSON.stringify({action:'game_action',gameId:'g1'}),
});
await new Promise(resolve => setTimeout(resolve, 0));
document.cell.textContent = '';
document.cell.classList.values.clear();
document.cell.disabled = false;
globalThis.__observerCallback?.();
assert(document.cell.textContent === '', 'Authoritative confirmation must release the local pin.');

console.log(`ProductionV105TicTacToeNoFreezeRuntimeTest: ${assertions} assertions passed`);
JS);

$output = [];
$exitCode = 0;
exec('node ' . escapeshellarg($tempDir . '/test.mjs') . ' 2>&1', $output, $exitCode);
foreach ([$tempDir . '/test.mjs', $tempDir . '/ttt.mjs', $tempDir . '/state.mjs'] as $file) @unlink($file);
@rmdir($tempDir);
if ($exitCode !== 0) {
    throw new RuntimeException("V105 Tic Tac Toe no-freeze runtime test failed:\n" . implode("\n", $output));
}
fwrite(STDOUT, implode("\n", $output) . "\n");
