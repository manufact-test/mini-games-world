import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { api } from './api/client.js?v=47';
import { openSheet, closeSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { showScreen } from './router.js?v=27';
import { haptic } from './telegram/telegram-app.js?v=27';
import { renderBalances, roomName } from './ui.js?v=89';

const HISTORY_CACHE_TTL_MS = 15000;

let installed = false;
let baseFetch = null;
let historyCache = null;

export function initInteractionLatencyCoordinator(){
  if (installed) return;
  installed = true;

  APP_CONFIG.searchIntervalMs = 800;
  APP_CONFIG.gameIntervalMs = 450;

  installZeroTransitionStyle();
  installResponseCache();
  installImmediateNavigation();

  document.addEventListener('mgw:app-ready', () => {
    prefetchHistory();
  }, { once:true });
}

function installZeroTransitionStyle(){
  const style = document.createElement('style');
  style.id = 'mgw-zero-latency-transitions';
  style.textContent = `
    #sheetOverlay,
    #sheetOverlay #sheet {
      transition:none !important;
      animation:none !important;
    }
  `;
  document.head.appendChild(style);
}

function installResponseCache(){
  baseFetch = window.fetch.bind(window);
  window.fetch = async function interactionFetch(input, init = {}){
    const meta = requestMeta(input, init);

    if (meta?.kind === 'history' && fresh(historyCache, HISTORY_CACHE_TTL_MS)) {
      refreshHistoryCacheInBackground(input, init);
      return historyResponseFromCache(historyCache);
    }

    /* Opening notifications is an authoritative user action. A cached unread
     * baseline may be useful for a badge, but it must never replace the marked-
     * read response that contains the latest invitation token and actions. */
    if (meta?.kind === 'notifications' && meta.markRead) {
      return baseFetch(input, init);
    }

    const response = await baseFetch(input, init);
    rememberHistoryResponse(meta, response);
    return response;
  };
}

function installImmediateNavigation(){
  document.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const target = origin.closest('button, [role="button"]');
    if (!target) return;

    if (target.id === 'startSearchBtn') {
      const info = document.getElementById('searchInfo');
      if (info) {
        info.textContent = `${roomName(state.room)} · участие ${Number(state.selectedBet || APP_CONFIG.matchBet)} коинов`;
      }
      closeSheet();
      showScreen('search');
      return;
    }

    if (target.id === 'cancelSearch' || target.id === 'changeSearch') {
      clearSearchTimers();
      showScreen('home');
      return;
    }

    if (target.matches('[data-create-link-invite]')) {
      queueMicrotask(() => {
        openSheet(`
          <div class="sheet-head">
            <div><h2>Подготавливаем приглашение</h2><p>Создаём защищённую ссылку для Telegram.</p></div>
            <button class="close" data-close-sheet type="button">×</button>
          </div>
          <div class="notifications-loading"><div>✈️</div><strong>Подготавливаем отправку…</strong></div>
        `);
      });
    }
  }, true);
}

function clearSearchTimers(){
  state.timers.search = clearTimer(state.timers.search);
  if (window.__MGW_SEARCH_SCREEN_RUNTIME__?.emptyRoomBotCheckTimer !== null) {
    window.clearTimeout(window.__MGW_SEARCH_SCREEN_RUNTIME__.emptyRoomBotCheckTimer);
    window.__MGW_SEARCH_SCREEN_RUNTIME__.emptyRoomBotCheckTimer = null;
  }
}

function prefetchHistory(){
  window.setTimeout(() => {
    api.history().catch(() => null);
  }, 100);
}

function requestMeta(input, init){
  const method = String(init?.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
  if (method !== 'POST') return null;

  let url;
  try {
    url = new URL(typeof input === 'string' ? input : input.url, window.location.href);
  } catch (error) {
    return null;
  }

  const payload = parsePayload(init?.body);
  if (url.pathname.endsWith('/bot/api.php') && String(payload.action || '') === 'history') {
    return { kind:'history', markRead:false };
  }
  if (url.pathname.endsWith('/bot/notifications.php')) {
    return { kind:'notifications', markRead:Boolean(payload.markRead) };
  }
  return null;
}

function rememberHistoryResponse(meta, response){
  if (meta?.kind !== 'history' || !response.ok) return;
  response.clone().json().then(data => {
    historyCache = { data, storedAt:Date.now() };
  }).catch(() => null);
}

function refreshHistoryCacheInBackground(input, init){
  baseFetch(input, init).then(response => {
    rememberHistoryResponse({ kind:'history', markRead:false }, response);
  }).catch(() => null);
}

function fresh(cache, ttl){
  return Boolean(cache?.data) && Date.now() - Number(cache.storedAt || 0) <= ttl;
}

function historyResponseFromCache(cache){
  const cached = structuredCloneSafe(cache.data);
  if (cached && typeof cached === 'object') delete cached.user;
  return jsonResponse(cached);
}

function structuredCloneSafe(value){
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}

function jsonResponse(data){
  return new Response(JSON.stringify(data), {
    status:200,
    headers:{ 'Content-Type':'application/json; charset=utf-8' },
  });
}

function parsePayload(body){
  if (typeof body !== 'string' || body === '') return {};
  try {
    const parsed = JSON.parse(body);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch (error) {
    return {};
  }
}

function clearTimer(timer){
  if (timer) window.clearInterval(timer);
  return null;
}
