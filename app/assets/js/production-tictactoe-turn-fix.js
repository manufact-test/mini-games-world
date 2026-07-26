import { state } from './state.js?v=27';
import { api } from './api/client.js?v=47';
import { toast } from './components/toast.js?v=41';
import { haptic } from './telegram/telegram-app.js?v=27';
import { renderBalances } from './ui.js?v=89';
import { renderTicTacToeSurface } from './games/tictactoe/renderer.js?v=53';

const originalGameState = api.gameState;

let earlyInstalled = false;
let afterInstalled = false;
let baseGameState = null;

const viewerByGame = new Map();
const actionPromiseByGame = new Map();
const latestResultByGame = new Map();
const generationByGame = new Map();
const busyGames = new Set();

export function initTicTacToeTurnFixEarly(){
  if (earlyInstalled) return;
  earlyInstalled = true;
  document.addEventListener('click', handleCellClick, true);
}

export function scheduleTicTacToeTurnFixAfter(){
  let attempts = 0;

  const install = () => {
    attempts++;
    if (api.gameState !== originalGameState || attempts >= 50) {
      initTicTacToeTurnFixAfter();
      return;
    }
    window.setTimeout(install, 0);
  };

  window.setTimeout(install, 0);
}

function initTicTacToeTurnFixAfter(){
  if (afterInstalled) return;
  afterInstalled = true;

  baseGameState = api.gameState;
  api.gameState = coordinatedGameState;
}

function handleCellClick(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const button = origin.closest('[data-game-cell]');
  if (!(button instanceof HTMLButtonElement)) return;

  const game = state.activeGame;
  if (!isTicTacToe(game)) return;

  event.preventDefault();
  event.stopImmediatePropagation();
  submitTicTacToeCell(button);
}

async function coordinatedGameState(gameId = null){
  const key = gameKey(gameId);
  const generation = gameGeneration(key);
  const pendingAction = actionPromiseByGame.get(key);

  if (pendingAction) {
    await pendingAction.catch(() => null);
    const latest = latestResultByGame.get(key);
    if (latest) return latest;
  }

  const result = await baseGameState(gameId);
  if (generation !== gameGeneration(key)) {
    const currentAction = actionPromiseByGame.get(key);
    if (currentAction) await currentAction.catch(() => null);
    return latestResultByGame.get(key) || result;
  }

  rememberViewer(result);
  const resultKey = result?.game?.id ? gameKey(result.game.id) : key;
  latestResultByGame.set(resultKey, result);
  return result;
}

async function submitTicTacToeCell(button){
  const game = state.activeGame;
  if (!isTicTacToe(game) || !game?.id) return;

  const key = gameKey(game.id);
  if (busyGames.has(key) || actionPromiseByGame.has(key)) return;

  const viewer = viewerByGame.get(key);
  const viewerId = String(viewer?.id || '');
  if (!viewerId) return;

  const cell = Number(button.dataset.gameCell);
  const board = String(game.board || '');
  const symbol = symbolForViewer(game, viewerId);
  const allowed = String(game.status || '') === 'active'
    && String(game.turn || '') === viewerId
    && Number.isInteger(cell)
    && cell >= 0
    && cell < board.length
    && board[cell] === '-'
    && (symbol === 'X' || symbol === 'O');

  if (!allowed) return;

  const previousGame = clone(game);
  const nextPlayer = Array.isArray(game.players)
    ? game.players.find(player => String(player?.id || '') !== viewerId)
    : null;
  const optimisticGame = {
    ...clone(game),
    board:`${board.slice(0, cell)}${symbol}${board.slice(cell + 1)}`,
    turn:String(nextPlayer?.id || ''),
  };

  busyGames.add(key);
  bumpGameGeneration(key);
  state.activeGame = optimisticGame;
  haptic('light');
  renderBoard(optimisticGame, viewer, cell);

  const actionPromise = api.gameAction(game.id, { type:'cell', cell });
  actionPromiseByGame.set(key, actionPromise);

  try {
    const result = await actionPromise;
    rememberViewer(result);
    latestResultByGame.set(key, result);

    if (result?.user) {
      state.user = result.user;
      state.session = result.session || state.session;
      renderBalances(state.user);
    }

    if (result?.game) {
      state.activeGame = result.game;
      renderBoard(result.game, result.me || viewer);
      if (String(result.game.status || '') === 'finished') {
        document.dispatchEvent(new CustomEvent('mgw:history-refresh'));
      }
    }
  } catch (error) {
    state.activeGame = previousGame;
    latestResultByGame.set(key, {
      game:previousGame,
      me:viewer,
      user:state.user,
      session:state.session,
    });
    renderBoard(previousGame, viewer);
    toast(error.message || 'Не удалось выполнить ход.');
  } finally {
    if (actionPromiseByGame.get(key) === actionPromise) {
      actionPromiseByGame.delete(key);
    }
    busyGames.delete(key);
    bumpGameGeneration(key);
  }
}

function renderBoard(game, viewer, optimisticCell = -1){
  const container = document.getElementById('gameBoard');
  if (!container) return;

  renderTicTacToeSurface({
    game,
    me:viewer,
    container,
    onAction:() => null,
  });

  if (optimisticCell >= 0) {
    container.querySelector(`[data-game-cell="${optimisticCell}"]`)?.classList.add('is-optimistic');
  }

  if (busyGames.has(gameKey(game?.id))) {
    container.querySelectorAll('[data-game-cell]').forEach(cell => {
      cell.disabled = true;
      cell.classList.add('locked');
    });
  }

  const turn = document.getElementById('turnText');
  const viewerId = String(viewer?.id || '');
  if (turn) {
    turn.textContent = String(game?.status || '') === 'finished'
      ? 'Игра завершена'
      : (String(game?.turn || '') === viewerId ? 'Ваш ход' : 'Ход соперника');
  }
}

function rememberViewer(result){
  const gameId = String(result?.game?.id || '');
  const viewer = result?.me || null;
  if (!gameId || viewer?.id === undefined || viewer?.id === null) return;
  viewerByGame.set(gameKey(gameId), viewer);
}

function symbolForViewer(game, viewerId){
  const player = Array.isArray(game?.players)
    ? game.players.find(item => String(item?.id || '') === viewerId)
    : null;
  return String(player?.symbol || '');
}

function isTicTacToe(game){
  return Boolean(game)
    && String(game?.game_type || 'tictactoe') === 'tictactoe'
    && typeof game?.board === 'string';
}

function gameKey(gameId){
  return String(gameId || 'search');
}

function gameGeneration(key){
  return Number(generationByGame.get(key) || 0);
}

function bumpGameGeneration(key){
  generationByGame.set(key, gameGeneration(key) + 1);
}

function clone(value){
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}
