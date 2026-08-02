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

const runtime = window.__MGW_V110_READONLY_GAME_SYNC__ ||= {
  initialized:false,
  timer:null,
  busy:false,
};

export function initV110ReadonlyGameSync(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  // Full game_state remains the authoritative session/cleanup fallback. The
  // frequent PvP freshness path reads only games.json and never app.lock.
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
  if (!canWatch(local, gameId) || runtime.busy) return null;

  const item = gameRuntimeItem(gameId);
  if (actionIsBusy(item)) return null;

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
    if (!canWatch(state.activeGame, gameId) || actionIsBusy(gameRuntimeItem(gameId))) return null;
    if (projectionKey(state.activeGame) === projectionKey(game)) return game;

    // Existing game-screen-v102 stays the only board and result owner.
    enterGame(game, result.me || null);
    return game;
  } catch (error) {
    return null;
  } finally {
    runtime.busy = false;
  }
}

function gameRuntimeItem(gameId){
  return window.__MGW_V100_GAME_RUNTIME__?.games?.get?.(gameId) || null;
}

function actionIsBusy(item){
  return Boolean(item?.running || item?.surrenderPending || Number(item?.queue?.length || 0) > 0);
}

function canWatch(game, gameId){
  if (!gameId || String(game?.status || '') !== 'active') return false;
  if (game?.is_bot_game) return false;
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
