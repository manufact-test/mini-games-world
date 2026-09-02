import { getInitData, getTelegram } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { beginStatsRequest, applyStatsSnapshot } from './stats-owner-v110.js?v=1121';

const PRESENCE_URL = `${window.location.origin}/bot/presence.php`;
const HEARTBEAT_MS = 4000;
const HEARTBEAT_PHASE_MIN_MS = 120;
const HEARTBEAT_PHASE_RANGE_MS = 720;
const STATUS_MS = 1200;
const RETRY_MS = 500;
const REQUEST_TIMEOUT_MS = 4500;
const presenceLeaseId = createPresenceLeaseId();

const runtime = window.__MGW_V110_PRESENCE__ ||= {
  initialized:false,
  started:false,
  appReady:false,
  heartbeatTimer:null,
  statusTimer:null,
  retryTimer:null,
  pingBusy:false,
  statusBusy:false,
  pingRequestId:0,
  statusRequestId:0,
  pingController:null,
  statusController:null,
  left:false,
  initialPingPromise:null,
  initialPresenceReady:false,
  resumeSignalPending:false,
};

export function initV110Presence(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  document.addEventListener('mgw:app-ready', () => {
    runtime.appReady = true;
    void resumePresence(true);
  }, { once:true });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      void resumePresence(true);
    } else {
      cancelInFlightRequests();
      sendLifecycleBeacon('background');
    }
  });

  window.addEventListener('pageshow', () => {
    if (document.visibilityState === 'visible') void resumePresence(true);
  }, { capture:true });

  window.addEventListener('pagehide', event => {
    if (!event.persisted) sendLeaveBeacon();
  }, { capture:true });

  const telegram = getTelegram();
  if (typeof telegram?.onEvent === 'function') {
    try { telegram.onEvent('activated', () => void resumePresence(true)); } catch (error) {}
  }

  // Presence transport starts before profile/bootstrap reads. MVP-17.4 also
  // exposes the first ping promise so the active v110 entry can make reconnect
  // restoration authoritative before it adopts a bootstrap game snapshot.
  startPresence();
}

export function waitForV110InitialPresence(){
  if (runtime.initialPresenceReady) return Promise.resolve(true);
  return runtime.initialPingPromise || Promise.resolve(false);
}

function startPresence(){
  if (!runtime.started) {
    runtime.started = true;
    scheduleHeartbeat();
    runtime.statusTimer = window.setInterval(() => {
      if (canReadHomeStatus()) void refreshStatus();
    }, STATUS_MS);
  }
  runtime.initialPingPromise = resumePresence(true);
}

function scheduleHeartbeat(){
  const firstDelay = HEARTBEAT_MS + heartbeatPhaseMs(presenceLeaseId);
  const heartbeat = () => {
    if (document.visibilityState === 'visible') void pingPresence();
    runtime.heartbeatTimer = window.setTimeout(heartbeat, HEARTBEAT_MS);
  };
  runtime.heartbeatTimer = window.setTimeout(heartbeat, firstDelay);
}

function heartbeatPhaseMs(value){
  let hash = 0;
  const source = String(value || '');
  for (let index = 0; index < source.length; index++) {
    hash = ((hash * 31) + source.charCodeAt(index)) >>> 0;
  }
  return HEARTBEAT_PHASE_MIN_MS + (hash % HEARTBEAT_PHASE_RANGE_MS);
}

function resumePresence(force = false){
  if (document.visibilityState !== 'visible') return Promise.resolve(false);
  runtime.left = false;
  runtime.resumeSignalPending = true;
  window.clearTimeout(runtime.retryTimer);
  runtime.retryTimer = null;
  if (force) cancelInFlightRequests();
  const ping = pingPresence();
  if (!runtime.initialPresenceReady) runtime.initialPingPromise = ping;
  return ping;
}

function cancelInFlightRequests(){
  runtime.pingRequestId += 1;
  runtime.statusRequestId += 1;
  runtime.pingController?.abort();
  runtime.statusController?.abort();
  runtime.pingController = null;
  runtime.statusController = null;
  runtime.pingBusy = false;
  runtime.statusBusy = false;
}

function presenceTransportBusy(){
  return runtime.pingBusy || runtime.statusBusy;
}

async function pingPresence(){
  if (presenceTransportBusy() || document.visibilityState !== 'visible') return false;

  const requestId = ++runtime.pingRequestId;
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
  const statsTicket = beginStatsRequest('presence');
  runtime.pingController = controller;
  runtime.pingBusy = true;
  runtime.left = false;

  try {
    const data = await requestPresence('ping', controller.signal);
    if (requestId !== runtime.pingRequestId) return false;
    applyStatsSnapshot(statsTicket, data?.stats);
    runtime.initialPresenceReady = true;
    if (runtime.resumeSignalPending) {
      runtime.resumeSignalPending = false;
      document.dispatchEvent(new CustomEvent('mgw:v110-presence-ready'));
    }
    return true;
  } catch (error) {
    if (requestId === runtime.pingRequestId) scheduleRetry();
    return false;
  } finally {
    window.clearTimeout(timeout);
    if (requestId === runtime.pingRequestId) {
      runtime.pingBusy = false;
      runtime.pingController = null;
    }
  }
}

async function refreshStatus(){
  if (presenceTransportBusy() || !runtime.appReady || !canReadHomeStatus()) return false;

  const requestId = ++runtime.statusRequestId;
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
  const statsTicket = beginStatsRequest('presence');
  runtime.statusController = controller;
  runtime.statusBusy = true;

  try {
    const data = await requestPresence('status', controller.signal);
    if (requestId !== runtime.statusRequestId) return false;
    applyStatsSnapshot(statsTicket, data?.stats);
    return true;
  } catch (error) {
    return false;
  } finally {
    window.clearTimeout(timeout);
    if (requestId === runtime.statusRequestId) {
      runtime.statusBusy = false;
      runtime.statusController = null;
    }
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
  if (!runtime.appReady || document.visibilityState !== 'visible') return false;
  const active = document.querySelector('.screen.active');
  return String(active?.dataset.screen || '') === 'home';
}

async function requestPresence(action, signal){
  const speed = window.__MGW_V101_SPEED__;
  const fetcher = typeof speed?.rawFetch === 'function'
    ? speed.rawFetch
    : window.fetch.bind(window);
  const response = await fetcher(PRESENCE_URL, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify(payload(action)),
    keepalive:action === 'leave' || action === 'background',
    priority:'high',
    cache:'no-store',
    signal,
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) throw new Error(data?.error || 'Presence request failed');
  return data;
}

function sendLeaveBeacon(){
  if (runtime.left) return;
  runtime.left = true;
  cancelInFlightRequests();
  sendLifecycleBeacon('leave');
}

function sendLifecycleBeacon(action){
  const body = JSON.stringify(payload(action));
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
