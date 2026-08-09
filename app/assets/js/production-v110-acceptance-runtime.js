import { state } from './state.js?v=27';

const runtime = window.__MGW_V110_ACCEPTANCE__ ||= {
  initialized:false,
  pending:null, pendingFrame:0, clock:null, timer:null, observer:null,
};

export function initV110AcceptanceRuntime(){
  if (runtime.initialized) return;
  runtime.initialized = true;
  document.addEventListener('mgw:phase-b-game-entering', primeLaunchState);
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
  ensureLaunchOverlay();
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
function gameTitleFromGame(game){
  const type = String(game?.game_type || game?.type || state.selectedGame || 'tictactoe');
  return gameTitle(type);
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
    .mgw-phase-b-launch-overlay{position:absolute;inset:0;z-index:140;display:flex;align-items:center;justify-content:center;padding:max(24px,env(safe-area-inset-top)) 22px max(24px,env(safe-area-inset-bottom));overflow:hidden;background:radial-gradient(circle at 18% 18%,rgba(124,92,255,.28),transparent 34%),radial-gradient(circle at 82% 78%,rgba(46,230,166,.18),transparent 31%),linear-gradient(180deg,#0b0f1a 0%,#080b13 100%);text-align:center}
    .mgw-phase-b-launch-overlay[hidden]{display:none!important}
    .mgw-phase-b-launch-card{position:relative;width:min(100%,360px);display:grid;justify-items:center;gap:0;padding:30px 22px 28px;border:1px solid rgba(255,255,255,.11);border-radius:32px;background:rgba(255,255,255,.055);box-shadow:0 26px 70px rgba(0,0,0,.34);overflow:hidden}
    .mgw-phase-b-launch-card:before{content:'';position:absolute;width:180px;height:180px;left:-90px;top:-100px;border-radius:50%;background:rgba(124,92,255,.16);filter:blur(2px)}
    .mgw-phase-b-launch-game{position:relative;z-index:1;display:inline-flex;align-items:center;min-height:30px;padding:0 12px;border:1px solid rgba(255,255,255,.10);border-radius:999px;background:rgba(255,255,255,.055);font-size:12px;font-weight:850;letter-spacing:.02em;color:rgba(255,255,255,.78)}
    .mgw-phase-b-launch-visual{position:relative;width:132px;height:112px;margin:18px 0 12px}
    .mgw-phase-b-launch-shape{position:absolute;border:1px solid rgba(255,255,255,.16);box-shadow:0 18px 42px rgba(124,92,255,.18);animation:mgwPhaseBFloat 2.6s ease-in-out infinite}
    .mgw-phase-b-launch-shape.one{width:58px;height:58px;left:12px;top:13px;border-radius:20px;transform:rotate(12deg);background:linear-gradient(135deg,rgba(124,92,255,.92),rgba(46,230,166,.48))}
    .mgw-phase-b-launch-shape.two{width:44px;height:44px;right:12px;top:22px;border-radius:50%;animation-delay:.22s;background:linear-gradient(135deg,rgba(255,200,87,.95),rgba(124,92,255,.48))}
    .mgw-phase-b-launch-shape.three{width:80px;height:31px;left:28px;bottom:9px;border-radius:999px;animation-delay:.44s;background:linear-gradient(135deg,rgba(46,230,166,.82),rgba(255,255,255,.22))}
    .mgw-phase-b-launch-dot{position:absolute;width:8px;height:8px;border-radius:50%;background:#fff;opacity:.72;animation:mgwPhaseBPulse 1.6s ease-in-out infinite}
    .mgw-phase-b-launch-dot.one{left:4px;bottom:26px}.mgw-phase-b-launch-dot.two{right:4px;bottom:32px;animation-delay:.3s}.mgw-phase-b-launch-dot.three{left:62px;top:1px;animation-delay:.6s}
    .mgw-phase-b-countdown{position:absolute;inset:0;display:grid;place-items:center;font-size:76px;font-weight:950;line-height:1;letter-spacing:-.06em;text-shadow:0 12px 34px rgba(124,92,255,.35)}
    .mgw-phase-b-countdown[hidden]{display:none!important}
    .mgw-phase-b-launch-title{position:relative;z-index:1;margin:0;font-size:26px;font-weight:950;line-height:1.08;letter-spacing:-.045em}
    .mgw-phase-b-launch-note{position:relative;z-index:1;margin-top:9px;max-width:270px;font-size:14px;line-height:1.45;color:var(--muted)}
    .mgw-phase-b-launch-progress{position:relative;z-index:1;display:flex;gap:7px;margin-top:18px}
    .mgw-phase-b-launch-progress i{display:block;width:7px;height:7px;border-radius:50%;background:rgba(255,255,255,.26);animation:mgwPhaseBDots 1.15s ease-in-out infinite}
    .mgw-phase-b-launch-progress i:nth-child(2){animation-delay:.15s}.mgw-phase-b-launch-progress i:nth-child(3){animation-delay:.3s}
    #gameBoard.mgw-phase-b-turn-wait{pointer-events:none}
    @keyframes mgwPhaseBFloat{0%,100%{translate:0 0}50%{translate:0 -8px}}
    @keyframes mgwPhaseBPulse{0%,100%{opacity:.35;transform:scale(.9)}50%{opacity:1;transform:scale(1.18)}}
    @keyframes mgwPhaseBDots{0%,100%{opacity:.25;transform:translateY(0)}50%{opacity:1;transform:translateY(-4px)}}
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
  return !phase || phase === 'active';
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
function primeLaunchState(event){
  const game = event?.detail?.game || null;
  const phase = String(game?.launch_phase || '');
  if (String(game?.status || '') !== 'active' || !['preparing', 'countdown', 'preparation_timeout'].includes(phase)) return;
  const overlay = ensureLaunchOverlay();
  if (!overlay) return;
  overlay.hidden = false;
  renderLaunchOverlay(overlay, game, phase);
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
  const leaveBlocked = status === 'active' && !!phase && !launchAllowsLeave(game);

  if (board) board.classList.toggle('mgw-phase-b-turn-wait', turnWaiting);
  setPhaseLeaveDisabled(leave, leaveBlocked);
  if (!overlay) return;

  if (!blocking) {
    overlay.hidden = true;
    return;
  }

  overlay.hidden = false;
  renderLaunchOverlay(overlay, game, phase);
}
function renderLaunchOverlay(overlay, game, phase){
  const title = overlay.querySelector('[data-phase-b-title]');
  const note = overlay.querySelector('[data-phase-b-note]');
  const countdown = overlay.querySelector('[data-phase-b-countdown]');
  const progress = overlay.querySelector('[data-phase-b-progress]');
  const gameLabel = overlay.querySelector('[data-phase-b-game]');
  if (gameLabel) gameLabel.textContent = gameTitleFromGame(game);

  if (phase === 'countdown') {
    const clock = runtime.clock;
    const fallbackRemaining = Math.max(0,
      Number(game?.starts_at_ms || 0) - Number(game?.server_now_ms || 0));
    const remaining = clock && clock.gameId === String(game?.id || '')
      ? Math.max(0, clock.start - performance.now())
      : fallbackRemaining;
    const seconds = Math.max(1, Math.min(3, Math.ceil(remaining / 1000)));
    if (title) title.textContent = 'Поехали!';
    if (note) note.textContent = 'Матч начинается';
    if (countdown) {
      countdown.hidden = false;
      countdown.textContent = String(seconds);
    }
    if (progress) progress.hidden = true;
    return;
  }

  if (countdown) countdown.hidden = true;
  if (phase === 'preparation_timeout') {
    if (title) title.textContent = 'Матч не состоялся';
    if (note) note.textContent = 'Соперник не подключился вовремя';
    if (progress) progress.hidden = true;
    return;
  }

  if (title) title.textContent = 'Готовим матч';
  if (note) note.textContent = 'Ещё мгновение — и начинаем';
  if (progress) progress.hidden = false;
}
function ensureLaunchOverlay(){
  let overlay = document.getElementById('mgwPhaseBLaunchOverlay');
  if (overlay) return overlay;
  const owner = document.getElementById('app');
  if (!(owner instanceof Element)) return null;
  overlay = document.createElement('div');
  overlay.id = 'mgwPhaseBLaunchOverlay';
  overlay.className = 'mgw-phase-b-launch-overlay';
  overlay.hidden = true;
  overlay.innerHTML = `
    <div class="mgw-phase-b-launch-card" role="status" aria-live="polite">
      <div class="mgw-phase-b-launch-game" data-phase-b-game>Игра</div>
      <div class="mgw-phase-b-launch-visual" aria-hidden="true">
        <span class="mgw-phase-b-launch-shape one"></span>
        <span class="mgw-phase-b-launch-shape two"></span>
        <span class="mgw-phase-b-launch-shape three"></span>
        <span class="mgw-phase-b-launch-dot one"></span>
        <span class="mgw-phase-b-launch-dot two"></span>
        <span class="mgw-phase-b-launch-dot three"></span>
        <div class="mgw-phase-b-countdown" data-phase-b-countdown hidden></div>
      </div>
      <strong class="mgw-phase-b-launch-title" data-phase-b-title>Готовим матч</strong>
      <span class="mgw-phase-b-launch-note" data-phase-b-note>Ещё мгновение — и начинаем</span>
      <div class="mgw-phase-b-launch-progress" data-phase-b-progress aria-hidden="true"><i></i><i></i><i></i></div>
    </div>
  `;
  owner.appendChild(overlay);
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
