import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { api } from './api/client.js?v=47';
import { closeSheet } from './components/sheet.js?v=68';
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
import { buildOptimisticGame } from './production-cross-game-optimistic.js?v=95';

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

const originalGameState = api.gameState;
const originalGameAction = api.gameAction;
const pendingActionByGame = new Map();
const latestSnapshotByGame = new Map();
const initialSnapshotServed = new Set();

let initialized = false;

export function initCrossGameCoordinator(){
  if (initialized) return;
  initialized = true;

  installImmediateSearchTransitions();
  installPlayerPickerTransitionGuard();
  installGameApiCoordinator();
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
    startSearchBtn:{
      gameType:'tictactoe',
      size:Number(state.selectedBoardSize || 3),
      title:'Крестики-нолики',
    },
    startFourSearchBtn:{
      gameType:'four_in_a_row',
      size:Number(state.selectedFourBoardSize || 7),
      title:'4 в ряд',
    },
    startBattleshipSearchBtn:{ gameType:'battleship', size:10, title:'Морской бой' },
    startCheckersSearchBtn:{ gameType:'checkers', size:8, title:'Шашки' },
    startReversiSearchBtn:{
      gameType:'reversi',
      size:Number(state.selectedReversiBoardSize || 8),
      title:'Реверси',
    },
    startChessSearchBtn:{ gameType:'chess', size:8, title:'Шахматы' },
    startGoSearchBtn:{
      gameType:'go',
      size:Number(state.selectedGoBoardSize || 9),
      title:'Го',
    },
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
  if (!sheet) return;

  let timeout = null;
  const finish = () => {
    document.body.classList.remove('mgw-player-picker-transition');
    window.clearTimeout(timeout);
    timeout = null;
  };

  document.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const button = origin.closest('[data-open-player-picker]');
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;

    document.body.classList.add('mgw-player-picker-transition');
    button.setAttribute('aria-busy', 'true');
    const previousText = button.textContent;
    button.textContent = 'Открываем игроков…';

    window.clearTimeout(timeout);
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
  api.gameState = coordinatedGameState;
  api.gameAction = coordinatedGameAction;
}

async function coordinatedGameState(gameId = null){
  const key = String(gameId || state.activeGame?.id || '');
  const pending = key ? pendingActionByGame.get(key) : null;
  if (pending) {
    const snapshot = latestSnapshotByGame.get(key);
    if (snapshot) return snapshot;
  }

  const local = localGameSnapshot(key);
  if (local && !initialSnapshotServed.has(key)) {
    initialSnapshotServed.add(key);
    queueMicrotask(() => originalGameState(gameId).then(rememberAuthoritativeSnapshot).catch(() => null));
    return local;
  }

  const result = await originalGameState(gameId);
  rememberAuthoritativeSnapshot(result);
  return result;
}

async function coordinatedGameAction(gameId, gameAction){
  const game = state.activeGame;
  const key = String(gameId || game?.id || '');
  if (!key || !game || String(game?.id || '') !== key || gameTypeOf(game) === 'tictactoe') {
    return originalGameAction(gameId, gameAction);
  }

  const existing = pendingActionByGame.get(key);
  if (existing) return existing;

  const viewer = { id:String(state.user?.id || '') };
  const previous = clone(game);
  const type = gameTypeOf(previous);
  const optimistic = buildOptimisticGame(previous, gameAction, viewer.id, type);
  if (optimistic) {
    state.activeGame = optimistic;
    const snapshot = responseSnapshot(optimistic, viewer);
    latestSnapshotByGame.set(key, snapshot);
    haptic('light');
    renderGameSnapshot(optimistic, viewer, true);
  } else {
    markGamePending(true);
  }

  const task = originalGameAction(gameId, gameAction)
    .then(result => {
      rememberAuthoritativeSnapshot(result);
      if (result?.game) renderGameSnapshot(result.game, result.me || viewer, false);
      return result;
    })
    .catch(error => {
      state.activeGame = previous;
      const restored = responseSnapshot(previous, viewer);
      latestSnapshotByGame.set(key, restored);
      renderGameSnapshot(previous, viewer, false);
      throw error;
    })
    .finally(() => {
      if (pendingActionByGame.get(key) === task) pendingActionByGame.delete(key);
      markGamePending(false);
    });

  pendingActionByGame.set(key, task);
  return task;
}

function localGameSnapshot(gameId){
  const game = state.activeGame;
  if (!game?.id || String(game.id) !== String(gameId || '')) return null;
  if (String(game.status || '') !== 'active') return null;
  return responseSnapshot(game, { id:String(state.user?.id || '') });
}

function responseSnapshot(game, me){
  return {
    ok:true,
    user:state.user,
    session:state.session,
    me,
    game,
  };
}

function rememberAuthoritativeSnapshot(result){
  const gameId = String(result?.game?.id || '');
  if (!gameId) return result;
  latestSnapshotByGame.set(gameId, result);
  if (result?.game) state.activeGame = result.game;
  if (result?.user) {
    state.user = result.user;
    state.session = result.session || state.session;
    renderBalances(state.user);
  }
  return result;
}

function renderGameSnapshot(game, me, pending){
  const screen = document.getElementById('screen-game');
  const meta = document.getElementById('matchMeta');
  const turn = document.getElementById('turnText');
  const timer = document.getElementById('timerText');
  const players = document.getElementById('playersRow');
  const surface = document.getElementById('gameBoard');
  if (!screen || !meta || !turn || !timer || !players || !surface) return;

  const type = gameTypeOf(game);
  screen.dataset.gameType = type;
  screen.dataset.gamePhase = String(game?.phase || '');
  meta.textContent = gameMetaText(game);
  turn.textContent = pending ? pendingStatus(type, game) : gameStatusText(game, me);
  timer.textContent = game.status === 'active' ? `${game.time_left ?? 60} сек` : '—';
  players.innerHTML = (game.players || []).map(player => `
    <div class="game-player ${String(game.turn) === String(player.id) && game.status === 'active' ? 'active' : ''}">
      <div class="name">${escapeHtml(player.name)}</div>
      <div class="mark">${escapeHtml(playerMarkText(game, player))} · ${String(player.id) === String(me?.id) ? 'вы' : 'соперник'}</div>
    </div>
  `).join('');

  renderGameSurface({
    game,
    me,
    container:surface,
    onAction:() => null,
  });

  if (type === 'battleship' && Number.isInteger(Number(game?.pending_fire_cell))) {
    surface.querySelector(`[data-battleship-cell="${Number(game.pending_fire_cell)}"]`)?.classList.add('mgw-pending-shot');
  }

  markGamePending(Boolean(pending));
}

function pendingStatus(type, game){
  if (type === 'battleship' && Number.isInteger(Number(game?.pending_fire_cell))) return 'Выстрел отправлен…';
  if (type === 'domino' && String(game?.last_action?.type || '') === 'draw') return 'Добираем костяшку…';
  return 'Ход принят…';
}

function markGamePending(pending){
  const board = document.getElementById('gameBoard');
  if (!board) return;
  board.classList.toggle('mgw-action-pending', Boolean(pending));
  board.querySelectorAll('button').forEach(button => {
    if (pending) {
      button.dataset.mgwWasDisabled = button.disabled ? '1' : '0';
      button.disabled = true;
    } else if (button.dataset.mgwWasDisabled === '0') {
      button.disabled = false;
      delete button.dataset.mgwWasDisabled;
    } else {
      delete button.dataset.mgwWasDisabled;
    }
  });
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
