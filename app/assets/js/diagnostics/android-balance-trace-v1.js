const TRACE_ENDPOINT = '/bot/staging-android-balance-trace.php';
const START_MS = performance.now();
const TRACE_ID = makeTraceId();
let domObserver = null;
let textContentPatched = false;

function makeTraceId(){
  const bytes = new Uint8Array(8);
  crypto.getRandomValues(bytes);
  return [...bytes].map(v => v.toString(16).padStart(2, '0')).join('');
}

export function balanceState(value){
  if (value === null || value === undefined || value === '') return 'missing';
  if (Number.isSafeInteger(value) && value >= 0) return value === 0 ? 'zero' : 'nonzero';
  if (typeof value === 'string' && /^\d+$/.test(value.trim())) {
    try {
      const parsed = Number(value.trim());
      if (Number.isSafeInteger(parsed) && parsed >= 0) return parsed === 0 ? 'zero' : 'nonzero';
    } catch (_) {}
  }
  return 'invalid';
}

export function traceBalanceEvent(event, states = {}){
  if (location.hostname !== 'seashell-okapi-889488.hostingersite.com' || window.__MGW_ANDROID_SHELL__ !== true) return;
  const normalized = {};
  for (const [key, value] of Object.entries(states || {})) {
    normalized[String(key).slice(0, 32)] = balanceState(value);
  }
  const payload = {
    trace_id: TRACE_ID,
    event:String(event || '').slice(0, 48),
    elapsed_ms:Math.max(0, Math.min(60000, Math.round(performance.now() - START_MS))),
    build:String(window.__MGW_BUILD__ || '').slice(0, 96),
    states:normalized,
  };
  void fetch(TRACE_ENDPOINT, {
    method:'POST',
    credentials:'same-origin',
    keepalive:true,
    cache:'no-store',
    headers:{
      'Content-Type':'application/json',
      'X-MGW-Diagnostic':'android-balance-v1',
    },
    body:JSON.stringify(payload),
  }).catch(() => {});
}


function writerFromStack(stack){
  for (const line of String(stack || '').split('\n')) {
    const match = line.match(/\/([^/?#\\s]+\.js)(?:[?#][^:\\s)]*)?:\\d+:\\d+/i);
    if (!match) continue;
    const file = String(match[1] || '').toLowerCase();
    if (!file || file === 'android-balance-trace-v1.js' || file === 'ui.js') continue;
    return file.replace(/[^a-z0-9._:-]+/g, '_').slice(0, 32) || 'unknown';
  }
  return 'unknown';
}

function installBalanceWriterTrace(){
  if (textContentPatched
      || location.hostname !== 'seashell-okapi-889488.hostingersite.com'
      || window.__MGW_ANDROID_SHELL__ !== true) return;

  const descriptor = Object.getOwnPropertyDescriptor(Node.prototype, 'textContent');
  if (!descriptor?.get || !descriptor?.set || descriptor.configurable !== true) return;

  Object.defineProperty(Node.prototype, 'textContent', {
    ...descriptor,
    set(value){
      if (this instanceof HTMLElement && this.id === 'balanceUnified') {
        const writer = writerFromStack(new Error().stack);
        traceBalanceEvent(`dom.writer.${writer}`, { dom:value });
      }
      return descriptor.set.call(this, value);
    },
  });
  textContentPatched = true;
}

export function installBalanceDomTrace(){
  installBalanceWriterTrace();
  if (domObserver || location.hostname !== 'seashell-okapi-889488.hostingersite.com' || window.__MGW_ANDROID_SHELL__ !== true) return;
  const attach = () => {
    const target = document.getElementById('balanceUnified');
    if (!(target instanceof HTMLElement)) return false;
    traceBalanceEvent('dom.balance.initial', { dom:target.textContent });
    domObserver = new MutationObserver(() => {
      traceBalanceEvent('dom.balance.change', { dom:target.textContent });
    });
    domObserver.observe(target, { childList:true, subtree:true, characterData:true });
    return true;
  };
  if (!attach()) {
    document.addEventListener('DOMContentLoaded', attach, { once:true });
  }
}
