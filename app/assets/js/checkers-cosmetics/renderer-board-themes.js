import {
  renderCheckersSurface as renderBaseCheckersSurface,
  checkersMeta,
  checkersPlayerMark,
  checkersStatus,
} from '../games/checkers/renderer.js?v=57&base=mvp16-accepted';

ensureCheckersCosmeticStyles();

export { checkersMeta, checkersPlayerMark, checkersStatus };

export function renderCheckersSurface({ game, me, container, onAction }){
  renderBaseCheckersSurface({ game, me, container, onAction });
  container.dataset.checkersTheme = checkersBoardVariant(game, me);
}

function checkersBoardVariant(game, me){
  const players = Array.isArray(game?.players) ? game.players : [];
  const viewer = players.find(player => String(player?.id || '') === String(me?.id || '')) || null;
  const slots = viewer?.game_cosmetics?.slots;
  const itemId = slots && typeof slots === 'object' ? String(slots.game_checkers_theme || '') : '';
  const marker = 'game-checkers-board-';
  return itemId.startsWith(marker) ? itemId.slice(marker.length) : 'base';
}

function ensureCheckersCosmeticStyles(){
  if (document.querySelector('link[data-mgw-checkers-cosmetics]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersCosmetics = 'mvp19-6-live-boards';
  link.href = new URL('../../css/games/checkers/cosmetics.css?v=1&mvp19_6=board-themes', import.meta.url).href;
  document.head.appendChild(link);
}
