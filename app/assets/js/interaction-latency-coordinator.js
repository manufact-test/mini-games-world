import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { api } from './api/client.js?v=47';
import { openSheet, closeSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { showScreen } from './router.js?v=27';
import { haptic } from './telegram/telegram-app.js?v=27';
import { renderBalances, roomName } from './ui.js?v=89';
import { startGamePolling } from './screens/game-screen.js?v=74';

const HISTORY_CACHE_TTL_MS = 15000;
const NOTIFICATIONS_CACHE_TTL_MS = 10000;

let installed = false;
let baseFetch = null;
let gameActionBusy = false;
let historyCache = null;
let notificationsCache = null;

export function initInteractionLatencyCoordinator(){
  if (installed) return;
  installed = true;

  APP_CONFIG.searchIntervalMs = 800;
  APP_CONFIG.gameIntervalMs = 450;

  installZeroTransitionStyle();
  installResponseCache();
  installImmediateNavigation();
  installOptimisticTicTacToe();

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
      refreshCacheInBackground(input, init, 'history');
      return historyResponseFromCache(historyCache);
    }

    if (meta?.kind === 'notifications' && meta.markRead
      && fresh(notificationsCache, NOTIFICATIONS_CACHE_TTL_MS)) {
      refreshCacheInBackground(input, init, 'notifications');
      const cached = structuredCloneSafe(notificationsCache.data);
      cached.unread_count = 0;
      return jsonResponse(cached);
    }

    const response = await baseFetch(input, init);
    rememberResponse(meta, response);
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

function installOptimisticTicTacToe(){
  document.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const button = origin.closest('[data-game-cell]');
    if (!button || gameActionBusy) return;

    const game = state.activeGame;
    const userId = String(state.user?.id || '');
    if (!game?.id || String(game.game_type || 'tictactoe') !== 'tictactoe') return;
    if (String(game.turn || '') !== userId || button.disabled || button.textContent.trim() !== '') return;

    event.preventDefault();
    event.stopImmediatePropagation();

    const cell = Number(button.dataset.gameCell);
    const symbol = symbolForUser(game, userId);
    if (!Number.isInteger(cell) || cell < 0 || !symbol) return;

    submitOptimisticTicTacToe(game, cell, symbol, button);
  }, true);
}

async function submitOptimisticTicTacToe(game, cell, symbol, button){
  gameActionBusy = true;
  haptic('light');

  const board = document.getElementById('gameBoard');
  const previousHtml = board?.innerHTML || '';
  const previousClass = board?.className || '';

  if (board) {
    board.querySelectorAll('[data-game-cell]').forEach(item => {
      item.disabled = true;
      item.classList.add('locked');
    });
  }

  button.textContent = symbol === 'X' ? '✕' : '○';
  button.classList.remove('locked');
  button.classList.add(symbol === 'X' ? 'x' : 'o', 'is-optimistic');

  state.timers.game = clearTimer(state.timers.game);

  try {
    const result = await api.gameAction(game.id, { type:'cell', cell });
    if (result.user) {
      state.user = result.user;
      state.session = result.session || state.session;
      renderBalances(state.user);
    }
    if (result.game) state.activeGame = result.game;
    startGamePolling(game.id);
  } catch (error) {
    if (board) {
      board.className = previousClass;
      board.innerHTML = previousHtml;
    }
    toast(error.message || 'Не удалось выполнить ход.');
    startGamePolling(game.id);
  } finally {
    gameActionBusy = false;
  }
}

function symbolForUser(game, userId){
  const player = Array.isArray(game.players)
    ? game.players.find(item => String(item?.id || '') === userId)
    : null;
  return String(player?.symbol || '');
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

function rememberResponse(meta, response){
  if (!meta || !response.ok) return;
  response.clone().json().then(data => {
    const value = { data, storedAt:Date.now() };
    if (meta.kind === 'history') historyCache = value;
    if (meta.kind === 'notifications') notificationsCache = value;
  }).catch(() => null);
}

function refreshCacheInBackground(input, init, kind){
  baseFetch(input, init).then(response => {
    rememberResponse({ kind, markRead:false }, response);
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
