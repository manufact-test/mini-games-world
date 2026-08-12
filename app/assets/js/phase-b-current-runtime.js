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
      const accepted = applyReadonlyGameProjection(game, result.me || null);
      if (!accepted) {
        const canonical = state.activeGame;
        syncClock(canonical);
        paintLaunchState(canonical);
        paintClock();
        return canonical;
      }
    }

    const canonical = state.activeGame;
    syncClock(game);
    paintLaunchState(canonical);
    paintClock();
    return canonical;
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

  const blocking = phase === 'preparing' || phase === 'countdown';
  const turnWaiting = phase === 'active' && !turnStartReached(game);
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
  if (title) title.textContent = 'Матч скоро начнётся';

  if (phase === 'countdown') {
    const remaining = sharedStartRemainingMs(game);
    const readyForServer = remaining <= 0;
    const seconds = Math.max(1, Math.min(3, Math.ceil(remaining / 1000)));
    if (note) note.textContent = readyForServer ? 'Вперёд!' : 'Приготовьтесь к первому ходу';
    if (countdown) {
      countdown.hidden = false;
      countdown.dataset.loading = '0';
      countdown.dataset.ready = readyForServer ? '1' : '0';
      countdown.textContent = readyForServer ? 'СТАРТ' : String(seconds);
    }
    if (progress) {
      progress.hidden = false;
      progress.dataset.visible = '0';
    }
    return;
  }

  if (countdown) {
    countdown.hidden = false;
    countdown.dataset.loading = '1';
    countdown.dataset.ready = '0';
    countdown.textContent = 'VS';
  }
  if (note) note.textContent = 'Готовьтесь к игре';
  if (progress) {
    progress.hidden = false;
    progress.dataset.visible = '1';
  }
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
    .mgw-phase-b-launch-overlay{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;padding:max(24px,env(safe-area-inset-top)) 14px max(24px,env(safe-area-inset-bottom));overflow:hidden;background:#070910;text-align:center;isolation:isolate}
    .mgw-phase-b-launch-overlay:before{content:'';position:absolute;z-index:0;top:0;bottom:0;left:50%;width:min(100%,460px);transform:translateX(-50%);background:radial-gradient(circle at 50% 42%,rgba(124,92,255,.20),transparent 34%),radial-gradient(circle at 50% 72%,rgba(46,230,166,.08),transparent 30%),#070910;box-shadow:0 0 90px rgba(124,92,255,.10);pointer-events:none}
    .mgw-phase-b-launch-overlay[hidden]{display:none!important}
    .mgw-phase-b-launch-card{position:relative;z-index:1;box-sizing:border-box;width:min(100%,400px);height:336px;display:grid;grid-template-rows:30px 136px 52px 46px 24px;align-content:start;justify-items:center;padding:22px 20px 20px;border:1px solid rgba(255,255,255,.10);border-radius:26px;background:linear-gradient(180deg,rgba(18,21,32,.98),rgba(9,11,18,.98));box-shadow:0 24px 70px rgba(0,0,0,.44);overflow:hidden;contain:layout paint}
    .mgw-phase-b-launch-card:before{content:'';position:absolute;inset:0;background:linear-gradient(145deg,rgba(124,92,255,.07),transparent 42%,rgba(46,230,166,.035));pointer-events:none}
    .mgw-phase-b-launch-game{position:relative;z-index:2;grid-row:1;display:inline-flex;align-items:center;min-height:30px;padding:0 12px;border:1px solid rgba(255,255,255,.09);border-radius:999px;background:rgba(255,255,255,.045);font-size:12px;font-weight:850;letter-spacing:.02em;color:rgba(255,255,255,.76)}
    .mgw-phase-b-launch-visual{position:relative;z-index:2;grid-row:2;align-self:center;width:132px;height:132px;margin:0;display:grid;place-items:center}
    .mgw-phase-b-launch-ring{position:absolute;inset:5px;border:2px solid rgba(255,255,255,.09);border-top-color:#7c5cff;border-right-color:#2ee6a6;border-radius:50%;animation:mgwPhaseBSpin .9s linear infinite;will-change:transform}
    .mgw-phase-b-launch-ring:after{content:'';position:absolute;inset:8px;border:1px solid rgba(255,255,255,.055);border-radius:50%}
    .mgw-phase-b-countdown{position:absolute;z-index:2;left:50%;top:50%;width:108px;height:108px;transform:translate(-50%,-50%);display:grid;place-items:center;box-sizing:border-box;border:1px solid rgba(255,255,255,.12);border-radius:50%;background:radial-gradient(circle at 50% 35%,rgba(124,92,255,.12),transparent 55%),rgba(6,8,13,.96);box-shadow:0 16px 38px rgba(0,0,0,.38),inset 0 0 24px rgba(255,255,255,.025);color:#fff;font-size:64px;font-weight:950;line-height:1;letter-spacing:-.05em;text-shadow:0 5px 18px rgba(0,0,0,.5)}
    .mgw-phase-b-countdown[data-loading="1"]{font-size:24px;letter-spacing:.10em;color:rgba(255,255,255,.88)}
    .mgw-phase-b-countdown[data-ready="1"]{font-size:25px;letter-spacing:.08em}
    .mgw-phase-b-countdown[hidden]{display:none!important}
    .mgw-phase-b-launch-title{position:relative;z-index:2;grid-row:3;align-self:center;display:flex;align-items:center;justify-content:center;width:100%;height:52px;margin:0;white-space:nowrap;font-size:25px;font-weight:950;line-height:1.08;letter-spacing:-.04em}
    .mgw-phase-b-launch-note{position:relative;z-index:2;grid-row:4;align-self:center;display:flex;align-items:center;justify-content:center;width:100%;height:46px;margin:0;box-sizing:border-box;font-size:14px;line-height:1.35;color:rgba(255,255,255,.68)}
    .mgw-phase-b-launch-progress{position:relative;z-index:2;grid-row:5;align-self:center;display:flex;gap:6px;margin:0}
    .mgw-phase-b-launch-progress[data-visible="0"]{visibility:hidden}
    .mgw-phase-b-launch-progress i{display:block;width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.30);animation:mgwPhaseBDots 1s ease-in-out infinite}
    .mgw-phase-b-launch-progress i:nth-child(2){animation-delay:.12s}.mgw-phase-b-launch-progress i:nth-child(3){animation-delay:.24s}
    #gameBoard.mgw-phase-b-turn-wait{pointer-events:none}
    @keyframes mgwPhaseBSpin{to{transform:rotate(360deg)}}
    @keyframes mgwPhaseBDots{0%,100%{opacity:.28;transform:translateY(0)}50%{opacity:.9;transform:translateY(-2px)}}
    @media (prefers-reduced-motion:reduce){.mgw-phase-b-launch-ring,.mgw-phase-b-launch-progress i{animation:none}}
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
        <span class="mgw-phase-b-launch-ring"></span>
        <div class="mgw-phase-b-countdown" data-phase-b-countdown data-loading="1" data-ready="0">VS</div>
      </div>
      <strong class="mgw-phase-b-launch-title" data-phase-b-title>Матч скоро начнётся</strong>
      <span class="mgw-phase-b-launch-note" data-phase-b-note>Готовьтесь к игре</span>
      <div class="mgw-phase-b-launch-progress" data-phase-b-progress data-visible="1" aria-hidden="true"><i></i><i></i><i></i></div>
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
