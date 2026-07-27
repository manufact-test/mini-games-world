<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runtimeSource = file_get_contents($root . '/app/assets/js/production-v101-speed-runtime.js');
$modelsSource = file_get_contents($root . '/app/assets/js/production-v101-speed-models.js');
if (!is_string($runtimeSource) || !is_string($modelsSource)) {
    throw new RuntimeException('Cannot read v101 runtime sources.');
}

$tempDir = sys_get_temp_dir() . '/mgw_v101_runtime_' . bin2hex(random_bytes(6));
mkdir($tempDir . '/telegram', 0700, true);
file_put_contents($tempDir . '/runtime.mjs', str_replace(
    ["'./telegram/telegram-app.js?v=27'", "'./session.js?v=27'", "'./production-v101-speed-models.js?v=101'"],
    ["'./telegram/telegram-app.mjs'", "'./session.mjs'", "'./models.mjs'"],
    $runtimeSource
));
file_put_contents($tempDir . '/models.mjs', $modelsSource);
file_put_contents($tempDir . '/telegram/telegram-app.mjs', "export const getInitData = () => 'user-scope';\n");
file_put_contents($tempDir . '/session.mjs', "export const getSessionId = () => 'session-1';\n");

$test = <<<'JS'
class FakeCustomEvent {
  constructor(type, options = {}) { this.type = type; this.detail = options.detail; this.target = null; }
}
class FakeElement {
  constructor(match = '') { this.match = match; }
  closest(selector) { return this.match === selector ? this : null; }
}
class FakeDocument {
  constructor(){ this.visibilityState = 'visible'; this.listeners = new Map(); }
  addEventListener(type, callback){
    if (!this.listeners.has(type)) this.listeners.set(type, []);
    this.listeners.get(type).push(callback);
  }
  dispatchEvent(event){
    for (const callback of this.listeners.get(event.type) || []) callback(event);
    return true;
  }
  querySelector(){ return null; }
}

globalThis.CustomEvent = FakeCustomEvent;
globalThis.Element = FakeElement;
globalThis.document = new FakeDocument();

globalThis.window = {
  location:{ origin:'https://mgw.test', href:'https://mgw.test/app/v101.php?v=101' },
  setTimeout,
  clearTimeout,
  setInterval,
  clearInterval,
  requestIdleCallback:callback => setTimeout(() => callback({ didTimeout:false, timeRemaining:() => 50 }), 0),
};

let assertions = 0;
const calls = [];
let profileCalls = 0;
let notificationCalls = 0;
let gameStateAborted = false;

function assert(condition, message){
  assertions++;
  if (!condition) throw new Error(message);
}

window.fetch = async (input, init = {}) => {
  const body = JSON.parse(String(init.body || '{}'));
  calls.push({ path:new URL(typeof input === 'string' ? input : input.url).pathname, action:body.action || '', priority:init.priority || 'auto' });

  if (body.action === 'game_state') {
    return await new Promise((resolve, reject) => {
      const timer = setTimeout(() => resolve(new Response(JSON.stringify({ ok:true, game:null }), { status:200, headers:{'Content-Type':'application/json'} })), 100);
      init.signal?.addEventListener('abort', () => {
        clearTimeout(timer);
        gameStateAborted = true;
        reject(new DOMException('Aborted', 'AbortError'));
      }, { once:true });
    });
  }

  if (body.action === 'bootstrap') {
    return new Response(JSON.stringify({
      ok:true,
      stats:{ games:7 },
      shop:{ available:80, items:[] },
      weekly_match:{ eligible:true },
      session:{ locked:false },
    }), { status:200, headers:{'Content-Type':'application/json'} });
  }

  if (body.action === 'profile') {
    profileCalls++;
    return new Response(JSON.stringify({ ok:true, user:{ id:'u1' }, profile:{ wins:3 } }), { status:200, headers:{'Content-Type':'application/json'} });
  }

  if (new URL(typeof input === 'string' ? input : input.url).pathname.endsWith('/bot/notifications.php')) {
    notificationCalls++;
    return new Response(JSON.stringify({
      ok:true,
      unread_count:body.markRead ? 0 : 1,
      items:[{ id:'n1', type:'invite_rematch_received', title:'Вам предлагают реванш', read:Boolean(body.markRead) }],
    }), { status:200, headers:{'Content-Type':'application/json'} });
  }

  if (body.action === 'game_action') {
    return new Response(JSON.stringify({
      ok:true,
      me:{ id:'u1' },
      game:{ id:'g1', status:'finished', winner_id:'u1', players:[{id:'u1'},{id:'u2'}] },
    }), { status:200, headers:{'Content-Type':'application/json'} });
  }

  return new Response(JSON.stringify({ ok:true }), { status:200, headers:{'Content-Type':'application/json'} });
};

globalThis.fetch = (...args) => window.fetch(...args);

const { initV101SpeedRuntime } = await import('./runtime.mjs');
initV101SpeedRuntime();

const request = (path, payload) => window.fetch(`https://mgw.test${path}`, {
  method:'POST',
  headers:{'Content-Type':'application/json'},
  body:JSON.stringify({ initData:'user-scope', sessionId:'session-1', ...payload }),
});

await (await request('/bot/api.php', { action:'bootstrap' })).json();
const statsBefore = calls.filter(call => call.action === 'stats').length;
const stats = await (await request('/bot/api.php', { action:'stats' })).json();
assert(stats.stats.games === 7, 'Bootstrap stats must seed an immediate cached response.');
assert(calls.filter(call => call.action === 'stats').length === statsBefore, 'Seeded stats must not repeat the server request.');

await (await request('/bot/api.php', { action:'profile' })).json();
await (await request('/bot/api.php', { action:'profile' })).json();
assert(profileCalls === 1, 'Repeated profile opening must reuse the bounded cache.');

await (await request('/bot/notifications.php', { markRead:false })).json();
const readNotifications = await (await request('/bot/notifications.php', { markRead:true })).json();
assert(readNotifications.unread_count === 0 && readNotifications.items[0].read === true, 'Cached notification opening must mark the visible sheet read immediately.');
assert(readNotifications.items[0].actions.join(',') === 'accept,decline', 'Fast rematch notification must already contain actionable controls.');
await new Promise(resolve => setTimeout(resolve, 0));
assert(notificationCalls >= 2, 'The optimistic notification read must still confirm mark-read with the server.');

const pollPromise = request('/bot/api.php', { action:'game_state', gameId:'g1' }).catch(error => error);
await new Promise(resolve => setTimeout(resolve, 5));
document.dispatchEvent({ type:'pointerdown', target:new FakeElement('#gameBoard button') });
const pollError = await pollPromise;
assert(gameStateAborted === true && pollError?.name === 'AbortError', 'A user board press must abort an older game-state request silently.');

let finished = null;
document.addEventListener('mgw:v101-finished-response', event => { finished = event.detail; });
await (await request('/bot/api.php', { action:'game_action', gameId:'g1', move:{cell:2} })).json();
assert(finished?.game?.status === 'finished' && finished.me.id === 'u1', 'A server-confirmed final action must publish the fast result event.');
assert(calls.find(call => call.action === 'game_action')?.priority === 'high', 'Game actions must receive high fetch priority.');

console.log(`ProductionV101SpeedRuntimeIntegrationTest: ${assertions} assertions passed`);
JS;

file_put_contents($tempDir . '/test.mjs', $test);
$output = [];
$exitCode = 0;
exec('node ' . escapeshellarg($tempDir . '/test.mjs') . ' 2>&1', $output, $exitCode);

foreach ([$tempDir . '/test.mjs', $tempDir . '/runtime.mjs', $tempDir . '/models.mjs', $tempDir . '/session.mjs', $tempDir . '/telegram/telegram-app.mjs'] as $file) @unlink($file);
@rmdir($tempDir . '/telegram');
@rmdir($tempDir);

if ($exitCode !== 0) {
    throw new RuntimeException("V101 speed runtime integration test failed:\n" . implode("\n", $output));
}
fwrite(STDOUT, implode("\n", $output) . "\n");
