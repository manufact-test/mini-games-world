import { state } from './state.js?v=27';

const runtime = window.__MGW_V110_ACCEPTANCE__ ||= {
  initialized:false,
  pending:null, pendingFrame:0, clock:null, timer:null, observer:null,
};

export function initV110AcceptanceRuntime(){
  if (runtime.initialized) return;
  runtime.initialized = true;
  window.addEventListener('click', guardAndTrackTicTacToe, true);
  window.addEventListener('click', stabilizeSearchSummary, true);
  runtime.timer = window.setInterval(tickGameUi, 100);
  const timer = document.getElementById('timerText');
  if (timer && typeof MutationObserver === 'function') {
    runtime.observer = new MutationObserver(paintClock);
    runtime.observer.observe(timer, { childList:true, characterData:true, subtree:true });
  }
  installSearchStyle();
}

function stabilizeSearchSummary(event){
  const button = event.target instanceof Element ? event.target.closest('button') : null;
  if (!(button instanceof HTMLButtonElement)) return;
  const typeByButton = {
    startSearchBtn:'tictactoe', startFourSearchBtn:'four_in_a_row',
    startBattleshipSearchBtn:'battleship', startCheckersSearchBtn:'checkers',
    startReversiSearchBtn:'reversi', startChessSearchBtn:'chess',
    startGoSearchBtn:'go', startDominoSearchBtn:'domino',
  };
  const type = typeByButton[button.id];
  if (!type) return;
  const info = document.getElementById('searchInfo');
  if (!info) return;
  const room = String(state.room || '') === 'gold' ? 'gold' : 'match';
  const bet = room === 'match' ? 10 : Number(state.selectedBet || 10);
  const size = boardSize(type);
  const title = gameTitle(type);
  const roomTitle = room === 'gold' ? 'Gold-комната' : 'Матч-комната';
  const context = {
    primary:`${title} · ${roomTitle} · участие ${bet} коинов`,
    secondary:type === 'domino' ? 'Классика 0–6' : `Поле ${size}×${size}`,
  };
  info.classList.add('mgw-v110-search-summary');
  info.innerHTML = `<span>${escapeHtml(context.primary)}</span><span>${escapeHtml(context.secondary)}</span>`;
}

function boardSize(type){
  return ({
    tictactoe:Number(state.selectedBoardSize || 3),
    four_in_a_row:Number(state.selectedFourBoardSize || 7),
    battleship:10, checkers:8,
    reversi:Number(state.selectedReversiBoardSize || 8),
    chess:8, go:Number(state.selectedGoBoardSize || 9), domino:7,
  })[type] || 3;
}
function gameTitle(type){
  return ({ tictactoe:'Крестики-нолики', four_in_a_row:'4 в ряд', battleship:'Морской бой', checkers:'Шашки', reversi:'Реверси', chess:'Шахматы', go:'Го', domino:'Домино' })[type] || 'Игра';
}
function installSearchStyle(){
  if (document.getElementById('mgw-v110-search-style')) return;
  const style = document.createElement('style');
  style.id = 'mgw-v110-search-style';
  style.textContent = '#searchInfo{min-height:2.9em}#searchInfo.mgw-v110-search-summary{display:grid;gap:2px;align-content:start}#searchInfo.mgw-v110-search-summary>span{display:block}';
  document.head.appendChild(style);
}

function guardAndTrackTicTacToe(event){
  const button = event.target instanceof Element
    ? event.target.closest('#gameBoard[data-game-type="tictactoe"] [data-game-cell]')
    : null;
  if (!(button instanceof HTMLButtonElement)) return;
  const descriptor = validTicTacToeMove(button);
  if (!descriptor) {
    event.preventDefault();
    event.stopImmediatePropagation();
    return;
  }
  runtime.pending = { ...descriptor, startedAt:Date.now(), sawRequest:false };
  queuePendingPaint();
}

function validTicTacToeMove(button){
  const game = state.activeGame;
  const id = String(game?.id || '');
  if (!id || String(game?.status || '') !== 'active') return null;
  const item = window.__MGW_V100_GAME_RUNTIME__?.games?.get?.(id);
  const authoritative = item?.authoritative || game;
  const viewerId = String(item?.viewer?.id || state.user?.id || '');
  const cell = Number(button.dataset.gameCell);
  const board = String(authoritative?.board || '');
  const player = Array.isArray(authoritative?.players)
    ? authoritative.players.find(value => String(value?.id || '') === viewerId) : null;
  const symbol = String(player?.symbol || '');
  if (!viewerId || !Number.isInteger(cell) || !['X','O'].includes(symbol)) return null;
  if (item?.running || Number(item?.queue?.length || 0) > 0 || item?.surrenderPending) return null;
  if (String(authoritative?.turn || '') !== viewerId) return null;
  if (cell < 0 || cell >= board.length || board[cell] !== '-') return null;
  return { gameId:id, cell, symbol };
}

function tickGameUi(){ reconcilePendingMove(); syncClock(); paintClock(); }
function reconcilePendingMove(){
  const pending = runtime.pending;
  if (!pending) return;
  if (Date.now() - pending.startedAt > 5000 || String(state.activeGame?.id || '') !== pending.gameId) return clearPendingMove();
  const item = window.__MGW_V100_GAME_RUNTIME__?.games?.get?.(pending.gameId);
  const game = item?.authoritative || state.activeGame;
  const board = String(game?.board || '');
  if (board[pending.cell] === pending.symbol || String(game?.status || '') === 'finished') return clearPendingMove();
  if (item?.running || Number(item?.queue?.length || 0) > 0) pending.sawRequest = true;
  if (pending.sawRequest && !item?.running && Number(item?.queue?.length || 0) === 0) return clearPendingMove();
  queuePendingPaint();
}
function queuePendingPaint(){
  if (!runtime.pending || runtime.pendingFrame) return;
  runtime.pendingFrame = window.requestAnimationFrame(() => {
    runtime.pendingFrame = 0;
    paintPendingMove();
    if (runtime.pending) queuePendingPaint();
  });
}
function paintPendingMove(){
  const pending = runtime.pending;
  if (!pending) return;
  const cell = document.querySelector(`#gameBoard[data-game-type="tictactoe"] [data-game-cell="${pending.cell}"]`);
  if (!(cell instanceof HTMLButtonElement)) return;
  const label = pending.symbol === 'X' ? '✕' : '○';
  if (cell.textContent !== label) cell.textContent = label;
  cell.classList.toggle('x', pending.symbol === 'X');
  cell.classList.toggle('o', pending.symbol === 'O');
  cell.classList.add('locked', 'mgw-pending-action');
  cell.disabled = true;
}
function clearPendingMove(){
  runtime.pending = null;
  if (runtime.pendingFrame) window.cancelAnimationFrame(runtime.pendingFrame);
  runtime.pendingFrame = 0;
}

function syncClock(){
  const game = state.activeGame;
  if (!game?.id || String(game?.status || '') !== 'active') { runtime.clock = null; return; }
  const timeoutSec = Math.max(1, Number(game.move_timeout_sec || 60));
  const signature = `${String(game.id)}|${String(game.turn || '')}|${String(game.turn_started_at || '')}`;
  const serverNowMs = finiteNumber(game.server_now_ms);
  const deadlineMs = finiteNumber(game.turn_deadline_ms);
  const serverRemainingMs = deadlineMs !== null && serverNowMs !== null
    ? Math.max(0, deadlineMs - serverNowMs)
    : Math.max(0, Number(game.time_left ?? timeoutSec) * 1000);
  if (!runtime.clock || runtime.clock.signature !== signature) {
    runtime.clock = { signature, gameId:String(game.id), deadline:performance.now() + serverRemainingMs };
    return;
  }
  const localRemaining = Math.max(0, runtime.clock.deadline - performance.now());
  // Never jump upward on a same-turn poll.
  if (serverRemainingMs + 700 < localRemaining) runtime.clock.deadline = performance.now() + serverRemainingMs;
}
function paintClock(){
  const clock = runtime.clock;
  const timer = document.getElementById('timerText');
  if (!timer || !clock || String(state.activeGame?.id || '') !== clock.gameId) return;
  const seconds = Math.max(0, Math.ceil((clock.deadline - performance.now()) / 1000));
  const label = `${seconds} сек`;
  if (timer.textContent !== label) timer.textContent = label;
}
function finiteNumber(value){
  if (value === null || value === undefined || value === '') return null;
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

function escapeHtml(value){
  return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;' })[char]);
}
