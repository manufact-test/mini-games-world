import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { getInitData } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { enterGame } from './screens/game-screen-v102-safe.js?v=102';

const WATCH_URL = `${window.location.origin}/bot/game-watch.php`;
const WATCH_INTERVAL_MS = 250;
const FALLBACK_GAME_POLL_MS = 1500;
const VOLATILE_KEYS = new Set([
  'time_left',
  'server_now_ms',
  'turn_deadline_ms',
  '__mgw_v100_pending_action',
]);
const CLOCK_PROJECTION_KEYS = [
  'turn',
  'turn_revision',
  'clock_revision',
  'turn_started_at',
  'turn_starts_at_ms',
  'turn_deadline_ms',
  'server_now_ms',
  'time_left',
  'move_timeout_sec',
];

const runtime = window.__MGW_V110_READONLY_GAME_SYNC__ ||= {
  initialized:false,
  timer:null,
  busy:false,
};

export function initV110ReadonlyGameSync(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  // Full game_state stays the authoritative readiness/session/cleanup fallback.
  // Frequent cross-device freshness reads only games.json and never takes the
  // global write transaction lock, including Phase B preparation/countdown.
  APP_CONFIG.gameIntervalMs = Math.max(Number(APP_CONFIG.gameIntervalMs || 0), FALLBACK_GAME_POLL_MS);

  document.addEventListener('mgw:app-ready', () => scheduleWatch(0), { once:true });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') scheduleWatch(0);
  });
  document.addEventListener('mgw:game-finished', () => scheduleWatch(WATCH_INTERVAL_MS));
  document.addEventListener('mgw:game-dismissed', () => scheduleWatch(WATCH_INTERVAL_MS));
  scheduleWatch(0);
}

function scheduleWatch(delay = WATCH_INTERVAL_MS){
  window.clearTimeout(runtime.timer);
  runtime.timer = window.setTimeout(async () => {
    await watchCurrentGame();
    scheduleWatch(WATCH_INTERVAL_MS);
  }, Math.max(0, Number(delay || 0)));
}

async function watchCurrentGame(){
  const local = state.activeGame;
  const gameId = String(local?.id || '');
  const item = gameRuntimeItem(gameId);
  const pendingClockOnly = canWatchPendingTicTacToeClock(local, item, gameId);
  const busyTerminalOnly = canWatchBusyTicTacToeTerminal(local, item, gameId);
  const allowBusyRead = pendingClockOnly || busyTerminalOnly;
  if (!canWatch(local, gameId, allowBusyRead) || runtime.busy) return null;
  if (actionIsBusy(item) && !allowBusyRead) return null;

  runtime.busy = true;
  try {
    const speed = window.__MGW_V101_SPEED__;
    const fetcher = typeof speed?.rawFetch === 'function'
      ? speed.rawFetch
      : window.fetch.bind(window);
    const response = await fetcher(WATCH_URL, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      body:JSON.stringify({ initData:getInitData(), sessionId:getSessionId(), gameId }),
      priority:'high',
      cache:'no-store',
    });
    const result = await response.json().catch(() => null);
    if (!response.ok || !result || result.ok === false) return null;

    const game = result.game || null;
    if (!game?.id || String(game.id) !== gameId) return null;

    const currentItem = gameRuntimeItem(gameId);
    const currentPendingClockOnly = canWatchPendingTicTacToeClock(state.activeGame, currentItem, gameId);
    const currentBusyTerminalOnly = canWatchBusyTicTacToeTerminal(state.activeGame, currentItem, gameId);
    const currentAllowBusyRead = currentPendingClockOnly || currentBusyTerminalOnly;
    if (!canWatch(state.activeGame, gameId, currentAllowBusyRead)) return null;

    // A server-committed terminal state outranks a local in-flight action. The
    // existing game screen remains the only result owner; this read-only watcher
    // merely delivers the already-published finished snapshot immediately.
    if (String(game.status || '') === 'finished') {
      enterGame(game, result.me || null);
      return game;
    }

    if (actionIsBusy(currentItem)) {
      if (currentPendingClockOnly) {
        // Preserve only the authoritative handoff clock in the shared active
        // state before the local pending action is cleared. This closes the
        // rare race where the acceptance runtime correctly received the new
        // 60-second deadline, then fell back to the previous turn because the
        // main action response had not projected the same clock yet. Board and
        // result fields remain exclusively owned by game-screen-v102.
        adoptClockProjection(game);
        document.dispatchEvent(new CustomEvent('mgw:v110-ttt-clock-snapshot', {
          detail:{ game },
        }));
      }
      // During a local TTT action the same watcher may either carry the clock
      // handoff or observe a terminal server snapshot. It never projects an
      // intermediate board while the action owner is still busy.
      return game;
    }

    if (projectionKey(state.activeGame) === projectionKey(game)) return game;

    // Existing game-screen-v102 stays the only board/result owner. During
    // preparation this call only projects the latest server state; readiness and
    // phase advancement remain owned by authoritative game_state/action paths.
    enterGame(game, result.me || null);
    return game;
  } catch (error) {
    return null;
  } finally {
    runtime.busy = false;
  }
}

function adoptClockProjection(game){
  const active = state.activeGame;
  if (!active?.id || String(active.id) !== String(game?.id || '')) return;
  for (const key of CLOCK_PROJECTION_KEYS) {
    if (Object.prototype.hasOwnProperty.call(game, key)) active[key] = game[key];
  }
}

function gameRuntimeItem(gameId){
  return window.__MGW_V100_GAME_RUNTIME__?.games?.get?.(gameId) || null;
}

function actionIsBusy(item){
  return Boolean(item?.running || item?.surrenderPending || Number(item?.queue?.length || 0) > 0);
}

function canWatchPendingTicTacToeClock(game, item, gameId){
  if (!gameId || !item || item.surrenderPending) return false;
  if (!item.running && Number(item?.queue?.length || 0) === 0) return false;
  const type = String(game?.game_type || game?.type || state.selectedGame || '');
  if (type !== 'tictactoe') return false;
  const pending = window.__MGW_V110_ACCEPTANCE__?.pending || null;
  return String(pending?.gameId || '') === gameId;
}

function canWatchBusyTicTacToeTerminal(game, item, gameId){
  if (!gameId || !item || item.surrenderPending || !actionIsBusy(item)) return false;
  const type = String(game?.game_type || game?.type || state.selectedGame || '');
  return type === 'tictactoe';
}

function canWatch(game, gameId, allowBusyRead = false){
  if (!gameId || String(game?.status || '') !== 'active') return false;
  const launchPhase = String(game?.launch_phase || '');
  if (launchPhase && !['preparing', 'countdown', 'active'].includes(launchPhase)) return false;
  // Bot games do not need the normal high-frequency board watcher, but a local
  // pending Tic-Tac-Toe action may still need a clock handoff or terminal state.
  if (game?.is_bot_game && !allowBusyRead) return false;
  if (document.visibilityState !== 'visible') return false;
  const screen = document.querySelector('.screen.active');
  return String(screen?.dataset.screen || '') === 'game';
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
