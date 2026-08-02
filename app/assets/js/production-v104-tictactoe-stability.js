import { state } from './state.js?v=27';

const MAX_PENDING_MS = 5000;
const runtime = window.__MGW_V104_TICTACTOE__ ||= {
  initialized:false,
  previousFetch:null,
  pending:null,
  observer:null,
};

export function initV104TicTacToeStability(){
  if (runtime.initialized) return;
  runtime.initialized = true;
  runtime.previousFetch = window.fetch.bind(window);
  window.fetch = stableFetch;

  document.addEventListener('click', rememberValidMove, true);
  const board = document.getElementById('gameBoard');
  if (board && typeof MutationObserver === 'function') {
    runtime.observer = new MutationObserver(pinPendingMark);
    runtime.observer.observe(board, { childList:true, subtree:true, characterData:true, attributes:true });
  }
}

function rememberValidMove(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const button = origin.closest('#gameBoard[data-game-type="tictactoe"] [data-game-cell]');
  if (!(button instanceof HTMLButtonElement)) return;

  const game = state.activeGame;
  const id = String(game?.id || '');
  const item = window.__MGW_V100_GAME_RUNTIME__?.games?.get?.(id);
  const authoritative = item?.authoritative || game;
  const viewerId = String(item?.viewer?.id || state.user?.id || '');
  const cell = Number(button.dataset.gameCell);
  const board = String(authoritative?.board || '');
  const player = Array.isArray(authoritative?.players)
    ? authoritative.players.find(entry => String(entry?.id || '') === viewerId)
    : null;
  const symbol = String(player?.symbol || '');

  if (!id || !viewerId || !Number.isInteger(cell) || !['X','O'].includes(symbol)) return;
  if (String(authoritative?.status || '') !== 'active') return;
  if (String(authoritative?.turn || '') !== viewerId) return;
  if (cell < 0 || cell >= board.length || board[cell] !== '-') return;
  if (item?.running || Number(item?.queue?.length || 0) > 0 || item?.surrenderPending) return;

  runtime.pending = {
    gameId:id,
    cell,
    symbol,
    startedAt:Date.now(),
  };
  queueMicrotask(pinPendingMark);
  window.requestAnimationFrame(pinPendingMark);
}

async function stableFetch(input, init = {}){
  const meta = actionMeta(input, init);
  let response;
  try {
    response = await runtime.previousFetch(input, init);
  } catch (error) {
    if (meta && runtime.pending?.gameId === meta.gameId) clearPending();
    throw error;
  }

  if (!meta || runtime.pending?.gameId !== meta.gameId) return response;
  const data = await response.clone().json().catch(() => null);
  if (!response.ok || !data || data.ok === false) {
    clearPending();
    return response;
  }

  const game = data?.game || null;
  const pending = runtime.pending;
  const board = String(game?.board || '');
  if (pending && board[pending.cell] === pending.symbol) {
    clearPending();
  } else if (String(game?.status || '') === 'finished') {
    clearPending();
  } else {
    pinPendingMark();
  }
  return response;
}

function pinPendingMark(){
  const pending = runtime.pending;
  if (!pending) return;
  if (Date.now() - pending.startedAt > MAX_PENDING_MS) {
    clearPending();
    return;
  }
  if (String(state.activeGame?.id || '') !== pending.gameId) {
    clearPending();
    return;
  }

  const cell = document.querySelector(`#gameBoard[data-game-type="tictactoe"] [data-game-cell="${pending.cell}"]`);
  if (!(cell instanceof HTMLButtonElement)) return;
  const label = pending.symbol === 'X' ? '✕' : '○';
  if (cell.textContent !== label) cell.textContent = label;
  cell.classList.toggle('x', pending.symbol === 'X');
  cell.classList.toggle('o', pending.symbol === 'O');
  cell.classList.add('locked', 'mgw-pending-action');
  cell.disabled = true;
}

function clearPending(){
  runtime.pending = null;
}

function actionMeta(input, init){
  const method = String(init?.method || input?.method || 'GET').toUpperCase();
  if (method !== 'POST' || typeof init?.body !== 'string') return null;
  let url;
  let body;
  try {
    url = new URL(typeof input === 'string' ? input : input.url, window.location.href);
    body = JSON.parse(init.body);
  } catch (error) {
    return null;
  }
  const action = String(body?.action || '');
  if (!url.pathname.endsWith('/bot/api.php') || !['game_action','make_move'].includes(action)) return null;
  return { gameId:String(body?.gameId || '') };
}
