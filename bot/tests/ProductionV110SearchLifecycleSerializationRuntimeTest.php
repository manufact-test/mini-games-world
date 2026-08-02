<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/screens/search-screen-v102.js');
if (!is_string($source)) throw new RuntimeException('Cannot read search-screen-v102.js');

$module = preg_replace('/^import[\s\S]*?;\R/m', '', $source);
if (!is_string($module)) throw new RuntimeException('Cannot prepare search runtime module.');
$prelude = <<<'JS'
const {
  state, api, toast, closeSheet, showScreen, clearTimer, renderBalances, roomName,
  APP_CONFIG, haptic, enterGame, clearGameView, currentV99PassiveLock,
  rememberV99PassiveLock, clearV99PassiveLock,
} = globalThis.__mgwSearchTestDeps;
JS;
$module = $prelude . "\n" . $module;

$tempDir = sys_get_temp_dir() . '/mgw_search_lifecycle_' . bin2hex(random_bytes(6));
mkdir($tempDir, 0700, true);
file_put_contents($tempDir . '/search.mjs', $module);
file_put_contents($tempDir . '/test.mjs', <<<'JS'
class FakeElement {
  constructor(id = '') { this.id = id; this.disabled = false; }
  closest(){ return this; }
  matches(){ return false; }
}
globalThis.Element = FakeElement;
globalThis.HTMLButtonElement = FakeElement;
globalThis.CustomEvent = class CustomEvent {
  constructor(type, init = {}) { this.type = type; this.detail = init.detail || {}; }
};

const listeners = new Map();
const dispatched = [];
globalThis.document = {
  addEventListener(type, handler){
    const list = listeners.get(type) || [];
    list.push(handler);
    listeners.set(type, list);
  },
  dispatchEvent(event){
    dispatched.push(event);
    for (const handler of listeners.get(event.type) || []) handler(event);
    return true;
  },
  getElementById(){ return null; },
  querySelector(){ return null; },
};

globalThis.window = {
  __MGW_V100_SEARCH_RUNTIME__: undefined,
  setInterval(){ return 1; },
  clearInterval(){},
};

let startCalls = 0;
let leaveCalls = 0;
let resolveStart;
let activeStart = new Promise(resolve => { resolveStart = resolve; });
const screens = [];
const state = {
  room:'match', selectedBet:10, selectedBoardSize:3, selectedGame:'', activeGame:null,
  timers:{ search:null, game:null },
};
const api = {
  startSearch(){ startCalls++; return activeStart; },
  leaveSearch(){ leaveCalls++; return Promise.resolve({ user:{ status:'idle' }, session:{ locked:false } }); },
  gameState(){ return Promise.resolve({ user:{ status:'searching' }, session:{ locked:false } }); },
};
globalThis.__mgwSearchTestDeps = {
  state,
  api,
  toast(){},
  closeSheet(){},
  showScreen(value){ screens.push(value); },
  clearTimer(){ return null; },
  renderBalances(){},
  roomName(){ return 'Матч-комната'; },
  APP_CONFIG:{ matchBet:10, goldBets:[10], searchIntervalMs:1000 },
  haptic(){},
  enterGame(){},
  clearGameView(){},
  currentV99PassiveLock(){ return null; },
  rememberV99PassiveLock(){},
  clearV99PassiveLock(){},
};

const { initSearchScreen, beginSearch } = await import('./search.mjs');
initSearchScreen();

let assertions = 0;
function assert(condition, message){
  assertions++;
  if (!condition) throw new Error(message);
}

const first = beginSearch({ gameType:'tictactoe', room:'match', bet:10, size:3, title:'Крестики-нолики' });
await Promise.resolve();
const duplicate = beginSearch({ gameType:'tictactoe', room:'match', bet:10, size:3, title:'Крестики-нолики' });
await Promise.resolve();
assert(startCalls === 1, 'A second start while the first request is pending must be rejected.');
assert(await duplicate === null, 'Duplicate beginSearch must resolve without starting another request.');

const cancelButton = new FakeElement('changeSearch');
const clickEvent = {
  target:cancelButton,
  preventDefault(){},
  stopImmediatePropagation(){},
};
for (const handler of listeners.get('click') || []) handler(clickEvent);
await Promise.resolve();
assert(leaveCalls === 0, 'leave_search must not race ahead of the unresolved start_search request.');

const stopped = new Promise(resolve => {
  document.addEventListener('mgw:search-stopped', resolve);
});
resolveStart({ queued:true, user:{ status:'searching' }, session:{ locked:false } });
await first;
await stopped;
assert(leaveCalls === 1, 'Exactly one authoritative leave_search must run after start_search settles.');
assert(screens.at(-1) === 'home', 'Cancellation must keep the visible screen at home.');
assert(dispatched.some(event => event.type === 'mgw:search-stopped' && event.detail.authoritative === true),
  'Authoritative cancellation must publish the search-stopped reconciliation boundary.');

activeStart = Promise.resolve({ queued:true, user:{ status:'searching' }, session:{ locked:false } });
await beginSearch({ gameType:'tictactoe', room:'match', bet:10, size:3, title:'Крестики-нолики' });
assert(startCalls === 2, 'A new search must be allowed after the previous authoritative stop completes.');

console.log(`ProductionV110SearchLifecycleSerializationRuntimeTest: ${assertions} assertions passed`);
JS);

$output = [];
$exitCode = 0;
exec('node ' . escapeshellarg($tempDir . '/test.mjs') . ' 2>&1', $output, $exitCode);
@unlink($tempDir . '/test.mjs');
@unlink($tempDir . '/search.mjs');
@rmdir($tempDir);
if ($exitCode !== 0) {
    throw new RuntimeException("Search lifecycle runtime test failed:\n" . implode("\n", $output));
}
fwrite(STDOUT, implode("\n", $output) . "\n");
