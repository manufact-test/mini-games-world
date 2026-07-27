import { startGamePolling } from './screens/game-screen.js?v=74';

window.__MGW_V97_START_GAME_POLLING__ = startGamePolling;

document.addEventListener('mgw:v97-game-found', event => {
  const gameId = String(event.detail?.gameId || '');
  if (gameId) startGamePolling(gameId);
});
