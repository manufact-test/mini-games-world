import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { api } from './api/client.js?v=47';
import { closeSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { showScreen } from './router.js?v=27';
import { clearTimer, renderBalances, roomName } from './ui.js?v=89';
import { startSearchPolling } from './screens/search-screen.js?v=74';
import { startGamePolling } from './screens/game-screen.js?v=74';
import { gameTypeOf } from './games/game-router.js?v=74';

const CACHE_PREFIX = 'mgw_v94_read_cache:';
const TECHNICAL_ERROR_PATTERN = /(failed to fetch|network\s*error|networkerror|load failed|unexpected token|internal server error|too many requests|service unavailable|gateway timeout|request aborted)/iu;

let initialized = false;
let nativeFetch = null;
let bootstrapSnapshot = null;
let rematchBusy = false;

export function initProductionUiStabilityFix(){
  if (initialized) return;
  initialized = true;

  installResilientReadFetch();
  installScreenLifecycle();
  installTechnicalToastNormalizer();
}

function installResilientReadFetch(){
  nativeFetch = window.fetch.bind(window);
  window.fetch = resilientReadFetch;
}

async function resilientReadFetch(input, init = {}){
  const meta = readRequestMeta(input, init);

  try {
    const response = await nativeFetch(input, init);
    if (response.ok) rememberReadResponse(meta, response);

    if (!response.ok && canUseReadFallback(meta, response.status)) {
      const fallback = readFallback(meta);
      if (fallback) return jsonResponse(fallback);
    }

    return response;
  } catch (error) {
    const fallback = readFallback(meta);
    if (fallback) return jsonResponse(fallback);
    throw error;
  }
}

function readRequestMeta(input, init){
  const method = String(init?.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
  if (method !== 'POST') return null;

  let url;
  try {
    url = new URL(typeof input === 'string' ? input : input.url, window.location.href);
  } catch (error) {
    return null;
  }

  const payload = parsePayload(init?.body);
  if (url.pathname.endsWith('/bot/api.php')) {
    const action = String(payload.action || '');
    if (['bootstrap', 'profile', 'history'].includes(action)) return { kind:action };
    return null;
  }
  if (url.pathname.endsWith('/bot/notifications.php')) return { kind:'notifications' };
  if (url.pathname.endsWith('/bot/invite-opponents.php')) return { kind:'opponents' };
  if (url.pathname.endsWith('/bot/shop-history.php')) return { kind:'shop_orders' };
  return null;
}

function rememberReadResponse(meta, response){
  if (!meta) return;

  response.clone().json().then(data => {
    if (!data || typeof data !== 'object' || data.ok === false) return;

    if (meta.kind === 'bootstrap') {
      bootstrapSnapshot = data;
      return;
    }

    const userId = activeUserId();
    if (!userId) return;
    try {
      localStorage.setItem(cacheKey(userId, meta.kind), JSON.stringify({ stored_at:Date.now(), data }));
    } catch (error) {
      // A private or full WebView storage must not break the live response.
    }
  }).catch(() => null);
}

function canUseReadFallback(meta, status){
  if (!meta || meta.kind === 'bootstrap' || !bootstrapSnapshot?.user) return false;
  return Number(status || 0) === 429 || Number(status || 0) >= 500;
}

function readFallback(meta){
  if (!meta || meta.kind === 'bootstrap' || !bootstrapSnapshot?.user) return null;

  const userId = activeUserId();
  if (userId) {
    try {
      const cached = JSON.parse(localStorage.getItem(cacheKey(userId, meta.kind)) || 'null');
      if (cached?.data && typeof cached.data === 'object') return cached.data;
    } catch (error) {
      // Ignore malformed or unavailable stale cache.
    }
  }

  if (meta.kind === 'profile') {
    return {
      ok:true,
      user:bootstrapSnapshot.user,
      stats:null,
      history:null,
      session:bootstrapSnapshot.session || null,
      degraded_read:true,
    };
  }

  if (meta.kind === 'history') {
    return {
      ok:true,
      user:bootstrapSnapshot.user,
      history:{ matches:[], operations:[] },
      topups:[],
      session:bootstrapSnapshot.session || null,
      degraded_read:true,
    };
  }

  if (meta.kind === 'notifications') {
    const raw = String(document.getElementById('notificationsOpen')?.dataset.unread || '0');
    return { ok:true, items:[], unread_count:Math.max(0, Number.parseInt(raw, 10) || 0), degraded_read:true };
  }

  if (meta.kind === 'opponents') return { ok:true, items:[], degraded_read:true };
  if (meta.kind === 'shop_orders') return { ok:true, orders:[], degraded_read:true };
  return null;
}

function activeUserId(){
  return String(bootstrapSnapshot?.user?.id || bootstrapSnapshot?.user?.telegram_id || '').trim();
}

function cacheKey(userId, kind){
  return `${CACHE_PREFIX}${userId}:${kind}`;
}

function installScreenLifecycle(){
  document.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const target = origin.closest('button, [role="button"]');
    if (!target) return;

    if (target.id === 'newOpponent') {
      event.preventDefault();
      event.stopImmediatePropagation();
      startNewOpponentImmediately();
      return;
    }

    if (target.id === 'goHome') {
      event.preventDefault();
      event.stopImmediatePropagation();
      leaveFinishedGameImmediately();
    }
  }, true);

  const gameScreen = document.getElementById('screen-game');
  if (!gameScreen) return;

  const observer = new MutationObserver(() => {
    if (!gameScreen.classList.contains('active')) clearGameSurface();
  });
  observer.observe(gameScreen, { attributes:true, attributeFilter:['class'] });
}

async function startNewOpponentImmediately(){
  if (rematchBusy) return;
  const lastGame = state.activeGame;
  if (!lastGame) return leaveFinishedGameImmediately();

  rematchBusy = true;
  const room = lastGame.room === 'gold' ? 'gold' : 'match';
  const boardSize = Number(lastGame.board_size || state.selectedBoardSize || 3);
  const bet = room === 'match'
    ? APP_CONFIG.matchBet
    : Number(lastGame.bet || state.selectedBet || APP_CONFIG.matchBet);
  const gameType = gameTypeOf(lastGame);

  state.room = room;
  state.selectedBet = bet;
  state.selectedGame = gameType;
  rememberSelectedBoard(gameType, boardSize);
  state.activeGame = null;
  state.timers.game = clearTimer(state.timers.game);

  clearGameSurface();
  closeSheet();
  const info = document.getElementById('searchInfo');
  if (info) info.textContent = `${roomName(room)} · участие ${bet} коинов · поле ${boardSize}×${boardSize}`;
  showScreen('search');

  try {
    const result = await api.startSearch(room, bet, boardSize, gameType);
    if (result?.user) {
      state.user = result.user;
      state.session = result.session || state.session;
      renderBalances(state.user);
    }

    if (result?.game) {
      state.activeGame = result.game;
      state.selectedGame = gameTypeOf(result.game);
      clearGameSurface();
      showScreen('game');
      startGamePolling(result.game.id);
      return;
    }

    startSearchPolling();
  } catch (error) {
    state.timers.search = clearTimer(state.timers.search);
    showScreen('home');
    toast(friendlyError(error, 'Не удалось начать новый поиск. Попробуйте ещё раз.'));
  } finally {
    rematchBusy = false;
  }
}

function leaveFinishedGameImmediately(){
  state.timers.game = clearTimer(state.timers.game);
  state.timers.search = clearTimer(state.timers.search);
  state.activeGame = null;
  closeSheet();
  clearGameSurface();
  showScreen('home');
  document.dispatchEvent(new CustomEvent('mgw:game-dismissed'));
}

function rememberSelectedBoard(gameType, boardSize){
  if (gameType === 'tictactoe') state.selectedBoardSize = boardSize;
  else if (gameType === 'four_in_a_row') state.selectedFourBoardSize = boardSize;
  else if (gameType === 'reversi') state.selectedReversiBoardSize = boardSize;
  else if (gameType === 'go') state.selectedGoBoardSize = boardSize;
}

function clearGameSurface(){
  const board = document.getElementById('gameBoard');
  if (board) {
    board.replaceChildren();
    board.className = 'board size-3';
    delete board.dataset.gameType;
  }

  const players = document.getElementById('playersRow');
  if (players) players.replaceChildren();

  const turn = document.getElementById('turnText');
  if (turn) turn.textContent = 'Ожидаем начало матча';

  const timer = document.getElementById('timerText');
  if (timer) timer.textContent = '—';
}

function installTechnicalToastNormalizer(){
  const element = document.getElementById('toast');
  if (!element) return;

  const normalize = () => {
    const message = String(element.textContent || '').trim();
    if (message && TECHNICAL_ERROR_PATTERN.test(message)) {
      element.textContent = 'Не удалось связаться с сервером. Подключение восстановится автоматически.';
    }
  };

  const observer = new MutationObserver(normalize);
  observer.observe(element, { childList:true, characterData:true, subtree:true });
  normalize();
}

function friendlyError(error, fallback){
  const message = String(error?.message || '').trim();
  return message && !TECHNICAL_ERROR_PATTERN.test(message) ? message : fallback;
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

function jsonResponse(data){
  return new Response(JSON.stringify(data), {
    status:200,
    headers:{ 'Content-Type':'application/json; charset=utf-8' },
  });
}
