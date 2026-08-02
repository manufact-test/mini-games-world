import { startGamePolling } from './screens/game-screen.js?v=74';

/* The v97 runtime calls this bridge only after it has invalidated the search epoch. */
window.__MGW_V97_START_GAME_POLLING__ = startGamePolling;
