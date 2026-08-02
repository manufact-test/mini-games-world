<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/production-v101-share-controller.js');
$models = file_get_contents($root . '/app/assets/js/production-v101-speed-models.js');
if (!is_string($source) || !is_string($models)) throw new RuntimeException('Cannot read v101 share sources.');

$tempDir = sys_get_temp_dir() . '/mgw_v101_share_' . bin2hex(random_bytes(6));
mkdir($tempDir . '/components', 0700, true);
mkdir($tempDir . '/telegram', 0700, true);

$replacements = [
    "'./state.js?v=27'" => "'./state.mjs'",
    "'./config.js?v=38'" => "'./config.mjs'",
    "'./components/sheet.js?v=68'" => "'./components/sheet.mjs'",
    "'./components/toast.js?v=41'" => "'./components/toast.mjs'",
    "'./telegram/telegram-app.js?v=27'" => "'./telegram/telegram-app.mjs'",
    "'./session.js?v=27'" => "'./session.mjs'",
    "'./production-v101-speed-models.js?v=101'" => "'./models.mjs'",
];
file_put_contents($tempDir . '/share.mjs', str_replace(array_keys($replacements), array_values($replacements), $source));
file_put_contents($tempDir . '/models.mjs', $models);
file_put_contents($tempDir . '/state.mjs', "export const state = { selectedGame:'tictactoe', room:'match', selectedBet:10 };\n");
file_put_contents($tempDir . '/config.mjs', "export const APP_CONFIG = { matchBet:10, goldBets:[10,20,30] };\n");
file_put_contents($tempDir . '/components/sheet.mjs', "export const openSheet = value => globalThis.__sheets.push(value);\n");
file_put_contents($tempDir . '/components/toast.mjs', "export const toast = value => globalThis.__toasts.push(value);\n");
file_put_contents($tempDir . '/telegram/telegram-app.mjs', <<<'JS'
export const getTelegram = () => ({ shareMessage:(id, callback) => { globalThis.__shares.push(id); globalThis.__shareCallback = callback; } });
export const getInitData = () => 'user-scope';
export const haptic = () => null;
JS);
file_put_contents($tempDir . '/session.mjs', "export const getSessionId = () => 'session-1';\n");

file_put_contents($tempDir . '/test.mjs', <<<'JS'
class FakeClassList {
  constructor(){ this.values = new Set(); }
  add(value){ this.values.add(value); }
  remove(value){ this.values.delete(value); }
  contains(value){ return this.values.has(value); }
}
class FakeElement {
  constructor(matches = [], dataset = {}) { this.matches = new Set(matches); this.dataset = dataset; }
  closest(selector){ return this.matches.has(selector) ? this : null; }
}
class FakeButton extends FakeElement {
  constructor(matches = [], dataset = {}) { super(matches, dataset); this.attributes = new Map(); this.classList = new FakeClassList(); }
  setAttribute(name, value){ this.attributes.set(name, value); }
  removeAttribute(name){ this.attributes.delete(name); }
}
class FakeDocument {
  constructor(){ this.listeners = new Map(); }
  addEventListener(type, callback){
    if (!this.listeners.has(type)) this.listeners.set(type, []);
    this.listeners.get(type).push(callback);
  }
  dispatch(type, target){
    const event = {
      type,
      target,
      prevented:false,
      stopped:false,
      preventDefault(){ this.prevented = true; },
      stopImmediatePropagation(){ this.stopped = true; },
    };
    for (const callback of this.listeners.get(type) || []) {
      callback(event);
      if (event.stopped) break;
    }
    return event;
  }
  querySelector(){ return null; }
}

globalThis.Element = FakeElement;
globalThis.HTMLButtonElement = FakeButton;
globalThis.document = new FakeDocument();
globalThis.__sheets = [];
globalThis.__toasts = [];
globalThis.__shares = [];
globalThis.window = {
  location:{ origin:'https://mgw.test', href:'https://mgw.test/app/v101.php?v=101' },
  setTimeout,
  clearTimeout,
  open:() => null,
};

let assertions = 0;
let createCalls = 0;
let discarded = 0;
let secondAborted = false;
function assert(condition, message){
  assertions++;
  if (!condition) throw new Error(message);
}

globalThis.fetch = window.fetch = async (input, init = {}) => {
  const body = JSON.parse(String(init.body || '{}'));
  if (body.action === 'discard_draft') {
    discarded++;
    return new Response(JSON.stringify({ok:true}), {status:200, headers:{'Content-Type':'application/json'}});
  }
  if (body.action !== 'create_link_draft') {
    return new Response(JSON.stringify({ok:true}), {status:200, headers:{'Content-Type':'application/json'}});
  }

  createCalls++;
  const call = createCalls;
  if (call === 2) {
    return await new Promise((resolve, reject) => {
      const timer = setTimeout(() => resolve(new Response(JSON.stringify({
        ok:true,
        invite:{token:'draft-two', prepared_message_id:'prepared-two', game_type:'tictactoe', board_size:3, bet:10},
      }), {status:200, headers:{'Content-Type':'application/json'}})), 80);
      init.signal?.addEventListener('abort', () => {
        clearTimeout(timer);
        secondAborted = true;
        reject(new DOMException('Aborted', 'AbortError'));
      }, {once:true});
    });
  }

  return new Response(JSON.stringify({
    ok:true,
    invite:{token:'draft-one', prepared_message_id:'prepared-one', game_type:'tictactoe', board_size:3, bet:10},
  }), {status:200, headers:{'Content-Type':'application/json'}});
};

const { initV101ShareController } = await import('./share.mjs');
initV101ShareController();

const inviteTrigger = new FakeElement(['[data-invite-friend]'], {inviteFriend:'tictactoe'});
document.dispatch('pointerdown', inviteTrigger);
await new Promise(resolve => setTimeout(resolve, 5));

const shareButton = new FakeButton(['[data-create-link-invite]']);
const shareClick = document.dispatch('click', shareButton);
await new Promise(resolve => setTimeout(resolve, 5));

assert(shareClick.prevented && shareClick.stopped, 'The v101 owner must prevent the legacy share handler.');
assert(createCalls === 1, 'A prepared share must reuse the warmed draft instead of creating a second draft on click.');
assert(globalThis.__shares.join(',') === 'prepared-one', 'The warmed Telegram prepared message must open immediately.');
assert(globalThis.__sheets.length === 0, 'No application loading or airplane sheet may appear before the native Telegram picker.');
assert(!shareButton.attributes.has('aria-busy'), 'The Share button must reset before the native Telegram picker opens.');

// Start another background warm, then choose the direct-player path.
document.dispatch('pointerdown', inviteTrigger);
await new Promise(resolve => setTimeout(resolve, 5));
const picker = new FakeElement(['[data-open-player-picker]']);
document.dispatch('pointerdown', picker);
await new Promise(resolve => setTimeout(resolve, 5));
assert(secondAborted === true, 'Opening the direct player picker must cancel only the share prewarm request.');
assert(globalThis.__toasts.length === 0, 'A cancelled background prewarm must stay silent.');
assert(discarded === 0, 'An aborted unfinished draft must not issue an invalid discard request.');

console.log(`ProductionV101SharePrewarmRuntimeTest: ${assertions} assertions passed`);
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
if ($exitCode !== 0) {
    throw new RuntimeException("V101 share prewarm test failed:\n" . implode("\n", $output));
}
fwrite(STDOUT, implode("\n", $output) . "\n");
