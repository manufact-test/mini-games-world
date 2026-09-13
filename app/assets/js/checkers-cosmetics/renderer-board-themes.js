import {
  renderCheckersSurface as renderBaseCheckersSurface,
  checkersMeta,
  checkersPlayerMark,
  checkersStatus,
} from '../games/checkers/renderer.js?v=57&base=mvp16-accepted';

ensureCheckersCosmeticStyles();
ensureCheckersRuntimeCorrectiveStyles();

export { checkersMeta, checkersPlayerMark, checkersStatus };

export function renderCheckersSurface({ game, me, container, onAction }){
  renderBaseCheckersSurface({ game, me, container, onAction });
  container.dataset.checkersTheme = checkersBoardVariant(game, me);
  container.dataset.mgwCheckersPaidEffect = viewerHasPaidCheckersEffect(game, me) ? '1' : '0';

  /*
   * Do not retarget the paid checker to a temporary destination-piece rect here.
   * The live effect owner already computes from/to from the real 8x8 cell rects.
   * Those cell centers are stable across optimistic -> authoritative rerenders,
   * while the destination piece can transiently carry selection/impact state.
   * Re-reading that temporary piece geometry was the source of the visible
   * ~4px arrival -> settled correction seen in Telegram QA.
   */
}

function checkersBoardVariant(game, me){
  const players = Array.isArray(game?.players) ? game.players : [];
  const viewer = players.find(player => String(player?.id || '') === String(me?.id || '')) || null;
  const slots = viewer?.game_cosmetics?.slots;
  const itemId = slots && typeof slots === 'object' ? String(slots.game_checkers_theme || '') : '';
  const marker = 'game-checkers-board-';
  return itemId.startsWith(marker) ? itemId.slice(marker.length) : 'base';
}

function viewerHasPaidCheckersEffect(game, me){
  const players = Array.isArray(game?.players) ? game.players : [];
  const viewer = players.find(player => String(player?.id || '') === String(me?.id || '')) || null;
  const slots = viewer?.game_cosmetics?.slots;
  const effectId = slots && typeof slots === 'object' ? String(slots.game_checkers_effect || '') : '';
  return [
    'game-checkers-effect-move',
    'game-checkers-effect-capture',
    'game-checkers-effect-promotion',
  ].includes(effectId);
}

function ensureCheckersCosmeticStyles(){
  if (document.querySelector('link[data-mgw-checkers-cosmetics]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersCosmetics = 'mvp19-6-live-boards';
  link.href = new URL('../../css/games/checkers/cosmetics.css?v=1&mvp19_6=board-themes', import.meta.url).href;
  document.head.appendChild(link);
}

function ensureCheckersRuntimeCorrectiveStyles(){
  if (document.querySelector('link[data-mgw-checkers-runtime-corrective]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersRuntimeCorrective = 'mvp19-6-cell-center-handoff-v2';
  link.href = new URL('../../css/games/checkers/runtime-handoff-mobile-v1.css?v=2&mvp19_6=cell-center-handoff&last_from=flat-v1&mobile=insets-v1', import.meta.url).href;
  document.head.appendChild(link);
}
