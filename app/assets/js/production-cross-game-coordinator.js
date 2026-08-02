import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { api } from './api/client.js?v=47';
import { closeSheet } from './components/sheet.js?v=68';
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
import { buildOptimisticGame } from './production-cross-game-optimistic.js?v=96';

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

const originalStartSearch = api.startSearch;
const originalGameState = api.gameState;
const originalGameAction = api.gameAction;
const runtimeByGame = new Map();
const initialSnapshotServed = new Set();
let initialized = false;

export function initCrossGameCoordinator(){
  if (initialized) return;
  initialized = true;

  installImmediateSearchTransitions();
  installPlayerPickerTransitionGuard();
  installGameApiCoordinator();
}

export function scheduleCrossGameCoordinatorAfterMain(){
  /* main.js installs an older serialized game-state wrapper after this early entry.
   * Reclaim the shared API once main initialization has completed, and again at
   * app-ready before an active match or first user action can start. */
  window.setTimeout(installGameApiCoordinator, 0);
  document.addEventListener('mgw:app-ready', installGameApiCoordinator, { once:true });
}

function installImmediateSearchTransitions(){
  document.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const button = origin.closest('button');
    if (!(button instanceof HTMLButtonElement) || !START_SEARCH_IDS.has(button.id) || button.disabled) return;

    const context = searchContextFor(button.id);
    state.selectedGame = context.gameType;
    state.activeGame = null;
    state.timers.game = clearTimer(state.timers.game);
    clearGameSurface();
    closeSheet();

    const info = document.getElementById('searchInfo');
    if (info) info.textContent = context.label;
    showScreen('search');
  }, true);
}

function searchContextFor(buttonId){
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
    label:`${selected.title} · ${roomName(room)} · участие ${bet} коинов${selected.gameType === 'domino' ? '' : ` · поле ${selected.size}×${selected.size}`}`,
  };
}

function installPlayerPickerTransitionGuard(){
  const sheet = document.getElementById('sheet');
  const overlay = document.getElementById('sheetOverlay');
  if (!sheet || !overlay) return;

  let timeout = null;
  let hold = null;
  const finish = () => {
    document.body.classList.remove('mgw-player-picker-transition');
    hold?.remove();
    hold = null;
    window.clearTimeout(timeout);
    timeout = null;
  };

  document.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const button = origin.closest('[data-open-player-picker]');
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;

    finish();
    document.body.classList.add('mgw-player-picker-transition');
    button.setAttribute('aria-busy', 'true');
    const previousText = button.textContent;
    button.textContent = 'Открываем игроков…';

    hold = document.createElement('div');
    hold.className = 'sheet mgw-player-picker-hold';
    hold.setAttribute('aria-hidden', 'true');
    hold.setAttribute('inert', '');
    hold.innerHTML = sheet.innerHTML;
    hold.querySelectorAll('[id]').forEach(node => node.removeAttribute('id'));
    hold.querySelectorAll('button,input,textarea,select,a').forEach(node => {
      node.setAttribute('tabindex', '-1');
      node.setAttribute('aria-hidden', 'true');
      if ('disabled' in node) node.disabled = true;
    });
    overlay.append(hold);

    timeout = window.setTimeout(() => {
      finish();
      if (document.body.contains(button)) {
        button.removeAttribute('aria-busy');
        button.textContent = previousText;
      }
    }, 1800);
  }, true);

  const observer = new MutationObserver(() => {
    if (!document.body.classList.contains('mgw-player-picker-transition')) return;
    const ready = Boolean(
      sheet.querySelector('.invite-player-list')
      || sheet.querySelector('.invite-empty-state')
      || sheet.querySelector('[data-back-to-invite-setup]')
    );
    if (ready) finish();
  });
  observer.observe(sheet, { childList:true, subtree:true });
}

function installGameApiCoordinator(){
  api.startSearch = coordinatedStartSearch;
  api.gameState = coordinatedGameState;
  api.gameAction = coordinatedGameAction;
}

async function coordinatedStartSearch(...args){
  const result = await originalStartSearch(...args);
  rememberAuthoritativeResult(result);
  return result;
}

async function coordinatedGameState(gameId = null){
  const key = String(gameId || state.activeGame?.id || '');
  const runtime = key ? runtimeByGame.get(key) : null;

  if (runtime && hasPendingActions(runtime) && runtime.optimisticGame) {
    return responseSnapshot(runtime.optimisticGame, runtime.viewer, runtime);
  }

  const local = localGameSnapshot(key);
  if (local && !initialSnapshotServed.has(key)) {
    initialSnapshotServed.add(key);
    queueMicrotask(() => {
      originalGameState(gameId)
        .then(rememberAuthoritativeResult)
        .catch(() => null);
    });
    return local;
  }

  const result = await originalGameState(gameId);
  rememberAuthoritativeResult(result);
  return result;
}

function coordinatedGameAction(gameId, gameAction){
  const game = state.activeGame;
  const key = String(gameId || game?.id || '');
  const type = gameTypeOf(game || {});
  if (!key || !game || String(game?.id || '') !== key || type === 'tictactoe') {
    return originalGameAction(gameId, gameAction);
  }

  const runtime = runtimeFor(key);
  const viewer = runtime.viewer || resolveViewer(game);
  if (viewer) runtime.viewer = viewer;
  const viewerId = String(viewer?.id || '');
  const baseGame = runtime.optimisticGame || game;
  const optimistic = buildOptimisticGame(baseGame, gameAction, viewerId, type);

  if (optimistic) {
    runtime.optimisticGame = optimistic;
    state.activeGame = optimistic;
    renderGameSnapshot(optimistic, viewer, true);
  } else if (!hasPendingActions(runtime)) {
    markGamePending(true);
  }

  const item = deferredAction(gameId, gameAction, runtime);
  runtime.queue.push(item);
  drainActionQueue(key, runtime);
  return item.promise;
}

async function drainActionQueue(key, runtime){
  if (runtime.running) return;
  runtime.running = true;

  try {
    while (runtime.queue.length) {
      const item = runtime.queue[0];
      try {
        const result = await originalGameAction(item.gameId, item.action);
        rememberAuthoritativeResult(result, { preserveOptimistic:true });
        runtime.queue.shift();

        if (runtime.queue.length === 0) {
          const authoritativeGame = result?.game || runtime.authoritativeGame;
          if (authoritativeGame) {
            runtime.optimisticGame = clone(authoritativeGame);
            state.activeGame = authoritativeGame;
            renderGameSnapshot(authoritativeGame, runtime.viewer || result?.me, false);
          }
          item.resolve(result);
        } else {
          /* The server accepted this step, but a later chained action is already
           * visible locally. Return the newest local snapshot so the older caller
           * cannot repaint over it while the queue continues. */
          item.resolve(responseSnapshot(runtime.optimisticGame, runtime.viewer, runtime, result));
        }
      } catch (error) {
        runtime.queue.shift();
        item.reject(error);
        rejectQueuedActions(runtime, error);
        restoreAuthoritativeGame(runtime);
        break;
      }
    }
  } finally {
    runtime.running = false;
    markGamePending(false);
    if (runtime.queue.length) drainActionQueue(key, runtime);
  }
}

function deferredAction(gameId, action, runtime){
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { gameId, action, runtime, promise, resolve, reject };
}

function rejectQueuedActions(runtime, cause){
  const error = new Error('Предыдущий ход не был принят сервером. Поле восстановлено.');
  error.cause = cause;
  while (runtime.queue.length) {
    runtime.queue.shift().reject(error);
  }
}

function restoreAuthoritativeGame(runtime){
  const fallback = runtime.authoritativeGame;
  if (!fallback) return;
  runtime.optimisticGame = clone(fallback);
  state.activeGame = fallback;
  renderGameSnapshot(fallback, runtime.viewer, false);
}

function hasPendingActions(runtime){
  return Boolean(runtime?.running || runtime?.queue?.length);
}

function localGameSnapshot(gameId){
  const game = state.activeGame;
  if (!game?.id || String(game.id) !== String(gameId || '')) return null;
  if (String(game.status || '') !== 'active') return null;

  const runtime = runtimeFor(String(game.id));
  const viewer = runtime.viewer || resolveViewer(game);
  if (!viewer?.id) return null;
  runtime.viewer = viewer;
  runtime.optimisticGame ||= clone(game);
  runtime.authoritativeGame ||= clone(game);
  return responseSnapshot(game, viewer, runtime);
}

function rememberAuthoritativeResult(result, options = {}){
  const gameId = String(result?.game?.id || '');
  rememberUserAndSession(result);
  if (!gameId) return result;

  const runtime = runtimeFor(gameId);
  const viewer = normalizeViewer(result?.me) || runtime.viewer || resolveViewer(result.game);
  if (viewer) runtime.viewer = viewer;
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
}

function runtimeFor(gameId){
  const key = String(gameId || '');
  if (!runtimeByGame.has(key)) {
    runtimeByGame.set(key, {
      viewer:null,
      authoritativeGame:null,
      optimisticGame:null,
      queue:[],
      running:false,
      lastUser:null,
      lastSession:null,
    });
  }
  return runtimeByGame.get(key);
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

function resolveViewer(game){
  const players = Array.isArray(game?.players) ? game.players : [];
  const explicit = players.find(player => player?.is_me === true || player?.viewer === true);
  if (explicit?.id !== undefined) return { ...explicit, id:String(explicit.id) };

  const candidates = [
    state.user?.id,
    state.user?.telegram_id,
    state.user?.mgw_id,
  ].map(value => String(value || '')).filter(Boolean);

  for (const candidate of candidates) {
    const player = players.find(item => String(item?.id || '') === candidate);
    if (player) return { ...player, id:candidate };
  }

  const viewerSide = String(game?.viewer_side || '');
  if (viewerSide) {
    const sideMatches = players.filter(player => String(player?.side || '') === viewerSide);
    if (sideMatches.length === 1) return { ...sideMatches[0], id:String(sideMatches[0].id || '') };
  }

  return null;
}

function normalizeViewer(viewer){
  const id = String(viewer?.id || '');
  return id ? { ...viewer, id } : null;
}

function renderGameSnapshot(game, me, pending){
  const screen = document.getElementById('screen-game');
  const meta = document.getElementById('matchMeta');
  const turn = document.getElementById('turnText');
  const timer = document.getElementById('timerText');
  const players = document.getElementById('playersRow');
  const surface = document.getElementById('gameBoard');
  if (!screen || !meta || !turn || !timer || !players || !surface || !me?.id) return;

  const type = gameTypeOf(game);
  screen.dataset.gameType = type;
  screen.dataset.gamePhase = String(game?.phase || '');
  meta.textContent = gameMetaText(game);
  turn.textContent = pending ? pendingStatus(type, game, me) : gameStatusText(game, me);
  timer.textContent = game.status === 'active' ? `${game.time_left ?? 60} сек` : '—';
  players.innerHTML = (game.players || []).map(player => `
    <div class="game-player ${String(game.turn) === String(player.id) && game.status === 'active' ? 'active' : ''}">
      <div class="name">${escapeHtml(player.name)}</div>
      <div class="mark">${escapeHtml(playerMarkText(game, player))} · ${String(player.id) === String(me.id) ? 'вы' : 'соперник'}</div>
    </div>
  `).join('');

  renderGameSurface({
    game,
    me,
    container:surface,
    onAction:nextAction => submitRenderedAction(game.id, nextAction),
  });

  if (type === 'battleship' && Number.isInteger(Number(game?.pending_fire_cell))) {
    surface.querySelector(`[data-battleship-cell="${Number(game.pending_fire_cell)}"]`)?.classList.add('mgw-pending-shot');
  }

  markGamePending(Boolean(pending && !canContinueImmediately(type, game, me)));
}

function submitRenderedAction(gameId, action){
  haptic('light');
  api.gameAction(gameId, action).catch(error => {
    toast(error?.message || 'Не удалось выполнить ход. Поле восстановлено.');
  });
}

function canContinueImmediately(type, game, me){
  return type === 'checkers'
    && String(game?.turn || '') === String(me?.id || '')
    && game?.forced_piece !== null
    && game?.forced_piece !== undefined
    && Array.isArray(game?.legal_moves)
    && game.legal_moves.length > 0;
}

function pendingStatus(type, game, me){
  if (canContinueImmediately(type, game, me)) return 'Продолжайте взятие';
  if (type === 'battleship' && Number.isInteger(Number(game?.pending_fire_cell))) return 'Выстрел отправлен…';
  if (type === 'domino' && String(game?.last_action?.type || '') === 'draw') return 'Добираем костяшку…';
  return 'Ход принят…';
}

function markGamePending(pending){
  const board = document.getElementById('gameBoard');
  if (!board) return;
  board.classList.toggle('mgw-action-pending', Boolean(pending));
}

function clearGameSurface(){
  const board = document.getElementById('gameBoard');
  if (board) {
    board.replaceChildren();
    board.className = 'board size-3';
    delete board.dataset.gameType;
  }
  document.getElementById('playersRow')?.replaceChildren();
  const turn = document.getElementById('turnText');
  if (turn) turn.textContent = 'Ожидаем начало матча';
  const timer = document.getElementById('timerText');
  if (timer) timer.textContent = '—';
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
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}
