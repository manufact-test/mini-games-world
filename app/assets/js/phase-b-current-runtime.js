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
  if (title) title.textContent = 'Подготовка матча';

  if (phase === 'countdown') {
    const remaining = sharedStartRemainingMs(game);
    const readyForServer = remaining <= 0;
    const seconds = Math.max(1, Math.min(3, Math.ceil(remaining / 1000)));
    if (note) note.textContent = readyForServer ? 'Открываем игру' : 'Начинаем одновременно';
    if (countdown) {
      countdown.hidden = false;
      countdown.dataset.ready = readyForServer ? '1' : '0';
      countdown.textContent = readyForServer ? 'СТАРТ' : String(seconds);
    }
    if (progress) progress.hidden = true;
    return;
  }

  if (countdown) {
    countdown.hidden = true;
    countdown.dataset.ready = '0';
  }
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
    .mgw-phase-b-launch-overlay{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;padding:max(24px,env(safe-area-inset-top)) 22px max(24px,env(safe-area-inset-bottom));overflow:hidden;background:#080a10;text-align:center;isolation:isolate}
    .mgw-phase-b-launch-overlay:before{content:'';position:absolute;z-index:0;top:0;bottom:0;left:50%;width:min(100%,430px);transform:translateX(-50%);background-color:#080a10;background-image:linear-gradient(rgba(255,255,255,.035) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.035) 1px,transparent 1px),radial-gradient(circle at 50% 46%,rgba(124,92,255,.18),transparent 44%);background-size:28px 28px,28px 28px,auto;box-shadow:0 0 90px rgba(124,92,255,.12);pointer-events:none}
    .mgw-phase-b-launch-overlay[hidden]{display:none!important}
    .mgw-phase-b-launch-card{position:relative;z-index:1;box-sizing:border-box;width:min(100%,360px);height:380px;display:grid;grid-template-rows:30px 150px 54px 48px 28px;align-content:start;justify-items:center;padding:26px 22px 22px;border:1px solid rgba(255,255,255,.11);border-radius:28px;background:linear-gradient(180deg,rgba(20,24,37,.97),rgba(10,13,22,.97));box-shadow:0 24px 64px rgba(0,0,0,.42);overflow:hidden}
    .mgw-phase-b-launch-card:before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(124,92,255,.08),transparent 38%,rgba(46,230,166,.045));pointer-events:none}
    .mgw-phase-b-launch-game{position:relative;z-index:1;grid-row:1;display:inline-flex;align-items:center;min-height:30px;padding:0 12px;border:1px solid rgba(255,255,255,.1);border-radius:999px;background:rgba(255,255,255,.055);font-size:12px;font-weight:850;letter-spacing:.02em;color:rgba(255,255,255,.8)}
    .mgw-phase-b-launch-visual{position:relative;z-index:1;grid-row:2;align-self:center;width:156px;height:108px;margin:0}
    .mgw-phase-b-launch-shape{position:absolute;border:1px solid rgba(255,255,255,.16);box-shadow:0 14px 34px rgba(0,0,0,.24);animation:mgwPhaseBFloat 2.6s ease-in-out infinite}
    .mgw-phase-b-launch-shape.one{width:54px;height:54px;left:13px;top:25px;border-radius:18px;transform:rotate(7deg);background:linear-gradient(135deg,rgba(124,92,255,.95),rgba(91,70,219,.62))}
    .mgw-phase-b-launch-shape.two{width:54px;height:54px;right:13px;top:25px;border-radius:50%;animation-delay:.22s;background:linear-gradient(135deg,rgba(46,230,166,.92),rgba(27,154,124,.58))}
    .mgw-phase-b-launch-shape.three{width:58px;height:3px;left:49px;top:51px;border:0;border-radius:999px;animation:none;background:linear-gradient(90deg,rgba(124,92,255,.5),rgba(255,255,255,.78),rgba(46,230,166,.5));box-shadow:none}
    .mgw-phase-b-launch-dot{position:absolute;width:7px;height:7px;border-radius:50%;background:#fff;opacity:.72;animation:mgwPhaseBPulse 1.6s ease-in-out infinite}
    .mgw-phase-b-launch-dot.one{left:72px;top:49px}.mgw-phase-b-launch-dot.two{left:58px;top:49px;animation-delay:.3s}.mgw-phase-b-launch-dot.three{right:58px;top:49px;animation-delay:.6s}
    .mgw-phase-b-countdown{position:absolute;z-index:2;left:50%;top:50%;width:88px;height:88px;transform:translate(-50%,-50%);display:grid;place-items:center;box-sizing:border-box;border:1px solid rgba(255,255,255,.16);border-radius:24px;background:rgba(5,7,12,.9);box-shadow:0 14px 34px rgba(0,0,0,.4);color:#fff;font-size:70px;font-weight:950;line-height:1;letter-spacing:-.06em;text-shadow:0 5px 18px rgba(0,0,0,.55)}
    .mgw-phase-b-countdown[data-ready="1"]{width:116px;height:58px;border-radius:18px;font-size:28px;letter-spacing:.08em}
    .mgw-phase-b-countdown[hidden]{display:none!important}
    .mgw-phase-b-launch-title{position:relative;z-index:1;grid-row:3;align-self:center;display:flex;align-items:center;justify-content:center;width:100%;height:54px;margin:0;font-size:26px;font-weight:950;line-height:1.08;letter-spacing:-.045em}
    .mgw-phase-b-launch-note{position:relative;z-index:1;grid-row:4;align-self:start;display:flex;align-items:flex-start;justify-content:center;width:100%;height:48px;margin:0;padding-top:7px;box-sizing:border-box;max-width:280px;font-size:14px;line-height:1.45;color:rgba(255,255,255,.72)}
    .mgw-phase-b-launch-progress{position:relative;z-index:1;grid-row:5;align-self:center;display:flex;gap:7px;margin:0}
    .mgw-phase-b-launch-progress i{display:block;width:7px;height:7px;border-radius:50%;background:rgba(255,255,255,.28);animation:mgwPhaseBDots 1.15s ease-in-out infinite}
    .mgw-phase-b-launch-progress i:nth-child(2){animation-delay:.15s}.mgw-phase-b-launch-progress i:nth-child(3){animation-delay:.3s}
    #gameBoard.mgw-phase-b-turn-wait{pointer-events:none}
    @keyframes mgwPhaseBFloat{0%,100%{translate:0 0}50%{translate:0 -7px}}
    @keyframes mgwPhaseBPulse{0%,100%{opacity:.28;transform:scale(.88)}50%{opacity:1;transform:scale(1.18)}}
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
        <div class="mgw-phase-b-countdown" data-phase-b-countdown data-ready="0" hidden></div>
      </div>
      <strong class="mgw-phase-b-launch-title" data-phase-b-title>Подготовка матча</strong>
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
