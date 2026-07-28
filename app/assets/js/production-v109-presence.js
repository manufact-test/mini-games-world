import { getInitData, getTelegram } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { state } from './state.js?v=27';
import { renderStats } from './screens/home-screen.js?v=74';

const PRESENCE_URL = `${window.location.origin}/bot/presence.php`;
const HEARTBEAT_MS = 4000;
const STATUS_MS = 1200;

const runtime = window.__MGW_V109_PRESENCE__ ||= {
  initialized:false,
  heartbeatTimer:null,
  statusTimer:null,
  pingBusy:false,
  statusBusy:false,
  left:false,
};

export function initV109Presence(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  document.addEventListener('mgw:app-ready', startPresence, { once:true });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') resumePresence();
  });
  window.addEventListener('pagehide', sendLeaveBeacon, { capture:true });

  const telegram = getTelegram();
  if (typeof telegram?.onEvent === 'function') {
    try {
      telegram.onEvent('activated', resumePresence);
      // Deactivated means backgrounded, not offline. Do not remove the account.
    } catch (error) {}
  }
}

function startPresence(){
  resumePresence();
  runtime.heartbeatTimer = window.setInterval(() => {
    if (document.visibilityState === 'visible') void pingPresence();
  }, HEARTBEAT_MS);
  runtime.statusTimer = window.setInterval(() => {
    if (canReadHomeStatus()) void refreshStatus();
  }, STATUS_MS);
}

function resumePresence(){
  runtime.left = false;
  void pingPresence();
  if (canReadHomeStatus()) void refreshStatus();
}

async function pingPresence(){
  if (runtime.pingBusy || document.visibilityState !== 'visible') return;
  runtime.pingBusy = true;
  runtime.left = false;
  try {
    const data = await requestPresence('ping');
    applyStats(data?.stats);
  } catch (error) {
    // Presence is best-effort and never blocks the interface.
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
    // Normal stats remain the fallback.
  } finally {
    runtime.statusBusy = false;
  }
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
  const response = await fetch(PRESENCE_URL, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify(payload(action)),
    keepalive:action === 'leave',
    priority:'low',
    mgwPrefetch:true,
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

  fetch(PRESENCE_URL, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body,
    keepalive:true,
    priority:'low',
  }).catch(() => null);
}

function payload(action){
  return {
    initData:getInitData(),
    sessionId:getSessionId(),
    action,
  };
}
