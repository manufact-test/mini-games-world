import { APP_CONFIG } from '../config.js?v=38';
import {
  initGameScreen,
  enterGame as enterBaseGame,
  startGamePolling,
  clearGameView,
} from './game-screen-v102.js?v=102';

const PREACTIVE_POLL_MS = 400;
const phasePollRuntime = window.__MGW_PHASE_B_POLL__ ||= { fastGameId:'' };
const runtime = window.__MGW_V100_GAME_RUNTIME__ ||= {
  initialized:false,
  games:new Map(),
  pointerHoldUntil:0,
  resultOpened:new Set(),
  weeklyNotified:new Set(),
};

export { initGameScreen, startGamePolling, clearGameView };

export function enterGame(game, me = null){
  const id = String(game?.id || '');
  const item = id ? runtime.games.get(id) : null;

  /* Repeated invite/search snapshots must not reset a local action, a queued
   * Battleship setup change or a surrender that is already being confirmed. */
  if (item?.running || item?.surrenderPending || Number(item?.queue?.length || 0) > 0) return;

  const phase = String(game?.launch_phase || '');
  const fastLaunch = String(game?.status || '') === 'active'
    && (phase === 'preparing' || phase === 'countdown');
  const acceptedInterval = Number(APP_CONFIG.gameIntervalMs || 1500);

  if (fastLaunch) {
    APP_CONFIG.gameIntervalMs = Math.min(acceptedInterval, PREACTIVE_POLL_MS);
  }
  try {
    enterBaseGame(game, me);
  } finally {
    APP_CONFIG.gameIntervalMs = acceptedInterval;
  }

  if (fastLaunch && id) {
    phasePollRuntime.fastGameId = id;
  } else if (phasePollRuntime.fastGameId === id) {
    phasePollRuntime.fastGameId = '';
  }
}

export function restoreAcceptedGamePolling(game = null){
  const id = String(game?.id || '');
  if (!id || phasePollRuntime.fastGameId !== id) return false;

  const status = String(game?.status || '');
  const phase = String(game?.launch_phase || '');
  if (status === 'active' && (phase === 'preparing' || phase === 'countdown')) {
    return false;
  }

  phasePollRuntime.fastGameId = '';
  if (status === 'active') startGamePolling(id);
  return true;
}
