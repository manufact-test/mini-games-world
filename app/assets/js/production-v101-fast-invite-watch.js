import { getInitData } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const FAST_INTERVAL_MS = 350;
const FAST_WINDOW_MS = 7000;

const runtime = window.__MGW_V101_FAST_INVITES__ ||= {
  initialized:false,
  baseline:false,
  seen:new Set(),
  timer:null,
  deadline:0,
  busy:false,
};

export function initV101FastInviteWatch(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  document.addEventListener('mgw:app-ready', () => primeBaseline(), { once:true });
  document.addEventListener('mgw:notification-sync', event => {
    const id = String(event.detail?.item?.id || '');
    if (id) runtime.seen.add(id);
  });
  document.addEventListener('mgw:game-dismissed', () => startFastWindow());
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') stopFastWindow();
  });
}

function startFastWindow(){
  runtime.deadline = Date.now() + FAST_WINDOW_MS;
  window.clearTimeout(runtime.timer);
  void tick(true);
}

function stopFastWindow(){
  window.clearTimeout(runtime.timer);
  runtime.timer = null;
  runtime.deadline = 0;
}

async function primeBaseline(){
  const result = await syncRequest().catch(() => null);
  rememberEvents(result?.invite_events || []);
  runtime.baseline = true;
}

async function tick(announce){
  if (runtime.busy || document.visibilityState !== 'visible') return scheduleNext();
  if (Date.now() > runtime.deadline) return stopFastWindow();

  runtime.busy = true;
  let found = false;
  try {
    const result = await syncRequest();
    const events = Array.isArray(result?.invite_events) ? result.invite_events : [];
    const unreadCount = Number(result?.unread_count || 0);
    document.dispatchEvent(new CustomEvent('mgw:notification-count', { detail:{ unreadCount } }));

    if (!runtime.baseline || !announce) {
      rememberEvents(events);
      runtime.baseline = true;
    } else {
      const fresh = events.filter(item => {
        const id = String(item?.id || '');
        return id && !item?.read && !runtime.seen.has(id);
      }).reverse();
      for (const item of fresh) {
        const id = String(item.id || '');
        runtime.seen.add(id);
        found = true;
        document.dispatchEvent(new CustomEvent('mgw:notification-sync', {
          detail:{ item, unreadCount },
        }));
      }
    }
  } catch (error) {
    // The normal invitation sync remains the fallback.
  } finally {
    runtime.busy = false;
  }

  if (found) stopFastWindow();
  else scheduleNext();
}

function scheduleNext(){
  if (!runtime.deadline || Date.now() > runtime.deadline) return stopFastWindow();
  window.clearTimeout(runtime.timer);
  runtime.timer = window.setTimeout(() => tick(true), FAST_INTERVAL_MS);
}

function rememberEvents(items){
  for (const item of Array.isArray(items) ? items : []) {
    const id = String(item?.id || '');
    if (id) runtime.seen.add(id);
  }
}

async function syncRequest(){
  const response = await fetch(INVITES_URL, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({
      initData:getInitData(),
      sessionId:getSessionId(),
      action:'sync',
      token:'',
    }),
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) throw new Error(data?.error || 'Invite sync failed');
  return data;
}
