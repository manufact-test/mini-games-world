import { state } from './state.js?v=27';
import { getInitData } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { renderBalances } from './ui.js?v=89';
import { enterGame } from './screens/game-screen-v102-safe.js?v=102';

const LIVE_URL = `${window.location.origin}/bot/game-live-v108.php`;
const SYNC_MS = 250;
const TICK_MS = 80;
const runtime = window.__MGW_V108_LIVE_GAME__ ||= {
  initialized:false,
  syncTimer:null,
  tickTimer:null,
  syncBusy:false,
  gameId:'',
  clockKey:'',
  deadlineMs:0,
  serverNowMs:0,
  receivedAt:0,
  timeoutSec:60,
  waiting:false,
  lastDrawn:null,
  repairedStarted:new Set(),
  finished:new Set(),
};

export function initV108LiveGame(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  ensureTimerSurface();
  runtime.tickTimer = window.setInterval(drawClock, TICK_MS);
  runtime.syncTimer = window.setInterval(syncLiveGame, SYNC_MS);
  document.addEventListener('mgw:app-ready', () => void syncLiveGame(), { once:true });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') void syncLiveGame();
  });
}

async function syncLiveGame(){
  if (runtime.syncBusy || document.visibilityState !== 'visible') return;
  const current = state.activeGame;
  const gameId = String(current?.id || '');
  if (!gameId) {
    resetClock();
    return;
  }

  runtime.syncBusy = true;
  try {
    const response = await fetch(LIVE_URL, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({
        initData:getInitData(),
        sessionId:getSessionId(),
        gameId,
      }),
      priority:'high',
    });
    const result = await response.json().catch(() => null);
    if (!response.ok || !result || result.ok === false || !result.game?.id) return;
    if (String(state.activeGame?.id || '') !== gameId) return;

    if (result.user) {
      state.user = result.user;
      renderBalances(state.user);
    }
    if (result.session) state.session = result.session;

    const game = result.game;
    const me = result.me || null;
    if (String(game.status || '') === 'finished') {
      applyFinishedGame(game, me);
      return;
    }
    if (String(game.status || '') !== 'active' || String(game.game_type || '') !== 'tictactoe') {
      showCoreTimer();
      return;
    }

    const wasWaiting = runtime.waiting;
    runtime.gameId = String(game.id || '');
    runtime.waiting = Boolean(game.clock_waiting_for_players);
    runtime.timeoutSec = Math.max(1, Number(game.move_timeout_sec || 60));
    runtime.serverNowMs = Number(game.server_now_ms || result.server_now_ms || Date.now());
    runtime.receivedAt = Date.now();
    runtime.deadlineMs = Number(game.turn_deadline_ms || 0);
    runtime.clockKey = `${runtime.gameId}|${String(game.turn || '')}|${runtime.deadlineMs}`;
    runtime.lastDrawn = null;

    const item = window.__MGW_V100_GAME_RUNTIME__?.games?.get?.(runtime.gameId);
    const pending = Boolean(item?.running || item?.surrenderPending || Number(item?.queue?.length || 0) > 0);
    const board = document.getElementById('gameBoard');
    const needsRepair = !board?.dataset.gameType
      || board.childElementCount === 0
      || board.classList.contains('is-pending-launch')
      || Boolean(document.querySelector('#sheet [data-invite-sheet]'));

    if (!pending && (needsRepair || (wasWaiting && !runtime.waiting))) {
      enterGame(game, me);
      runtime.repairedStarted.add(runtime.gameId);
    } else {
      state.activeGame = {
        ...state.activeGame,
        time_left:game.time_left,
        move_timeout_sec:game.move_timeout_sec,
        turn_started_at:game.turn_started_at,
        turn_deadline_ms:game.turn_deadline_ms,
        server_now_ms:game.server_now_ms,
        clock_waiting_for_players:game.clock_waiting_for_players,
      };
    }

    applyWaitingLock(game, runtime.waiting);
    drawClock();
  } catch (error) {
    // Core game polling remains the fallback during a temporary live-sync error.
  } finally {
    runtime.syncBusy = false;
  }
}

function applyFinishedGame(game, me){
  const id = String(game.id || '');
  if (!id || runtime.finished.has(id)) return;
  runtime.finished.add(id);
  runtime.waiting = false;
  runtime.deadlineMs = 0;

  const item = window.__MGW_V100_GAME_RUNTIME__?.games?.get?.(id);
  if (item) {
    item.authoritative = clone(game);
    item.optimistic = clone(game);
    item.viewer = me || item.viewer;
  }
  state.activeGame = game;

  document.dispatchEvent(new CustomEvent('mgw:v101-finished-response', {
    detail:{game, me, source:'v108-live'},
  }));
}

function applyWaitingLock(game, waiting){
  const board = document.getElementById('gameBoard');
  if (!board || String(game.game_type || '') !== 'tictactoe') return;
  board.classList.toggle('mgw-v108-clock-waiting', waiting);

  if (waiting) {
    board.querySelectorAll('[data-game-cell]').forEach(cell => {
      if (cell instanceof HTMLButtonElement) cell.disabled = true;
    });
    const turn = document.getElementById('turnText');
    if (turn) turn.textContent = 'Ждём подключения соперника';
  }
}

function drawClock(){
  const game = state.activeGame;
  const activeTicTacToe = Boolean(game?.id)
    && String(game.status || '') === 'active'
    && String(game.game_type || '') === 'tictactoe';
  const target = ensureTimerSurface();
  const core = document.getElementById('timerText');
  const old = document.getElementById('timerTextV107');

  if (!activeTicTacToe) {
    if (target) target.hidden = true;
    if (core) core.hidden = false;
    if (old) old.hidden = true;
    return;
  }

  if (core) core.hidden = true;
  if (old) old.hidden = true;
  if (target) target.hidden = false;
  if (!target) return;

  let remaining = runtime.timeoutSec;
  if (!runtime.waiting && runtime.deadlineMs > 0 && runtime.receivedAt > 0) {
    const estimatedServerNow = runtime.serverNowMs + (Date.now() - runtime.receivedAt);
    remaining = Math.max(0, Math.min(runtime.timeoutSec, Math.ceil((runtime.deadlineMs - estimatedServerNow) / 1000)));
  }

  if (runtime.lastDrawn !== remaining) {
    target.textContent = `${remaining} сек`;
    runtime.lastDrawn = remaining;
  }
}

function ensureTimerSurface(){
  const core = document.getElementById('timerText');
  if (!core?.parentElement) return document.getElementById('timerTextV108');
  let target = document.getElementById('timerTextV108');
  if (target) return target;

  target = document.createElement(core.tagName.toLowerCase());
  target.id = 'timerTextV108';
  target.className = core.className;
  target.setAttribute('aria-live', 'off');
  target.textContent = '60 сек';
  target.hidden = true;
  core.insertAdjacentElement('afterend', target);
  return target;
}

function showCoreTimer(){
  const core = document.getElementById('timerText');
  const target = document.getElementById('timerTextV108');
  if (core) core.hidden = false;
  if (target) target.hidden = true;
}

function resetClock(){
  runtime.gameId = '';
  runtime.clockKey = '';
  runtime.deadlineMs = 0;
  runtime.receivedAt = 0;
  runtime.waiting = false;
  runtime.lastDrawn = null;
  showCoreTimer();
}

function clone(value){
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}
