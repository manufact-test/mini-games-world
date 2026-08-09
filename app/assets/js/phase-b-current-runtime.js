import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { getInitData } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { applyReadonlyGameProjection } from './screens/game-screen-phase-b-current.js?v=116&b=f6d062608b0c';

const WATCH_URL = `${window.location.origin}/bot/game-watch.php`;
const WATCH_INTERVAL_MS = 250;
const PRIMARY_GAME_POLL_FLOOR_MS = 1500;
const VOLATILE_KEYS = new Set([
  'time_left',
  'server_now_ms',
  'turn_deadline_ms',
]);

const runtime = window.__MGW_PHASE_B_CURRENT__ ||= {
  initialized:false,
  watchTimer:null,
  watchBusy:false,
  tickTimer:null,
  clock:null,
  timerObserver:null,
};

export function initPhaseBCurrentRuntime(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  enforcePrimaryPollFloor();
  installLaunchStyle();
  ensureLaunchOverlay();

  document.addEventListener('mgw:phase-b-game-entering', event => {
    primeLaunchState(event.detail?.game || state.activeGame);
    scheduleWatch(0);
  });
  document.addEventListener('mgw:app-ready', () => {
    enforcePrimaryPollFloor();
    scheduleWatch(0);
  }, { once:true });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      enforcePrimaryPollFloor();
      scheduleWatch(0);
    }
  });
  document.addEventListener('mgw:game-finished', () => scheduleWatch(WATCH_INTERVAL_MS));
  document.addEventListener('mgw:game-dismissed', () => scheduleWatch(WATCH_INTERVAL_MS));
  window.addEventListener('click', guardPhaseBControls, true);

  runtime.tickTimer = window.setInterval(tickPhaseB, 100);

  const timer = document.getElementById('timerText');
  if (timer && typeof MutationObserver === 'function') {
    runtime.timerObserver = new MutationObserver(paintClock);
    runtime.timerObserver.observe(timer, { childList:true, characterData:true, subtree:true });
  }

  scheduleWatch(0);
}

function enforcePrimaryPollFloor(){
  APP_CONFIG.gameIntervalMs = Math.max(
    PRIMARY_GAME_POLL_FLOOR_MS,
    Number(APP_CONFIG.gameIntervalMs || 0)
  );
}

function primeLaunchState(game){
  if (!game?.id) return;
  syncClock(game);
  paintLaunchState(game);
  paintClock();
}

function scheduleWatch(delay = WATCH_INTERVAL_MS){
  window.clearTimeout(runtime.watchTimer);
  runtime.watchTimer = window.setTimeout(async () => {
    await watchCurrentGame();
    scheduleWatch(WATCH_INTERVAL_MS);
  }, Math.max(0, Number(delay || 0)));
}

async function watchCurrentGame(){
  const local = state.activeGame;
  const gameId = String(local?.id || '');
  if (!canWatch(local, gameId) || runtime.watchBusy) return null;

  runtime.watchBusy = true;
  try {
    const response = await window.fetch(WATCH_URL, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      body:JSON.stringify({
        initData:getInitData(),
        sessionId:getSessionId(),
        gameId,
      }),
      priority:'high',
      cache:'no-store',
    });
    const result = await response.json().catch(() => null);
    if (!response.ok || !result || result.ok === false) return null;

    const game = result.game || null;
    if (!game?.id || String(game.id) !== gameId) return null;
    if (!canAcceptProjection(gameId)) return null;

    if (projectionKey(state.activeGame) !== projectionKey(game)) {
      applyReadonlyGameProjection(game, result.me || null);
    }

    syncClock(game);
    paintLaunchState(game);
    paintClock();
    return game;
  } catch (error) {
    return null;
  } finally {
    runtime.watchBusy = false;
  }
}

function canWatch(game, gameId){
  if (!gameId || String(game?.status || '') !== 'active') return false;
  const phase = String(game?.launch_phase || '');
  if (phase && !['preparing','countdown','active'].includes(phase)) return false;
  if (game?.is_bot_game) return false;
  if (document.visibilityState !== 'visible') return false;
  const screen = document.querySelector('.screen.active');
  if (String(screen?.dataset.screen || '') !== 'game') return false;
  return !localActionBusy();
}

function canAcceptProjection(gameId){
  return String(state.activeGame?.id || '') === gameId && !localActionBusy();
}

function localActionBusy(){
  if (window.__MGW_GAME_SCREEN_RUNTIME__?.actionBusy) return true;
  return Boolean(document.querySelector('#gameBoard .is-optimistic, #gameBoard .mgw-pending-action'));
}

function tickPhaseB(){
  const game = state.activeGame;
  syncClock(game);
  paintLaunchState(game);
  paintClock();
}

function syncClock(game){
  if (!game?.id || String(game?.status || '') !== 'active') {
    runtime.clock = null;
    return;
  }

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
  if (candidateStart + 250 < runtime.clock.start) runtime.clock.start = candidateStart;
  if (candidateDeadline + 700 < runtime.clock.deadline) runtime.clock.deadline = candidateDeadline;
}

function paintClock(){
  const clock = runtime.clock;
  const timer = document.getElementById('timerText');
  const game = state.activeGame;
  if (!timer || !clock || String(game?.id || '') !== clock.gameId || String(game?.status || '') !== 'active') return;

  const now = performance.now();
  const beforeTurnStart = now < clock.start;
  const seconds = beforeTurnStart
    ? clock.timeoutSec
    : Math.max(0, Math.min(clock.timeoutSec, Math.ceil((clock.deadline - now) / 1000)));
  const text = `${seconds} сек`;
  if (timer.textContent !== text) timer.textContent = text;
}

function paintLaunchState(game = state.activeGame){
  const overlay = ensureLaunchOverlay();
  const board = document.getElementById('gameBoard');
  const leave = document.getElementById('leaveGame');

  if (!game?.id || String(game?.status || '') !== 'active') {
    if (overlay) overlay.hidden = true;
    if (board) board.classList.remove('mgw-phase-b-turn-wait');
    setPhaseLeaveDisabled(leave, false);
    return;
  }

  const phase = String(game?.launch_phase || '');
  if (!phase) {
    if (overlay) overlay.hidden = true;
    if (board) board.classList.remove('mgw-phase-b-turn-wait');
    setPhaseLeaveDisabled(leave, false);
    return;
  }

  const countdownWaiting = phase === 'countdown' && !sharedStartReached(game);
  const blocking = phase === 'preparing' || countdownWaiting;
  const turnWaiting = !blocking && !turnStartReached(game);
  const leaveBlocked = !launchAllowsLeave(game);

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
    const remaining = sharedStartRemainingMs(game);
    const seconds = Math.max(1, Math.min(3, Math.ceil(remaining / 1000)));
    if (title) title.textContent = 'Матч начинается';
    if (note) note.textContent = 'Приготовьтесь';
    if (countdown) {
      countdown.hidden = false;
      countdown.textContent = String(seconds);
    }
    if (progress) progress.hidden = true;
    return;
  }

  if (countdown) countdown.hidden = true;
  if (title) title.textContent = 'Готовим матч';
  if (note) note.textContent = 'Подключаем игроков — ещё мгновение';
  if (progress) progress.hidden = false;
}

function guardPhaseBControls(event){
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

function launchAllowsAction(game){
  const phase = String(game?.launch_phase || '');
  if (!phase) return true;
  if (phase === 'preparing' || phase === 'preparation_timeout') return false;
  if (phase === 'countdown') return sharedStartReached(game);
  if (phase === 'active') return turnStartReached(game);
  return false;
}

function launchAllowsLeave(game){
  if (!Object.prototype.hasOwnProperty.call(game || {}, 'launch_phase')) return true;
  return String(game?.launch_phase || '') === 'active';
}

function sharedStartReached(game){
  const clock = runtime.clock;
  if (clock && clock.gameId === String(game?.id || '')) return performance.now() >= clock.start;
  const serverNow = finiteNumber(game?.server_now_ms);
  const startsAt = finiteNumber(game?.starts_at_ms);
  return serverNow !== null && startsAt !== null && serverNow >= startsAt;
}

function sharedStartRemainingMs(game){
  const clock = runtime.clock;
  if (clock && clock.gameId === String(game?.id || '')) return Math.max(0, clock.start - performance.now());
  const serverNow = finiteNumber(game?.server_now_ms);
  const startsAt = finiteNumber(game?.starts_at_ms);
  return serverNow !== null && startsAt !== null ? Math.max(0, startsAt - serverNow) : 3000;
}

function turnStartReached(game){
  const clock = runtime.clock;
  if (clock && clock.gameId === String(game?.id || '')) return performance.now() >= clock.start;
  const serverNow = finiteNumber(game?.server_now_ms);
  const startsAt = finiteNumber(game?.turn_starts_at_ms);
  return serverNow === null || startsAt === null ? true : serverNow >= startsAt;
}

function installLaunchStyle(){
  if (document.getElementById('mgw-phase-b-current-style')) return;
  const style = document.createElement('style');
  style.id = 'mgw-phase-b-current-style';
  style.textContent = `
    .mgw-phase-b-launch-overlay{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;padding:max(24px,env(safe-area-inset-top)) 22px max(24px,env(safe-area-inset-bottom));overflow:hidden;background:radial-gradient(circle at 18% 18%,rgba(124,92,255,.3),transparent 34%),radial-gradient(circle at 82% 78%,rgba(46,230,166,.2),transparent 31%),linear-gradient(180deg,#0b0f1a 0%,#080b13 100%);text-align:center}
    .mgw-phase-b-launch-overlay[hidden]{display:none!important}
    .mgw-phase-b-launch-card{position:relative;width:min(100%,360px);display:grid;justify-items:center;padding:30px 22px 28px;border:1px solid rgba(255,255,255,.12);border-radius:32px;background:rgba(255,255,255,.06);box-shadow:0 26px 70px rgba(0,0,0,.36);overflow:hidden;backdrop-filter:blur(18px)}
    .mgw-phase-b-launch-card:before{content:'';position:absolute;width:180px;height:180px;left:-90px;top:-100px;border-radius:50%;background:rgba(124,92,255,.18)}
    .mgw-phase-b-launch-game{position:relative;z-index:1;display:inline-flex;align-items:center;min-height:30px;padding:0 12px;border:1px solid rgba(255,255,255,.1);border-radius:999px;background:rgba(255,255,255,.055);font-size:12px;font-weight:850;letter-spacing:.02em;color:rgba(255,255,255,.8)}
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
    .mgw-phase-b-launch-note{position:relative;z-index:1;margin-top:9px;max-width:280px;font-size:14px;line-height:1.45;color:var(--muted)}
    .mgw-phase-b-launch-progress{position:relative;z-index:1;display:flex;gap:7px;margin-top:18px}
    .mgw-phase-b-launch-progress i{display:block;width:7px;height:7px;border-radius:50%;background:rgba(255,255,255,.28);animation:mgwPhaseBDots 1.15s ease-in-out infinite}
    .mgw-phase-b-launch-progress i:nth-child(2){animation-delay:.15s}.mgw-phase-b-launch-progress i:nth-child(3){animation-delay:.3s}
    #gameBoard.mgw-phase-b-turn-wait{pointer-events:none}
    @keyframes mgwPhaseBFloat{0%,100%{translate:0 0}50%{translate:0 -8px}}
    @keyframes mgwPhaseBPulse{0%,100%{opacity:.35;transform:scale(.9)}50%{opacity:1;transform:scale(1.18)}}
    @keyframes mgwPhaseBDots{0%,100%{opacity:.25;transform:translateY(0)}50%{opacity:1;transform:translateY(-4px)}}
  `;
  document.head.appendChild(style);
}

function ensureLaunchOverlay(){
  let overlay = document.getElementById('mgwPhaseBLaunchOverlay');
  if (overlay) return overlay;
  const owner = document.getElementById('app') || document.body;
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
      <span class="mgw-phase-b-launch-note" data-phase-b-note>Подключаем игроков — ещё мгновение</span>
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

function gameTitleFromGame(game){
  const type = String(game?.game_type || game?.type || state.selectedGame || 'tictactoe');
  return ({
    tictactoe:'Крестики-нолики',
    four_in_a_row:'4 в ряд',
    battleship:'Морской бой',
    checkers:'Шашки',
    reversi:'Реверси',
    chess:'Шахматы',
    go:'Го',
    domino:'Домино',
  })[type] || 'Игра';
}

function projectionKey(value){
  return JSON.stringify(normalizeProjection(value));
}

function normalizeProjection(value){
  if (Array.isArray(value)) return value.map(normalizeProjection);
  if (!value || typeof value !== 'object') return value;
  const normalized = {};
  for (const key of Object.keys(value).sort()) {
    if (VOLATILE_KEYS.has(key)) continue;
    normalized[key] = normalizeProjection(value[key]);
  }
  return normalized;
}

function finiteNumber(value){
  if (value === null || value === undefined || value === '') return null;
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}
