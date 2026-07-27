import {
  initGameScreen,
  enterGame as enterBaseGame,
  startGamePolling,
  clearGameView,
} from './game-screen-v100.js?v=100';

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

  /* Invitation/search sync may repeat an already-open game snapshot. It must
   * never reset the queue or optimistic board while the local action request
   * is still running. The canonical action response performs reconciliation. */
  if (item?.running || Number(item?.queue?.length || 0) > 0) return;

  enterBaseGame(game, me);
}
