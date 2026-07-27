<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/production-v102-share-controller.js');
$models = file_get_contents($root . '/app/assets/js/production-v101-speed-models.js');
if (!is_string($source) || !is_string($models)) throw new RuntimeException('Cannot read v102 share sources.');

$tempDir = sys_get_temp_dir() . '/mgw_v102_share_' . bin2hex(random_bytes(6));
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
file_put_contents($tempDir . '/config.mjs', "export const APP_CONFIG = { matchBet:10, goldBets:[10,20] };\n");
file_put_contents($tempDir . '/components/sheet.mjs', <<<'JS'
export function openSheet(html){
  globalThis.__sheet.innerHTML = String(html);
  globalThis.__overlay.classList.add('active');
  globalThis.__sheetOpens.push(String(html));
}
JS);
file_put_contents($tempDir . '/components/toast.mjs', "export const toast = text => globalThis.__toasts.push(String(text));\n");
file_put_contents($tempDir . '/session.mjs', "export const getSessionId = () => 'session-1';\n");
file_put_contents($tempDir . '/telegram/telegram-app.mjs', <<<'JS'
export const getTelegram = () => globalThis.__telegram;
export const getInitData = () => 'user-scope';
export const haptic = () => null;
JS);

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
  constructor(){
    this.listeners = new Map();
    this.visibilityState = 'visible';
    this.documentElement = {style:{}};
    this.body = {style:{}};
  }
  addEventListener(type, callback){
    if (!this.listeners.has(type)) this.listeners.set(type, []);
    this.listeners.get(type).push(callback);
  }
  dispatch(type, target = null){
    const event = {
      type, target,
      preventDefault(){ this.prevented = true; },
      stopImmediatePropagation(){ this.stopped = true; },
    };
    for (const callback of this.listeners.get(type) || []) {
      callback(event);
      if (event.stopped) break;
    }
    return event;
  }
  getElementById(id){
    if (id === 'sheetOverlay') return globalThis.__overlay;
    if (id === 'sheet') return globalThis.__sheet;
    return null;
  }
  querySelector(){ return null; }
}

globalThis.Element = FakeElement;
globalThis.HTMLButtonElement = FakeButton;
globalThis.document = new FakeDocument();
globalThis.window = { location:{origin:'https://mgw.test'}, setTimeout, clearTimeout, open:() => null };
globalThis.__overlay = {classList:new FakeClassList()};
globalThis.__sheet = {innerHTML:'<div id="conditions">Условия приглашения</div>', offsetHeight:1};
globalThis.__overlay.classList.add('active');
globalThis.__sheetOpens = [];
globalThis.__toasts = [];

let shareCallback = null;
let activated = null;
let shareCount = 0;
let discardCount = 0;
globalThis.__telegram = {
  onEvent(name, callback){ if (name === 'activated') activated = callback; },
  shareMessage(id, callback){
    shareCount++;
    shareCallback = callback;
    globalThis.__overlay.classList.remove('active');
  },
  openTelegramLink() {},
};

let draftNumber = 0;
globalThis.fetch = window.fetch = async (input, init = {}) => {
  const body = JSON.parse(String(init.body || '{}'));
  if (body.action === 'create_link_draft') {
    draftNumber++;
    return new Response(JSON.stringify({ok:true, invite:{
      token:`draft-${draftNumber}`,
      prepared_message_id:`prepared-${draftNumber}`,
      game_type:'tictactoe', game_title:'Крестики-нолики', room:'match', board_size:3, bet:10,
    }}), {status:200, headers:{'Content-Type':'application/json'}});
  }
  if (body.action === 'confirm_shared') {
    return new Response(JSON.stringify({ok:true, invite:{
      token:body.token, game_type:'tictactoe', game_title:'Крестики-нолики', room:'match', board_size:3, bet:10,
    }, unread_count:0}), {status:200, headers:{'Content-Type':'application/json'}});
  }
  if (body.action === 'discard_draft') discardCount++;
  return new Response(JSON.stringify({ok:true}), {status:200, headers:{'Content-Type':'application/json'}});
};

let assertions = 0;
function assert(condition, message){ assertions++; if (!condition) throw new Error(message); }
const { initV102ShareController } = await import('./share.mjs');
initV102ShareController();

const invite = new FakeElement(['[data-invite-friend]'], {inviteFriend:'tictactoe'});
document.dispatch('pointerdown', invite);
await new Promise(resolve => setTimeout(resolve, 5));
const button = new FakeButton(['[data-create-link-invite]']);
document.dispatch('click', button);
await new Promise(resolve => setTimeout(resolve, 5));
assert(shareCount === 1 && typeof shareCallback === 'function', 'Prepared Telegram share must open once.');
assert(!globalThis.__overlay.classList.contains('active'), 'The test must simulate Telegram temporarily hiding the Mini App sheet.');

activated();
assert(globalThis.__overlay.classList.contains('active'), 'Telegram activated event must restore the existing invitation surface before callback.');
assert(globalThis.__sheet.innerHTML.includes('Условия приглашения'), 'Return restoration must preserve the existing conditions sheet, not paint a new loading view.');

shareCallback(true);
await new Promise(resolve => setTimeout(resolve, 5));
assert(globalThis.__sheet.innerHTML.includes('Приглашение отправлено'), 'Successful callback must replace the restored conditions with the final waiting sheet.');
assert(!globalThis.__sheetOpens.some(html => html.includes('Ждём результата отправки') || html.includes('✈️')), 'The v102 return path must never restore the historical airplane/loading sheet.');

// A cancelled native share must return to the same conditions surface.
globalThis.__sheet.innerHTML = '<div id="conditions-two">Другие условия</div>';
globalThis.__overlay.classList.add('active');
document.dispatch('pointerdown', invite);
await new Promise(resolve => setTimeout(resolve, 5));
const secondButton = new FakeButton(['[data-create-link-invite]']);
document.dispatch('click', secondButton);
await new Promise(resolve => setTimeout(resolve, 5));
shareCallback(false);
await new Promise(resolve => setTimeout(resolve, 5));
assert(globalThis.__overlay.classList.contains('active') && globalThis.__sheet.innerHTML.includes('Другие условия'), 'Cancelled share must restore the exact prior surface.');
assert(discardCount >= 1, 'Cancelled prepared draft must be discarded asynchronously.');
assert(globalThis.__toasts.length === 0, 'Normal Telegram return and cancel paths must stay free of error toasts.');

console.log(`ProductionV102ShareReturnRuntimeTest: ${assertions} assertions passed`);
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
if ($exitCode !== 0) throw new RuntimeException("V102 share return test failed:\n" . implode("\n", $output));
fwrite(STDOUT, implode("\n", $output) . "\n");
