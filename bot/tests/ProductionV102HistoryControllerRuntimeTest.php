<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/production-v102-history-controller.js');
if (!is_string($source)) throw new RuntimeException('Cannot read v102 history controller.');

$tempDir = sys_get_temp_dir() . '/mgw_v102_history_' . bin2hex(random_bytes(6));
mkdir($tempDir . '/components', 0700, true);
mkdir($tempDir . '/telegram', 0700, true);

$replacements = [
    "'./state.js?v=27'" => "'./state.mjs'",
    "'./components/sheet.js?v=68'" => "'./components/sheet.mjs'",
    "'./components/toast.js?v=41'" => "'./components/toast.mjs'",
    "'./telegram/telegram-app.js?v=27'" => "'./telegram/telegram-app.mjs'",
    "'./session.js?v=27'" => "'./session.mjs'",
    "'./ui.js?v=89'" => "'./ui.mjs'",
];
file_put_contents($tempDir . '/history.mjs', str_replace(array_keys($replacements), array_values($replacements), $source));
file_put_contents($tempDir . '/state.mjs', "export const state = { user:null };\n");
file_put_contents($tempDir . '/components/sheet.mjs', "export const openSheet = html => globalThis.__sheets.push(String(html));\n");
file_put_contents($tempDir . '/components/toast.mjs', "export const toast = text => globalThis.__toasts.push(String(text));\n");
file_put_contents($tempDir . '/telegram/telegram-app.mjs', "export const getInitData = () => 'user-scope';\n");
file_put_contents($tempDir . '/session.mjs', "export const getSessionId = () => 'session-1';\n");
file_put_contents($tempDir . '/ui.mjs', "export const renderBalances = user => { globalThis.__balanceUser = user; };\n");

file_put_contents($tempDir . '/test.mjs', <<<'JS'
class FakeElement {
  constructor(id = '') { this.id = id; this.disabled = false; this.attributes = new Map(); }
  closest(selector){ return selector === `#${this.id}` ? this : null; }
  setAttribute(name, value){ this.attributes.set(name, value); }
  removeAttribute(name){ this.attributes.delete(name); }
}
class FakeButton extends FakeElement {}
class FakeDocument {
  constructor(){
    this.listeners = new Map();
    this.body = { contains:() => true };
  }
  addEventListener(type, callback){
    if (!this.listeners.has(type)) this.listeners.set(type, []);
    this.listeners.get(type).push(callback);
  }
  dispatch(type, target = null){
    const event = {
      type,
      target,
      prevented:false,
      stopped:false,
      detail:null,
      preventDefault(){ this.prevented = true; },
      stopImmediatePropagation(){ this.stopped = true; },
    };
    for (const callback of this.listeners.get(type) || []) {
      callback(event);
      if (event.stopped) break;
    }
    return event;
  }
  querySelectorAll(){ return []; }
}

globalThis.Element = FakeElement;
globalThis.HTMLButtonElement = FakeButton;
globalThis.document = new FakeDocument();
globalThis.window = { location:{origin:'https://mgw.test'}, setTimeout, clearTimeout };
globalThis.__sheets = [];
globalThis.__toasts = [];

let calls = 0;
globalThis.fetch = window.fetch = async () => {
  calls++;
  if (calls === 1) return new Response('', { status:200, headers:{'Content-Type':'application/json'} });
  return new Response(JSON.stringify({
    ok:true,
    user:{id:'u1', balance_match:90, balance_gold:20},
    history:{
      operations:[{title:'Участие', description:'Матч', created_at:'2026-07-27T10:00:00Z', amount_label:'−10 коинов', tone:'neg'}],
      matches:[{result:'Победа', room:'match', board_size:3, bet:10, opponent:'Игрок', short_id:'ABC', created_at:'2026-07-27T10:00:00Z', payout:18, tone:'pos'}],
    },
    topups:[],
  }), { status:200, headers:{'Content-Type':'application/json'} });
};

let assertions = 0;
function assert(condition, message){ assertions++; if (!condition) throw new Error(message); }

const { initV102HistoryController } = await import('./history.mjs');
initV102HistoryController();
document.dispatch('mgw:app-ready');
await new Promise(resolve => setTimeout(resolve, 280));
assert(calls === 2, 'An invalid empty 200 history response must be retried exactly once.');
assert(globalThis.__sheets.length === 0, 'Background history prefetch must never open a loading sheet.');
assert(globalThis.__toasts.length === 0, 'A recovered background empty 200 response must stay silent.');

const balance = new FakeButton('balanceHistoryBtn');
const balanceEvent = document.dispatch('click', balance);
await new Promise(resolve => setTimeout(resolve, 0));
assert(balanceEvent.prevented && balanceEvent.stopped, 'The v102 history owner must block the legacy delayed history handler.');
assert(calls === 2, 'Balance history must reuse the ready prefetched snapshot.');
assert(globalThis.__sheets.at(-1)?.includes('История баланса'), 'Balance history must open only with ready content.');
assert(!globalThis.__sheets.some(html => html.includes('Загружаем историю')), 'No v102 history path may paint the old loading sheet.');

const matches = new FakeButton('matchHistoryBtn');
document.dispatch('click', matches);
await new Promise(resolve => setTimeout(resolve, 0));
assert(calls === 2, 'Match history must share the same validated snapshot.');
assert(globalThis.__sheets.at(-1)?.includes('История матчей'), 'Match history must open only with ready content.');
assert(globalThis.__balanceUser?.id === 'u1', 'Validated history response may refresh visible balances.');

console.log(`ProductionV102HistoryControllerRuntimeTest: ${assertions} assertions passed`);
JS);

$output = [];
$exitCode = 0;
exec('node ' . escapeshellarg($tempDir . '/test.mjs') . ' 2>&1', $output, $exitCode);
foreach (glob($tempDir . '/*.mjs') ?: [] as $file) @unlink($file);
foreach (glob($tempDir . '/components/*.mjs') ?: [] as $file) @unlink($file);
foreach (glob($tempDir . '/telegram/*.mjs') ?: [] as $file) @unlink($file);
@rmdir($tempDir . '/components');
@rmdir($tempDir . '/telegram');
@rmdir($tempDir);
if ($exitCode !== 0) throw new RuntimeException("V102 history test failed:\n" . implode("\n", $output));
fwrite(STDOUT, implode("\n", $output) . "\n");
