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
   * The live effect owner computes from/to from real 8x8 cell rects. The runtime
   * corrective now makes those rects structurally stable by defining eight equal
   * grid rows as well as the accepted eight columns, so checker content can no
   * longer resize a row between optimistic arrival and authoritative handoff.
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
  const href = new URL('../../css/games/checkers/runtime-handoff-mobile-v1.css?v=3&mvp19_6=equal-grid-rows&landing=stable-row-centers-v1&last_from=flat-v1&mobile=insets-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-checkers-runtime-corrective]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwCheckersRuntimeCorrective = 'mvp19-6-equal-grid-rows-v3';
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersRuntimeCorrective = 'mvp19-6-equal-grid-rows-v3';
  link.href = href;
  document.head.appendChild(link);
}
