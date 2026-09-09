import { getInitData, getTelegram } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { state } from './state.js?v=27';
import { renderStats } from './screens/home-screen.js?v=74';

const PRESENCE_URL = `${window.location.origin}/bot/presence.php`;
const HEARTBEAT_MS = 4000;
const STATUS_MS = 1200;
const RETRY_MS = 500;
const REQUEST_TIMEOUT_MS = 4500;
const presenceLeaseId = createPresenceLeaseId();

const runtime = window.__MGW_V115_PRESENCE__ ||= {
  initialized:false,
  appReady:false,
  heartbeatTimer:null,
  statusTimer:null,
  retryTimer:null,
  pingBusy:false,
  statusBusy:false,
  left:false,
};

export function initV115Presence(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  document.addEventListener('mgw:app-ready', () => {
    runtime.appReady = true;
    resumePresence();
  }, { once:true });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') resumePresence();
  });

  window.addEventListener('pageshow', () => {
    if (document.visibilityState === 'visible') resumePresence();
  }, { capture:true });

  window.addEventListener('pagehide', event => {
    if (!event.persisted) sendLeaveBeacon();
  }, { capture:true });

  const telegram = getTelegram();
  if (typeof telegram?.onEvent === 'function') {
    try { telegram.onEvent('activated', resumePresence); } catch (error) {}
  }

  runtime.heartbeatTimer = window.setInterval(() => {
    if (document.visibilityState === 'visible') void pingPresence();
  }, HEARTBEAT_MS);
  runtime.statusTimer = window.setInterval(() => {
    if (canReadHomeStatus()) void refreshStatus();
  }, STATUS_MS);

  // Start before bootstrap so a Telegram invitation document owns a live lease
  // even when it did not enter through the ordinary /start path.
  resumePresence();
}

function resumePresence(){
  if (document.visibilityState !== 'visible') return;
  runtime.left = false;
  window.clearTimeout(runtime.retryTimer);
  runtime.retryTimer = null;
  void pingPresence();
}

async function pingPresence(){
  if (runtime.pingBusy || document.visibilityState !== 'visible') return false;
  runtime.pingBusy = true;
  try {
    const data = await requestPresence('ping');
    applyOnlinePlayers(data?.stats);
    return true;
  } catch (error) {
    scheduleRetry();
    return false;
  } finally {
    runtime.pingBusy = false;
  }
}

async function refreshStatus(){
  if (runtime.statusBusy || !canReadHomeStatus()) return false;
  runtime.statusBusy = true;
  try {
    const data = await requestPresence('status');
    applyOnlinePlayers(data?.stats);
    return true;
  } catch (error) {
    return false;
  } finally {
    runtime.statusBusy = false;
  }
}

function applyOnlinePlayers(stats){
  if (!stats || !Object.prototype.hasOwnProperty.call(stats, 'online_players')) return;
  state.stats = {
    ...(state.stats && typeof state.stats === 'object' ? state.stats : {}),
    online_players:stats.online_players,
  };
  window.__MGW_V115_PRESENCE_ONLINE__ = Number(stats.online_players || 0);
  renderStats(state.stats);
}

function scheduleRetry(){
  if (runtime.retryTimer || document.visibilityState !== 'visible') return;
  runtime.retryTimer = window.setTimeout(() => {
    runtime.retryTimer = null;
    void pingPresence();
  }, RETRY_MS);
}

function canReadHomeStatus(){
  if (!runtime.appReady || document.visibilityState !== 'visible') return false;
  const active = document.querySelector('.screen.active');
  return String(active?.dataset.screen || '') === 'home';
}

async function requestPresence(action){
  const speed = window.__MGW_V101_SPEED__;
  const fetcher = typeof speed?.rawFetch === 'function'
    ? speed.rawFetch
    : window.fetch.bind(window);
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
  try {
    const response = await fetcher(PRESENCE_URL, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      body:JSON.stringify(payload(action)),
      keepalive:action === 'leave',
      priority:'high',
      cache:'no-store',
      signal:controller.signal,
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data || data.ok === false) throw new Error(data?.error || 'Presence request failed');
    return data;
  } finally {
    window.clearTimeout(timeout);
  }
}

function sendLeaveBeacon(){
  if (runtime.left) return;
  runtime.left = true;
  const body = JSON.stringify(payload('leave'));
  try {
    const blob = new Blob([body], { type:'application/json' });
    if (navigator.sendBeacon?.(PRESENCE_URL, blob)) return;
  } catch (error) {}

  window.fetch(PRESENCE_URL, {
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
    presenceLeaseId,
    action,
  };
}

function createPresenceLeaseId(){
  const random = globalThis.crypto?.randomUUID
    ? globalThis.crypto.randomUUID()
    : `${Date.now()}_${Math.random().toString(16).slice(2)}`;
  return `presence_${random}`;
}
