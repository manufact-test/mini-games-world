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

document.addEventListener('mgw:v110-ttt-clock-snapshot', event => {
  const game = event?.detail?.game || null;
  if (String(game?.status || '') === 'finished') enterGame(game);
});

export { initGameScreen, startGamePolling, clearGameView };

export function enterGame(game, me = null){
  const id = String(game?.id || '');
  const item = id ? runtime.games.get(id) : null;
  const terminal = String(game?.status || '') === 'finished';
  if (!terminal && (item?.running || item?.surrenderPending || Number(item?.queue?.length || 0) > 0)) return;

  const phase = String(game?.launch_phase || '');
  const preStart = String(game?.status || '') === 'active'
    && (phase === 'preparing' || phase === 'countdown' || phase === 'preparation_timeout');
  if (preStart) {
    document.dispatchEvent(new CustomEvent('mgw:phase-b-game-entering', {
      detail:{ game },
    }));
  }
  enterBaseGame(game, me);
}
