<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/production-v103-targeted-interactions.js');
if (!is_string($source)) throw new RuntimeException('Cannot read v103 targeted interaction source.');

$tempDir = sys_get_temp_dir() . '/mgw_v103_targeted_' . bin2hex(random_bytes(6));
mkdir($tempDir . '/components', 0700, true);
mkdir($tempDir . '/telegram', 0700, true);
mkdir($tempDir . '/screens', 0700, true);

$replacements = [
    "'./state.js?v=27'" => "'./state.mjs'",
    "'./api/client.js?v=47'" => "'./api.mjs'",
    "'./components/toast.js?v=41'" => "'./components/toast.mjs'",
    "'./components/sheet.js?v=68'" => "'./components/sheet.mjs'",
    "'./router.js?v=27'" => "'./router.mjs'",
    "'./ui.js?v=89'" => "'./ui.mjs'",
    "'./telegram/telegram-app.js?v=27'" => "'./telegram/telegram-app.mjs'",
    "'./production-v99-session-transport.js?v=99'" => "'./transport.mjs'",
    "'./screens/game-screen-v102-safe.js?v=102'" => "'./screens/game.mjs'",
];
file_put_contents($tempDir . '/guard.mjs', str_replace(array_keys($replacements), array_values($replacements), $source));
file_put_contents($tempDir . '/state.mjs', "export const state = { activeGame:null, user:{id:'me'}, session:null, timers:{game:123} };\n");
file_put_contents($tempDir . '/api.mjs', "export const api = { leaveGame:(id) => globalThis.__leaveGame(id) };\n");
file_put_contents($tempDir . '/components/toast.mjs', "export const toast = value => globalThis.__toasts.push(String(value));\n");
file_put_contents($tempDir . '/components/sheet.mjs', "export const closeSheet = () => globalThis.__calls.push('close-sheet');\n");
file_put_contents($tempDir . '/router.mjs', "export const showScreen = value => globalThis.__calls.push('screen:' + value);\n");
file_put_contents($tempDir . '/ui.mjs', "export const clearTimer = () => null; export const renderBalances = () => globalThis.__calls.push('balances');\n");
file_put_contents($tempDir . '/telegram/telegram-app.mjs', "export const haptic = value => globalThis.__calls.push('haptic:' + value);\n");
file_put_contents($tempDir . '/transport.mjs', <<<'JS'
export const currentV99PassiveLock = () => globalThis.__passiveLock;
export const clearV99PassiveLock = () => { globalThis.__passiveLock = null; globalThis.__calls.push('clear-lock'); };
JS);
file_put_contents($tempDir . '/screens/game.mjs', <<<'JS'
export const enterGame = (game) => { globalThis.__restored.push(game); globalThis.__calls.push('restore-game'); };
export const clearGameView = () => globalThis.__calls.push('clear-game');
JS);

file_put_contents($tempDir . '/test.mjs', <<<'JS'
class FakeClassList {
  constructor(values = []){ this.values = new Set(values); }
  add(value){ this.values.add(value); }
  remove(value){ this.values.delete(value); }
  toggle(value, enabled){ enabled ? this.add(value) : this.remove(value); }
  contains(value){ return this.values.has(value); }
}

class FakeElement {
  constructor({ id = '', selectors = [], dataset = {} } = {}){
    this.id = id;
    this.dataset = dataset;
    this.selectors = new Set(selectors);
    this.classList = new FakeClassList();
    this.attributes = new Map();
    this.disabled = false;
    this.parentMap = new Map();
  }
  closest(selector){
    if (selector === 'button, [role="button"]') return this;
    if (this.selectors.has(selector)) return this;
    return this.parentMap.get(selector) || null;
  }
  matches(selector){ return this.selectors.has(selector); }
  setAttribute(name, value){ this.attributes.set(name, String(value)); }
  removeAttribute(name){ this.attributes.delete(name); }
}
class FakeButton extends FakeElement {}
class FakeDocument {
  constructor(){ this.listeners = new Map(); this.elements = new Map(); this.events = []; }
  addEventListener(type, callback){
    if (!this.listeners.has(type)) this.listeners.set(type, []);
    this.listeners.get(type).push(callback);
  }
  dispatchEvent(event){
    this.events.push(event.type);
    for (const callback of this.listeners.get(event.type) || []) callback(event);
    return true;
  }
  click(target){
    const event = {
      type:'click', target, prevented:false, stopped:false,
      preventDefault(){ this.prevented = true; },
      stopImmediatePropagation(){ this.stopped = true; },
    };
    for (const callback of this.listeners.get('click') || []) {
      callback(event);
      if (event.stopped) break;
    }
    return event;
  }
  getElementById(id){ return this.elements.get(id) || null; }
  querySelector(selector){
    if (selector === '[data-room="match"].active') return globalThis.__matchActive ? {} : null;
    return null;
  }
}
class FakeMutationObserver { constructor(callback){ this.callback = callback; } observe(){} }
class FakeCustomEvent { constructor(type, options = {}){ this.type = type; this.detail = options.detail; } }

globalThis.Element = FakeElement;
globalThis.HTMLButtonElement = FakeButton;
globalThis.MutationObserver = FakeMutationObserver;
globalThis.CustomEvent = FakeCustomEvent;
globalThis.document = new FakeDocument();
globalThis.window = {
  setTimeout,
  __MGW_V101_SPEED__:{ backgroundControllers:new Set() },
  __MGW_V100_GAME_RUNTIME__:{ games:new Map() },
};
globalThis.__calls = [];
globalThis.__toasts = [];
globalThis.__restored = [];
globalThis.__passiveLock = null;
globalThis.__matchActive = true;

const playIds = ['playTicTacToe','playFourInARow','playBattleship','playCheckers','playReversi','playChess','playGo','playDomino'];
for (const id of playIds) document.elements.set(id, new FakeButton({id}));
const roomCard = new FakeElement({id:'roomCard'});
document.elements.set('roomCard', roomCard);
const actions = {
  classList:new FakeClassList(['single']),
  insertAdjacentHTML(){ document.elements.set('weeklyMatchInfo', new FakeButton({id:'weeklyMatchInfo'})); },
};
const topup = new FakeButton({id:'topUpMatch'});
topup.parentMap.set('.room-actions', actions);
document.elements.set('topUpMatch', topup);

let leaveResolve;
let leaveReject;
globalThis.__leaveGame = () => new Promise((resolve, reject) => { leaveResolve = resolve; leaveReject = reject; });

const { state } = await import('./state.mjs');
const { initV103TargetedInteractions } = await import('./guard.mjs');
initV103TargetedInteractions();
await new Promise(resolve => setTimeout(resolve, 5));

let assertions = 0;
function assert(condition, message){ assertions++; if (!condition) throw new Error(message); }

assert(document.getElementById('weeklyMatchInfo') !== null, 'The Match-room details button must be restored after initialization.');
assert(!actions.classList.contains('single'), 'The Match-room actions must use two columns when Details is present.');

__passiveLock = {locked:true, message:'Игра уже открыта на другом устройстве.'};
const playClick = document.click(document.getElementById('playTicTacToe'));
assert(playClick.prevented && playClick.stopped, 'A locked account must be stopped at the main Play button.');
assert(__toasts.length === 1, 'The main Play lock must show one explanatory message.');

const invite = new FakeButton({selectors:['[data-invite-friend]']});
const inviteClick = document.click(invite);
assert(!inviteClick.prevented && !inviteClick.stopped, 'The main Play guard must not block invitation controls.');

__passiveLock = null;
const game = {id:'g1', game_type:'tictactoe', status:'active', turn:'other', board:'---------'};
state.activeGame = game;
window.__MGW_V100_GAME_RUNTIME__.games.set('g1', {
  authoritative:game,
  viewer:{id:'me'},
  running:false,
  queue:[],
  surrenderPending:false,
});
const tttCell = new FakeButton({selectors:['#gameBoard[data-game-type="tictactoe"] [data-game-cell]'], dataset:{gameCell:'0'}});
const wrongTurn = document.click(tttCell);
assert(wrongTurn.prevented && wrongTurn.stopped, 'A Tic Tac Toe tap outside the authoritative turn must never reach the renderer action handler.');

window.__MGW_V100_GAME_RUNTIME__.games.get('g1').authoritative = {...game, turn:'me'};
state.activeGame = {...game, turn:'me'};
const validTurn = document.click(tttCell);
assert(!validTurn.prevented && !validTurn.stopped, 'A valid authoritative Tic Tac Toe tap must remain available to the existing game owner.');

const abortState = {aborted:false};
window.__MGW_V101_SPEED__.backgroundControllers.add({abort(){ abortState.aborted = true; }});
const confirm = new FakeButton({id:'confirmLeaveGame'});
state.activeGame = {...game, turn:'me'};
const leaveClick = document.click(confirm);
assert(leaveClick.prevented && leaveClick.stopped, 'The v103 owner must replace the old blocked surrender handler.');
assert(__calls.includes('screen:home') && state.activeGame === null, 'Surrender must leave the visible game before the server response.');
assert(abortState.aborted, 'Surrender must abort obsolete passive reads.');
assert(!document.events.includes('mgw:game-dismissed'), 'Dismissal sync must not run before leave_game succeeds.');

const pendingPlay = document.click(document.getElementById('playChess'));
assert(pendingPlay.prevented && pendingPlay.stopped, 'New games must stay blocked while surrender confirmation is pending.');

leaveResolve({user:{id:'me'}, session:{locked:false}, game:{...game,status:'finished'}});
await new Promise(resolve => setTimeout(resolve, 0));
assert(__calls.includes('clear-lock'), 'A successful surrender must clear a stale passive lock.');
assert(document.events.includes('mgw:game-finished') && document.events.includes('mgw:game-dismissed'), 'Successful surrender must publish finish and dismissal after confirmation.');

// Failure path restores the exact active snapshot.
state.activeGame = {...game, id:'g2', turn:'me'};
globalThis.__leaveGame = () => Promise.reject(new Error('network'));
const failedLeave = document.click(confirm);
assert(failedLeave.prevented && failedLeave.stopped, 'The replacement surrender owner must also handle failures.');
await new Promise(resolve => setTimeout(resolve, 0));
assert(__restored.some(item => item?.id === 'g2'), 'A failed surrender must restore the original active game snapshot.');

console.log(`ProductionV103TargetedInteractionRuntimeTest: ${assertions} assertions passed`);
JS);

$output = [];
$exitCode = 0;
exec('node ' . escapeshellarg($tempDir . '/test.mjs') . ' 2>&1', $output, $exitCode);
foreach (glob($tempDir . '/*.mjs') ?: [] as $file) @unlink($file);
foreach (glob($tempDir . '/components/*.mjs') ?: [] as $file) @unlink($file);
foreach (glob($tempDir . '/telegram/*.mjs') ?: [] as $file) @unlink($file);
foreach (glob($tempDir . '/screens/*.mjs') ?: [] as $file) @unlink($file);
@rmdir($tempDir . '/components');
@rmdir($tempDir . '/telegram');
@rmdir($tempDir . '/screens');
@rmdir($tempDir);
if ($exitCode !== 0) {
    throw new RuntimeException("V103 targeted interaction runtime test failed:\n" . implode("\n", $output));
}
fwrite(STDOUT, implode("\n", $output) . "\n");
