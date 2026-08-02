import {
  initGameScreen,
  enterGame as enterBaseGame,
  startGamePolling,
  clearGameView,
} from './game-screen-v102.js?v=102';

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

  enterBaseGame(game, me);
}
