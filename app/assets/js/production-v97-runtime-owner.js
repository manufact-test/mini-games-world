import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { api } from './api/client.js?v=47';
import { openSheet, closeSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { showScreen } from './router.js?v=27';
import { clearTimer, renderBalances, roomName } from './ui.js?v=89';
import { haptic } from './telegram/telegram-app.js?v=27';
import {
  gameMetaText,
  gameStatusText,
  gameTypeOf,
  playerMarkText,
  renderGameSurface,
} from './games/game-router.js?v=74';
import { buildOptimisticGame as buildLegacyOptimistic } from './production-cross-game-optimistic.js?v=96';
import {
  buildTicTacToeOptimistic,
  gameSurfaceFingerprint,
  validateBattleshipPlacement,
} from './production-v97-models.js?v=97';

const rawBootstrap = api.bootstrap;
const rawStartSearch = api.startSearch;
const rawLeaveSearch = api.leaveSearch;
const rawGameState = api.gameState;
const rawGameAction = api.gameAction;
const rawNotifications = api.notifications;

const START_SEARCH_IDS = new Set([
  'startSearchBtn',
  'startFourSearchBtn',
  'startBattleshipSearchBtn',
  'startCheckersSearchBtn',
  'startReversiSearchBtn',
  'startChessSearchBtn',
  'startGoSearchBtn',
  'startDominoSearchBtn',
]);
const SESSION_LOCK_PATTERN = /(активная игра на другом устройстве|ищете матч на другом устройстве|игра уже открыта на другом устройстве)/iu;

const gameRuntime = new Map();
const gameStateInFlight = new Map();
let initialized = false;
let searchEpoch = 0;
let searchActive = false;
let searchPollBusy = false;
let boardInteractionHoldUntil = 0;
let latestNotifications = { loaded:false, items:[], unreadCount:0 };
let lastSessionLockToastAt = 0;

export function initProductionV97RuntimeOwner(){
  if (initialized) return;
  initialized = true;

  installApiOwner();
  installOwnedClicks();
  installBoardInteractionHold();

  window.setTimeout(installApiOwner, 0);
  window.setTimeout(installApiOwner, 120);
  document.addEventListener('mgw:app-ready', installApiOwner, { once:true });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') installApiOwner();
  });
}

function installApiOwner(){
  api.bootstrap = ownedBootstrap;
  api.startSearch = ownedStartSearch;
  api.leaveSearch = ownedLeaveSearch;
  api.gameState = ownedGameState;
  api.gameAction = ownedGameAction;
  api.notifications = ownedNotifications;
}

async function ownedBootstrap(){
  const result = await rawBootstrap();
  return gateSessionResult(result, { bootstrap:true });
}

async function ownedStartSearch(...args){
  const result = await rawStartSearch(...args);
  return rememberServerResult(gateSessionResult(result));
}

async function ownedLeaveSearch(){
  const result = await rawLeaveSearch();
  return gateSessionResult(result);
}

async function ownedNotifications(markRead = false){
  const result = await rawNotifications(Boolean(markRead));
  latestNotifications = {
    loaded:true,
    items:Array.isArray(result?.items) ? result.items : [],
    unreadCount:Math.max(0, Number(result?.unread_count || 0)),
  };
  return result;
}

async function ownedGameState(gameId = null){
  const key = String(gameId || state.activeGame?.id || 'search');
  if (gameStateInFlight.has(key)) return clone(await gameStateInFlight.get(key));

  const runtime = runtimeFor(key);
  const generation = runtime.generation;
  const task = (async () => {
    try {
      const raw = await rawGameState(gameId);
      if (key !== 'search') await waitForBoardInteractionWindow();
      const result = gateSessionResult(raw);
      if (result?.session?.locked) return result;

      if (runtime.generation !== generation || hasPendingActions(runtime)) {
        return runtime.optimisticGame
          ? responseSnapshot(runtime.optimisticGame, runtime.viewer, runtime, result)
          : result;
      }

      rememberServerResult(result);
      return result;
    } catch (error) {
      if (isSessionLockError(error)) {
        enforceSessionLock(String(error?.message || ''));
        return lockedResponse();
      }
      throw error;
    }
  })().finally(() => {
    if (gameStateInFlight.get(key) === task) gameStateInFlight.delete(key);
  });

  gameStateInFlight.set(key, task);
  return clone(await task);
}

function ownedGameAction(gameId, action){
  const game = state.activeGame;
  const key = String(gameId || game?.id || '');
  if (!key || !game || String(game.id || '') !== key) return rawGameAction(gameId, action);

  const runtime = runtimeFor(key);
  const viewer = runtime.viewer || resolveViewer(game);
  if (viewer) runtime.viewer = viewer;
  const viewerId = String(viewer?.id || '');
  const type = gameTypeOf(game);
  const base = runtime.optimisticGame || game;

  if (type === 'battleship' && !validateBattleshipPlacement(base, action)) {
    const error = new Error('Корабли нельзя ставить друг на друга или вплотную, даже по диагонали.');
    toast(error.message);
    return Promise.reject(error);
  }

  const optimistic = type === 'tictactoe'
    ? buildTicTacToeOptimistic(base, action, viewerId)
    : buildLegacyOptimistic(base, action, viewerId, type);

  if (optimistic) {
    runtime.optimisticGame = optimistic;
    state.activeGame = optimistic;
    runtime.generation++;
    renderGameSnapshot(optimistic, viewer, true);
  }

  const deferred = createDeferred(gameId, action);
  runtime.queue.push(deferred);
  drainActionQueue(key, runtime);
  return deferred.promise;
}

async function drainActionQueue(key, runtime){
  if (runtime.running) return;
  runtime.running = true;

  try {
    while (runtime.queue.length) {
      const item = runtime.queue[0];
      try {
        const raw = await rawGameAction(item.gameId, item.action);
        const result = gateSessionResult(raw);
        if (result?.session?.locked) throw new Error(result.session.message || 'Игра уже открыта на другом устройстве.');
        rememberServerResult(result, { preserveOptimistic:true });
        runtime.queue.shift();

        if (runtime.queue.length === 0) {
          const authoritative = result?.game || runtime.authoritativeGame;
          if (authoritative) {
            const currentFingerprint = gameSurfaceFingerprint(runtime.optimisticGame, runtime.viewer?.id);
            const serverFingerprint = gameSurfaceFingerprint(authoritative, runtime.viewer?.id || result?.me?.id);
            runtime.optimisticGame = clone(authoritative);
            state.activeGame = authoritative;
            if (currentFingerprint !== serverFingerprint) {
              renderGameSnapshot(authoritative, runtime.viewer || result?.me, false);
            } else {
              syncGameChrome(authoritative, runtime.viewer || result?.me, false);
            }
          }
          item.resolve(result);
        } else {
          item.resolve(responseSnapshot(runtime.optimisticGame, runtime.viewer, runtime, result));
        }
      } catch (error) {
        runtime.queue.shift();
        item.reject(error);
        while (runtime.queue.length) runtime.queue.shift().reject(error);
        restoreAuthoritative(runtime);
        if (isSessionLockError(error)) enforceSessionLock(String(error?.message || ''));
        break;
      }
    }
  } finally {
    runtime.running = false;
    markActionPending(false);
    runtime.generation++;
    if (runtime.queue.length) drainActionQueue(key, runtime);
  }
}

function installOwnedClicks(){
  document.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const button = origin.closest('button, [role="button"]');
    if (!(button instanceof Element)) return;

    if (button.id === 'notificationsOpen') {
      event.preventDefault();
      event.stopImmediatePropagation();
      openNotificationsOwned();
      return;
    }

    if (button.id === 'cancelSearch' || button.id === 'changeSearch') {
      event.preventDefault();
      event.stopImmediatePropagation();
      cancelOwnedSearch();
      return;
    }

    if (button instanceof HTMLButtonElement && START_SEARCH_IDS.has(button.id) && !button.disabled) {
      event.preventDefault();
      event.stopImmediatePropagation();
      startOwnedSearch(button.id);
      return;
    }

    const cell = origin.closest('[data-game-cell]');
    if (!(cell instanceof HTMLButtonElement)) return;
    const game = state.activeGame;
    if (gameTypeOf(game || {}) !== 'tictactoe') return;

    event.preventDefault();
    event.stopImmediatePropagation();
    if (cell.disabled || cell.classList.contains('locked')) return;
    const index = Number(cell.dataset.gameCell);
    if (!Number.isInteger(index)) return;
    haptic('light');
    api.gameAction(game.id, { type:'cell', cell:index }).catch(error => {
      toast(error?.message || 'Не удалось выполнить ход.');
    });
  }, true);
}

function installBoardInteractionHold(){
  document.addEventListener('pointerdown', event => {
    const origin = event.target;
    if (!(origin instanceof Element) || !origin.closest('#gameBoard button')) return;
    boardInteractionHoldUntil = Date.now() + 700;
  }, true);
  const release = event => {
    const origin = event.target;
    if (!(origin instanceof Element) || !origin.closest('#gameBoard')) return;
    boardInteractionHoldUntil = Date.now() + 140;
  };
  document.addEventListener('pointerup', release, true);
  document.addEventListener('pointercancel', release, true);
  document.addEventListener('click', event => {
    const origin = event.target;
    if (origin instanceof Element && origin.closest('#gameBoard')) {
      boardInteractionHoldUntil = Date.now() + 40;
    }
  }, true);
}

async function waitForBoardInteractionWindow(){
  const delay = boardInteractionHoldUntil - Date.now();
  if (delay > 0) await new Promise(resolve => window.setTimeout(resolve, delay));
}

async function startOwnedSearch(buttonId){
  if (state.session?.locked) {
    enforceSessionLock(state.session.message || 'Игра уже открыта на другом устройстве.');
    return;
  }

  const context = searchContext(buttonId);
  const epoch = ++searchEpoch;
  searchActive = true;
  searchPollBusy = false;
  clearSearchRuntime();
  state.activeGame = null;
  state.selectedGame = context.gameType;
  state.room = context.room;
  state.selectedBet = context.bet;
  rememberBoardSelection(context.gameType, context.size);
  clearGameSurface();
  closeSheet();
  const info = document.getElementById('searchInfo');
  if (info) info.textContent = context.label;
  showScreen('search');
  haptic('light');

  try {
    const result = await rawStartSearch(context.room, context.bet, context.size, context.gameType);
    if (epoch !== searchEpoch || !searchActive) {
      rawLeaveSearch().catch(() => null);
      return;
    }
    const gated = rememberServerResult(gateSessionResult(result));
    if (gated?.session?.locked) return;
    if (gated?.game?.status === 'active') {
      openFoundGame(gated.game, gated.me);
      return;
    }
    state.timers.search = window.setInterval(() => pollOwnedSearch(epoch), APP_CONFIG.searchIntervalMs);
    pollOwnedSearch(epoch);
  } catch (error) {
    if (epoch !== searchEpoch) return;
    if (isSessionLockError(error)) {
      enforceSessionLock(String(error?.message || ''));
      return;
    }
    searchActive = false;
    clearSearchRuntime();
    showScreen('home');
    toast(error?.message || 'Не удалось начать поиск.');
  }
}

async function pollOwnedSearch(epoch){
  if (!searchActive || epoch !== searchEpoch || searchPollBusy) return;
  searchPollBusy = true;
  try {
    const result = gateSessionResult(await rawGameState());
    if (!searchActive || epoch !== searchEpoch || result?.session?.locked) return;
    rememberUserAndSession(result);
    if (result?.game?.status === 'active') {
      rememberServerResult(result);
      openFoundGame(result.game, result.me);
      return;
    }
    if (result?.user && result.user.status !== 'searching') {
      searchActive = false;
      clearSearchRuntime();
      showScreen('home');
      toast('Поиск остановлен.');
    }
  } catch (error) {
    if (isSessionLockError(error)) enforceSessionLock(String(error?.message || ''));
  } finally {
    searchPollBusy = false;
  }
}

function cancelOwnedSearch(){
  ++searchEpoch;
  searchActive = false;
  clearSearchRuntime();
  state.activeGame = null;
  clearGameSurface();
  closeSheet();
  showScreen('home');
  toast('Поиск отменён.');
  haptic('light');
  rawLeaveSearch().then(rememberUserAndSession).catch(() => null);
}

function openFoundGame(game, me = null){
  searchActive = false;
  clearSearchRuntime();
  state.activeGame = game;
  state.selectedGame = gameTypeOf(game);
  const runtime = runtimeFor(String(game.id || ''));
  runtime.viewer = normalizeViewer(me) || runtime.viewer || resolveViewer(game);
  runtime.authoritativeGame = clone(game);
  runtime.optimisticGame = clone(game);
  showScreen('game');
  document.dispatchEvent(new CustomEvent('mgw:v97-game-found', { detail:{ gameId:String(game.id || '') } }));
  window.setTimeout(() => {
    installApiOwner();
    const start = window.__MGW_V97_START_GAME_POLLING__;
    if (typeof start === 'function') start(game.id);
  }, 0);
}

function clearSearchRuntime(){
  state.timers.search = clearTimer(state.timers.search);
  const legacy = window.__MGW_SEARCH_SCREEN_RUNTIME__;
  if (legacy?.emptyRoomBotCheckTimer !== null && legacy?.emptyRoomBotCheckTimer !== undefined) {
    window.clearTimeout(legacy.emptyRoomBotCheckTimer);
    legacy.emptyRoomBotCheckTimer = null;
  }
}

function searchContext(buttonId){
  const room = state.room === 'gold' ? 'gold' : 'match';
  const bet = room === 'match' ? APP_CONFIG.matchBet : Number(state.selectedBet || APP_CONFIG.goldBets[0]);
  const contexts = {
    startSearchBtn:{ gameType:'tictactoe', size:Number(state.selectedBoardSize || 3), title:'Крестики-нолики' },
    startFourSearchBtn:{ gameType:'four_in_a_row', size:Number(state.selectedFourBoardSize || 7), title:'4 в ряд' },
    startBattleshipSearchBtn:{ gameType:'battleship', size:10, title:'Морской бой' },
    startCheckersSearchBtn:{ gameType:'checkers', size:8, title:'Шашки' },
    startReversiSearchBtn:{ gameType:'reversi', size:Number(state.selectedReversiBoardSize || 8), title:'Реверси' },
    startChessSearchBtn:{ gameType:'chess', size:8, title:'Шахматы' },
    startGoSearchBtn:{ gameType:'go', size:Number(state.selectedGoBoardSize || 9), title:'Го' },
    startDominoSearchBtn:{ gameType:'domino', size:7, title:'Домино' },
  };
  const selected = contexts[buttonId] || contexts.startSearchBtn;
  return {
    ...selected,
    room,
    bet,
    label:`${selected.title} · ${roomName(room)} · участие ${bet} коинов${selected.gameType === 'domino' ? '' : ` · поле ${selected.size}×${selected.size}`}`,
  };
}

function rememberBoardSelection(type, size){
  if (type === 'tictactoe') state.selectedBoardSize = size;
  else if (type === 'four_in_a_row') state.selectedFourBoardSize = size;
  else if (type === 'reversi') state.selectedReversiBoardSize = size;
  else if (type === 'go') state.selectedGoBoardSize = size;
}

async function openNotificationsOwned(){
  haptic('light');
  const hasUsefulSnapshot = latestNotifications.loaded
    && (latestNotifications.items.length > 0 || latestNotifications.unreadCount === 0);
  if (hasUsefulSnapshot) renderNotifications(latestNotifications.items);
  else renderNotificationsLoading();

  try {
    const result = await ownedNotifications(true);
    renderNotifications(result.items || []);
    setUnreadCount(0);
  } catch (error) {
    if (hasUsefulSnapshot) renderNotifications(latestNotifications.items);
    else renderNotificationsError();
  }
}

function renderNotificationsLoading(){
  openSheet(`<div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="notifications-loading"><div>🔔</div><strong>Загружаем…</strong></div>`);
}

function renderNotifications(items){
  const body = items.length
    ? `<div class="notifications-list">${items.map(renderNotificationItem).join('')}</div>`
    : `<div class="notifications-empty"><div>🔔</div><strong>Пока уведомлений нет</strong></div>`;
  openSheet(`<div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div>${body}`);
}

function renderNotificationItem(item){
  const tone = ['success','danger','info','warning'].includes(String(item?.tone || '')) ? String(item.tone) : 'info';
  const actions = Array.isArray(item?.actions) ? item.actions : [];
  const token = String(item?.invite_token || '');
  const buttons = token && actions.length
    ? `<div class="notification-actions invite-actions">${actions.map(action => `<button class="btn ${['accept','start'].includes(action) ? 'primary' : 'ghost'} full" data-invite-action="${escapeHtml(action)}" data-invite-token="${escapeHtml(token)}" type="button">${escapeHtml(notificationActionLabel(action))}</button>`).join('')}</div>`
    : '';
  return `<article class="notification-card ${tone}"><div class="notification-icon">${notificationIcon(tone, item?.type)}</div><div class="notification-copy"><div class="notification-head"><strong>${escapeHtml(item?.title || 'Уведомление')}</strong><span>${escapeHtml(formatDate(item?.created_at))}</span></div>${item?.message ? `<p>${escapeHtml(item.message)}</p>` : ''}${buttons}</div></article>`;
}

function renderNotificationsError(){
  openSheet(`<div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="notifications-empty error"><div>⚠️</div><strong>Не удалось открыть уведомления</strong><span>Попробуйте ещё раз.</span></div>`);
}

function notificationActionLabel(action){
  return { accept:'Принять приглашение', decline:'Отклонить', start:'Начать игру', cancel:'Отменить' }[String(action || '')] || 'Открыть';
}

function notificationIcon(tone, type){
  if (String(type || '').startsWith('invite_')) return '🎮';
  if (tone === 'success') return '✓';
  if (tone === 'danger' || tone === 'warning') return '!';
  return 'i';
}

function setUnreadCount(count){
  const button = document.getElementById('notificationsOpen');
  if (!button) return;
  const safe = Math.max(0, Math.trunc(Number(count || 0)));
  button.dataset.unread = safe > 99 ? '99+' : String(safe);
  button.classList.toggle('has-unread', safe > 0);
  button.setAttribute('aria-label', safe > 0 ? `Уведомления: ${safe} новых` : 'Уведомления');
}

function gateSessionResult(result, options = {}){
  if (!result?.session?.locked) return result;
  const message = String(result.session.message || 'Игра уже открыта на другом устройстве.');
  enforceSessionLock(message);
  return {
    ...result,
    game:null,
    active_game:null,
    ...(options.bootstrap ? { active_game:null } : {}),
  };
}

function enforceSessionLock(message){
  const safeMessage = message || 'Игра уже открыта на другом устройстве.';
  ++searchEpoch;
  searchActive = false;
  clearSearchRuntime();
  state.timers.game = clearTimer(state.timers.game);
  state.activeGame = null;
  state.session = { ...(state.session || {}), locked:true, message:safeMessage };
  clearGameSurface();
  closeSheet();
  showScreen('home');
  const now = Date.now();
  if (now - lastSessionLockToastAt > 1800) {
    lastSessionLockToastAt = now;
    toast(safeMessage);
  }
}

function lockedResponse(){
  return {
    ok:true,
    user:state.user,
    session:state.session || { locked:true, message:'Игра уже открыта на другом устройстве.' },
    me:null,
    game:null,
    active_game:null,
  };
}

function isSessionLockError(error){
  return SESSION_LOCK_PATTERN.test(String(error?.message || ''));
}

function rememberServerResult(result, options = {}){
  rememberUserAndSession(result);
  const gameId = String(result?.game?.id || '');
  if (!gameId) return result;
  const runtime = runtimeFor(gameId);
  runtime.viewer = normalizeViewer(result?.me) || runtime.viewer || resolveViewer(result.game);
  runtime.authoritativeGame = clone(result.game);
  runtime.lastUser = result?.user || runtime.lastUser || state.user;
  runtime.lastSession = result?.session || runtime.lastSession || state.session;
  if (!options.preserveOptimistic && !hasPendingActions(runtime)) {
    runtime.optimisticGame = clone(result.game);
    state.activeGame = result.game;
  }
  return result;
}

function rememberUserAndSession(result){
  if (result?.user) {
    state.user = result.user;
    renderBalances(state.user);
  }
  if (result?.session) state.session = result.session;
  return result;
}

function runtimeFor(gameId){
  const key = String(gameId || '');
  if (!gameRuntime.has(key)) {
    gameRuntime.set(key, {
      viewer:null,
      authoritativeGame:null,
      optimisticGame:null,
      queue:[],
      running:false,
      generation:0,
      lastUser:null,
      lastSession:null,
    });
  }
  return gameRuntime.get(key);
}

function hasPendingActions(runtime){
  return Boolean(runtime?.running || runtime?.queue?.length);
}

function createDeferred(gameId, action){
  let resolve;
  let reject;
  const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
  return { gameId, action, promise, resolve, reject };
}

function responseSnapshot(game, viewer, runtime, source = null){
  return {
    ...(source && typeof source === 'object' ? source : {}),
    ok:true,
    user:source?.user || runtime?.lastUser || state.user,
    session:source?.session || runtime?.lastSession || state.session,
    me:viewer || source?.me || null,
    game,
  };
}

function restoreAuthoritative(runtime){
  if (!runtime.authoritativeGame) return;
  runtime.optimisticGame = clone(runtime.authoritativeGame);
  state.activeGame = runtime.authoritativeGame;
  renderGameSnapshot(runtime.authoritativeGame, runtime.viewer, false);
}

function renderGameSnapshot(game, me, pending){
  if (!game || !me?.id) return;
  syncGameChrome(game, me, pending);
  const surface = document.getElementById('gameBoard');
  if (!surface) return;
  renderGameSurface({
    game,
    me,
    container:surface,
    onAction:action => {
      haptic('light');
      api.gameAction(game.id, action).catch(error => toast(error?.message || 'Не удалось выполнить ход.'));
    },
  });
  surface.dataset.mgwV97Fingerprint = gameSurfaceFingerprint(game, me.id);
  markActionPending(Boolean(pending && !canContinueImmediately(game, me)));
}

function syncGameChrome(game, me, pending){
  const type = gameTypeOf(game);
  const screen = document.getElementById('screen-game');
  const meta = document.getElementById('matchMeta');
  const turn = document.getElementById('turnText');
  const timer = document.getElementById('timerText');
  const players = document.getElementById('playersRow');
  if (screen) {
    screen.dataset.gameType = type;
    screen.dataset.gamePhase = String(game?.phase || '');
  }
  if (meta) meta.textContent = gameMetaText(game);
  if (turn) turn.textContent = pending ? pendingStatus(game, me) : gameStatusText(game, me);
  if (timer) timer.textContent = game.status === 'active' ? `${game.time_left ?? 60} сек` : '—';
  if (players) {
    players.innerHTML = (game.players || []).map(player => `<div class="game-player ${String(game.turn) === String(player.id) && game.status === 'active' ? 'active' : ''}"><div class="name">${escapeHtml(player.name)}</div><div class="mark">${escapeHtml(playerMarkText(game, player))} · ${String(player.id) === String(me.id) ? 'вы' : 'соперник'}</div></div>`).join('');
  }
}

function canContinueImmediately(game, me){
  return gameTypeOf(game) === 'checkers'
    && String(game?.turn || '') === String(me?.id || '')
    && game?.forced_piece !== null
    && game?.forced_piece !== undefined
    && Array.isArray(game?.legal_moves)
    && game.legal_moves.length > 0;
}

function pendingStatus(game, me){
  if (canContinueImmediately(game, me)) return 'Продолжайте взятие';
  if (gameTypeOf(game) === 'battleship' && Number.isInteger(Number(game?.pending_fire_cell))) return 'Выстрел отправлен…';
  return 'Ход принят…';
}

function markActionPending(pending){
  document.getElementById('gameBoard')?.classList.toggle('mgw-action-pending', Boolean(pending));
}

function resolveViewer(game){
  const players = Array.isArray(game?.players) ? game.players : [];
  const explicit = players.find(player => player?.is_me === true || player?.viewer === true);
  if (explicit?.id !== undefined) return { ...explicit, id:String(explicit.id) };
  for (const candidate of [state.user?.id, state.user?.telegram_id, state.user?.mgw_id].map(value => String(value || '')).filter(Boolean)) {
    const player = players.find(item => String(item?.id || '') === candidate);
    if (player) return { ...player, id:candidate };
  }
  const side = String(game?.viewer_side || '');
  const sideMatches = side ? players.filter(player => String(player?.side || '') === side) : [];
  return sideMatches.length === 1 ? { ...sideMatches[0], id:String(sideMatches[0].id || '') } : null;
}

function normalizeViewer(viewer){
  const id = String(viewer?.id || '');
  return id ? { ...viewer, id } : null;
}

function clearGameSurface(){
  const board = document.getElementById('gameBoard');
  if (board) {
    board.replaceChildren();
    board.className = 'board size-3';
    delete board.dataset.gameType;
    delete board.dataset.mgwV97Fingerprint;
  }
  document.getElementById('playersRow')?.replaceChildren();
  const turn = document.getElementById('turnText');
  if (turn) turn.textContent = 'Ожидаем начало матча';
  const timer = document.getElementById('timerText');
  if (timer) timer.textContent = '—';
}

function formatDate(value){
  const date = new Date(value || '');
  if (Number.isNaN(date.getTime())) return '';
  return new Intl.DateTimeFormat('ru-RU', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' }).format(date);
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function clone(value){
  if (value === undefined) return undefined;
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}
