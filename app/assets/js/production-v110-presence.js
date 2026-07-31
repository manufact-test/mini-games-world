import { getInitData, getTelegram } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { state } from './state.js?v=27';
import { renderStats } from './screens/home-screen.js?v=74';

const PRESENCE_URL = `${window.location.origin}/bot/presence.php`;
const HEARTBEAT_MS = 4000;
const STATUS_MS = 1200;
const RETRY_MS = 500;

const runtime = window.__MGW_V110_PRESENCE__ ||= {
  initialized:false,
  started:false,
  heartbeatTimer:null,
  statusTimer:null,
  retryTimer:null,
  pingBusy:false,
  statusBusy:false,
  left:false,
};

export function initV110Presence(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  // Start immediately. Invite launches must not depend on catching a one-shot
  // app-ready event after another module has already dispatched it.
  startPresence();
  document.addEventListener('mgw:app-ready', resumePresence, { once:true });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') resumePresence();
  });
  window.addEventListener('pagehide', sendLeaveBeacon, { capture:true });

  const telegram = getTelegram();
  if (typeof telegram?.onEvent === 'function') {
    try { telegram.onEvent('activated', resumePresence); } catch (error) {}
  }
}

function startPresence(){
  if (!runtime.started) {
    runtime.started = true;
    runtime.heartbeatTimer = window.setInterval(() => {
      if (document.visibilityState === 'visible') void pingPresence();
    }, HEARTBEAT_MS);
    runtime.statusTimer = window.setInterval(() => {
      if (canReadHomeStatus()) void refreshStatus();
    }, STATUS_MS);
  }
  resumePresence();
}

function resumePresence(){
  runtime.left = false;
  window.clearTimeout(runtime.retryTimer);
  runtime.retryTimer = null;
  void pingPresence();
  if (canReadHomeStatus()) void refreshStatus();
}

async function pingPresence(){
  if (runtime.pingBusy || document.visibilityState !== 'visible') return false;
  runtime.pingBusy = true;
  runtime.left = false;
  try {
    const data = await requestPresence('ping');
    applyStats(data?.stats);
    return true;
  } catch (error) {
    scheduleRetry();
    return false;
  } finally {
    runtime.pingBusy = false;
  }
}

async function refreshStatus(){
  if (runtime.statusBusy || !canReadHomeStatus()) return;
  runtime.statusBusy = true;
  try {
    const data = await requestPresence('status');
    applyStats(data?.stats);
  } catch (error) {
    // Normal stats polling remains the fallback.
  } finally {
    runtime.statusBusy = false;
  }
}

function scheduleRetry(){
  if (runtime.retryTimer || document.visibilityState !== 'visible') return;
  runtime.retryTimer = window.setTimeout(() => {
    runtime.retryTimer = null;
    void pingPresence();
  }, RETRY_MS);
}

function canReadHomeStatus(){
  if (document.visibilityState !== 'visible') return false;
  const active = document.querySelector('.screen.active');
  return String(active?.dataset.screen || '') === 'home';
}

function applyStats(stats){
  if (!stats || typeof stats !== 'object') return;
  state.stats = stats;
  renderStats(stats);
}

async function requestPresence(action){
  const speed = window.__MGW_V101_SPEED__;
  const fetcher = typeof speed?.rawFetch === 'function'
    ? speed.rawFetch
    : window.fetch.bind(window);
  const response = await fetcher(PRESENCE_URL, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify(payload(action)),
    keepalive:action === 'leave',
    priority:'high',
    cache:'no-store',
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) throw new Error(data?.error || 'Presence request failed');
  return data;
}

function sendLeaveBeacon(){
  if (runtime.left) return;
  runtime.left = true;
  const body = JSON.stringify(payload('leave'));
  try {
    const blob = new Blob([body], { type:'application/json' });
    if (navigator.sendBeacon?.(PRESENCE_URL, blob)) return;
  } catch (error) {}

  const speed = window.__MGW_V101_SPEED__;
  const fetcher = typeof speed?.rawFetch === 'function'
    ? speed.rawFetch
    : window.fetch.bind(window);
  fetcher(PRESENCE_URL, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body,
    keepalive:true,
    priority:'low',
    cache:'no-store',
  }).catch(() => null);
}

function payload(action){
  return {
    initData:getInitData(),
    sessionId:getSessionId(),
    action,
  };
}
