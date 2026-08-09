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

  /* Repeated invite/search/watch snapshots must not reset a local action, a
   * queued Battleship setup change or a surrender already being confirmed. */
  if (item?.running || item?.surrenderPending || Number(item?.queue?.length || 0) > 0) return;

  const phase = String(game?.launch_phase || '');
  const preStart = String(game?.status || '') === 'active'
    && (phase === 'preparing' || phase === 'countdown' || phase === 'preparation_timeout');

  /* The launch view is a global application layer owned by the Phase B runtime.
   * Prime it synchronously before the game screen is rendered so neither player
   * ever sees a half-loaded board or preparation copy inside the board itself. */
  if (preStart) {
    document.dispatchEvent(new CustomEvent('mgw:phase-b-game-entering', {
      detail:{ game },
    }));
  }

  /* Full game_state remains the authoritative readiness/session fallback at the
   * accepted cadence. Fast pre-start freshness is read-only and is owned by
   * production-v110-readonly-game-sync.js, so preparation never hammers the
   * write transaction lock every 400ms. */
  enterBaseGame(game, me);
}
