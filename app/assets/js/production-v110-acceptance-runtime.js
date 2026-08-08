import { state } from './state.js?v=27';
import { restoreAcceptedGamePolling } from './screens/game-screen-v102-safe.js?v=102';

const runtime = window.__MGW_V110_ACCEPTANCE__ ||= {
  initialized:false,
  pending:null, pendingFrame:0, clock:null, timer:null, observer:null,
};

export function initV110AcceptanceRuntime(){
  if (runtime.initialized) return;
  runtime.initialized = true;
  window.addEventListener('click', guardPhaseBPreStartControls, true);
  window.addEventListener('click', guardAndTrackTicTacToe, true);
  window.addEventListener('click', stabilizeSearchSummary, true);
  runtime.timer = window.setInterval(tickGameUi, 100);
  const timer = document.getElementById('timerText');
  if (timer && typeof MutationObserver === 'function') {
    runtime.observer = new MutationObserver(paintClock);
    runtime.observer.observe(timer, { childList:true, characterData:true, subtree:true });
  }
  installSearchStyle();
  installLaunchStyle();
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
function installLaunchStyle(){
  if (document.getElementById('mgw-phase-b-launch-style')) return;
  const style = document.createElement('style');
  style.id = 'mgw-phase-b-launch-style';
  style.textContent = `
    #screen-game .board-wrap{position:relative}
    .mgw-phase-b-launch-overlay{position:absolute;inset:0;z-index:8;display:grid;place-items:center;padding:24px;border-radius:18px;background:rgba(10,16,30,.88);backdrop-filter:blur(6px);text-align:center}
    .mgw-phase-b-launch-overlay[hidden]{display:none!important}
    .mgw-phase-b-launch-card{display:grid;gap:8px;justify-items:center;max-width:280px}
    .mgw-phase-b-launch-title{font-size:18px;font-weight:700;line-height:1.2}
    .mgw-phase-b-launch-note{font-size:13px;line-height:1.35;opacity:.78}
    .mgw-phase-b-countdown{font-size:64px;font-weight:800;line-height:1}
    #gameBoard.mgw-phase-b-turn-wait{pointer-events:none}
  `;
  document.head.appendChild(style);
}

function guardPhaseBPreStartControls(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const boardControl = origin.closest('#gameBoard button');
  const leaveControl = origin.closest('#leaveGame');
  if (!boardControl && !leaveControl) return;

  const game = state.activeGame;
  if (!game?.id || String(game?.status || '') !== 'active') return;
  const phase = String(game?.launch_phase || '');
  if (!phase) return;

  const allowed = boardControl ? launchAllowsAction(game) : launchAllowsLeave(game);
  if (allowed) return;

  event.preventDefault();
  event.stopImmediatePropagation();
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
  if (!launchAllowsAction(authoritative)) return null;
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

function tickGameUi(){
  reconcilePendingMove();
  syncClock();
  paintClock();
  paintLaunchState();
  restoreAcceptedGamePolling(state.activeGame);
}
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
  const revision = String(game.turn_revision ?? game.clock_revision ?? '');
  const turnStartKey = String(game.turn_starts_at_ms ?? game.turn_started_at ?? '');
  const signature = `${String(game.id)}|${String(game.turn || '')}|${revision}|${turnStartKey}`;
  const serverNowMs = finiteNumber(game.server_now_ms);
  const turnStartsAtMs = finiteNumber(game.turn_starts_at_ms);
  const deadlineMs = finiteNumber(game.turn_deadline_ms);
  const now = performance.now();
  const startRemainingMs = turnStartsAtMs !== null && serverNowMs !== null
    ? Math.max(0, turnStartsAtMs - serverNowMs)
    : 0;
  const deadlineRemainingMs = deadlineMs !== null && serverNowMs !== null
    ? Math.max(0, deadlineMs - serverNowMs)
    : Math.max(0, Number(game.time_left ?? timeoutSec) * 1000);
  const candidateStart = now + startRemainingMs;
  const candidateDeadline = now + deadlineRemainingMs;

  if (!runtime.clock || runtime.clock.signature !== signature) {
    runtime.clock = {
      signature,
      gameId:String(game.id),
      start:candidateStart,
      deadline:candidateDeadline,
      timeoutSec,
    };
    return;
  }

  runtime.clock.timeoutSec = timeoutSec;
  // Same-turn snapshots may tighten an anchor, but never extend countdown or clock.
  if (candidateStart + 250 < runtime.clock.start) runtime.clock.start = candidateStart;
  if (candidateDeadline + 700 < runtime.clock.deadline) runtime.clock.deadline = candidateDeadline;
}
function paintClock(){
  const clock = runtime.clock;
  const timer = document.getElementById('timerText');
  const game = state.activeGame;
  if (!timer || !clock || String(game?.id || '') !== clock.gameId) return;
  const phase = String(game?.launch_phase || '');
  const now = performance.now();
  const beforeTurnStart = phase === 'preparing'
    || phase === 'preparation_timeout'
    || now < clock.start;
  const seconds = beforeTurnStart
    ? clock.timeoutSec
    : Math.max(0, Math.ceil((clock.deadline - now) / 1000));
  const label = `${seconds} сек`;
  if (timer.textContent !== label) timer.textContent = label;
}
function launchAllowsAction(game){
  const phase = String(game?.launch_phase || '');
  if (phase === 'preparing' || phase === 'preparation_timeout' || phase === 'cancelled') return false;
  if (phase === 'countdown' && !launchStartReached(game)) return false;
  if (phase && phase !== 'active' && phase !== 'countdown') return false;
  return turnStartReached(game);
}
function launchAllowsLeave(game){
  const phase = String(game?.launch_phase || '');
  if (!phase || phase === 'active') return true;
  if (phase === 'countdown') return launchStartReached(game);
  return false;
}
function launchStartReached(game){
  if (String(game?.launch_phase || '') !== 'countdown') return true;
  const clock = runtime.clock;
  if (clock && clock.gameId === String(game?.id || '')) return performance.now() >= clock.start;
  const startsAtMs = finiteNumber(game?.starts_at_ms);
  const serverNowMs = finiteNumber(game?.server_now_ms);
  return startsAtMs !== null && serverNowMs !== null && startsAtMs <= serverNowMs;
}
function turnStartReached(game){
  const clock = runtime.clock;
  if (clock && clock.gameId === String(game?.id || '')) return performance.now() >= clock.start;
  const turnStartsAtMs = finiteNumber(game?.turn_starts_at_ms);
  const serverNowMs = finiteNumber(game?.server_now_ms);
  return turnStartsAtMs === null || serverNowMs === null || turnStartsAtMs <= serverNowMs;
}
function paintLaunchState(){
  const game = state.activeGame;
  const board = document.getElementById('gameBoard');
  const leave = document.getElementById('leaveGame');
  const overlay = ensureLaunchOverlay();
  const status = String(game?.status || '');
  const phase = String(game?.launch_phase || '');
  const countdownWaiting = phase === 'countdown' && !launchStartReached(game);
  const blocking = status === 'active'
    && (phase === 'preparing' || phase === 'preparation_timeout' || countdownWaiting);
  const turnWaiting = status === 'active' && !blocking && !turnStartReached(game);

  if (board) board.classList.toggle('mgw-phase-b-turn-wait', turnWaiting);
  setPhaseLeaveDisabled(leave, blocking);
  if (!overlay) return;

  if (!blocking) {
    overlay.hidden = true;
    return;
  }

  overlay.hidden = false;
  const title = overlay.querySelector('[data-phase-b-title]');
  const note = overlay.querySelector('[data-phase-b-note]');
  const countdown = overlay.querySelector('[data-phase-b-countdown]');

  if (phase === 'countdown') {
    const clock = runtime.clock;
    const remaining = clock && clock.gameId === String(game?.id || '')
      ? Math.max(0, clock.start - performance.now())
      : 0;
    const seconds = Math.max(1, Math.min(3, Math.ceil(remaining / 1000)));
    if (title) title.textContent = 'Матч начинается';
    if (note) note.textContent = 'Оба игрока готовы';
    if (countdown) {
      countdown.hidden = false;
      countdown.textContent = String(seconds);
    }
    return;
  }

  if (countdown) countdown.hidden = true;
  if (phase === 'preparation_timeout') {
    if (title) title.textContent = 'Матч не начался';
    if (note) note.textContent = 'Завершаем матч и возвращаем ставку';
    return;
  }

  const ready = Math.max(0, Number(game?.ready_count || 0));
  const required = Math.max(1, Number(game?.ready_required || 2));
  if (title) title.textContent = 'Синхронизируем игроков';
  if (note) note.textContent = `Готово устройств: ${Math.min(ready, required)} из ${required}`;
}
function ensureLaunchOverlay(){
  const wrap = document.querySelector('#screen-game .board-wrap');
  if (!(wrap instanceof Element)) return null;
  let overlay = document.getElementById('mgwPhaseBLaunchOverlay');
  if (overlay) return overlay;
  overlay = document.createElement('div');
  overlay.id = 'mgwPhaseBLaunchOverlay';
  overlay.className = 'mgw-phase-b-launch-overlay';
  overlay.hidden = true;
  overlay.innerHTML = `
    <div class="mgw-phase-b-launch-card" role="status" aria-live="polite">
      <div class="mgw-phase-b-countdown" data-phase-b-countdown hidden></div>
      <strong class="mgw-phase-b-launch-title" data-phase-b-title></strong>
      <span class="mgw-phase-b-launch-note" data-phase-b-note></span>
    </div>
  `;
  wrap.appendChild(overlay);
  return overlay;
}
function setPhaseLeaveDisabled(button, disabled){
  if (!(button instanceof HTMLButtonElement)) return;
  if (disabled) {
    if (!button.disabled) {
      button.disabled = true;
      button.dataset.mgwPhaseBDisabled = '1';
    }
    return;
  }
  if (button.dataset.mgwPhaseBDisabled === '1') {
    button.disabled = false;
    delete button.dataset.mgwPhaseBDisabled;
  }
}
function finiteNumber(value){
  if (value === null || value === undefined || value === '') return null;
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

function escapeHtml(value){
  return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;' })[char]);
}