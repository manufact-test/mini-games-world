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

  queueMicrotask(() => {
    // The paid mover is a detached fixed overlay while the authoritative
    // checker already exists (hidden) in the destination cell. Measure that
    // real destination checker after the live owner has rendered and bind the
    // overlay endpoint + size to its exact DOM geometry.
    //
    // IMPORTANT: lock this geometry exactly once per live overlay. Recomputing
    // the CSS custom properties on every poll/render can move an animation that
    // has already reached 100%, creating the visible final "hop-hop" correction.
    syncExactLiveLanding(container);
  });
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

function syncExactLiveLanding(container){
  if (!(container instanceof HTMLElement)) return;

  const layer = [...document.querySelectorAll('.mgw-checkers-live-fx')].at(-1) || null;
  if (!(layer instanceof HTMLElement)) return;
  if (layer.dataset.mgwCheckersLandingLocked === '1') return;

  const destinationPiece = container.querySelector(
    '.checkers-cell.mgw-checkers-live-fx-hide-piece .checkers-piece',
  );
  const movingPiece = layer.querySelector('.mgw-checkers-live-fx-piece');
  if (!(destinationPiece instanceof HTMLElement) || !(movingPiece instanceof HTMLElement)) return;

  const layerRect = layer.getBoundingClientRect();
  const pieceRect = destinationPiece.getBoundingClientRect();
  const exactSize = stableLayoutPieceSize(destinationPiece);
  if (layerRect.width <= 0 || layerRect.height <= 0 || pieceRect.width <= 0 || pieceRect.height <= 0 || exactSize === null) return;

  const fromX = numericCssPx(layer, '--mgw-fx-from-x');
  const fromY = numericCssPx(layer, '--mgw-fx-from-y');
  if (fromX === null || fromY === null) return;

  // getBoundingClientRect() intentionally remains the center owner because every
  // accepted Checkers scale animation is center-origin. Size is different: rect
  // width/height INCLUDE transform scale (selected 1.08, move-impact, promotion),
  // which can make the detached paid piece physically oversized and then appear
  // to shrink from one edge at handoff. Read the untransformed layout box instead.
  const exactX = pieceRect.left - layerRect.left + pieceRect.width / 2;
  const exactY = pieceRect.top - layerRect.top + pieceRect.height / 2;

  layer.style.setProperty('--mgw-fx-dx', `${exactX - fromX}px`);
  layer.style.setProperty('--mgw-fx-dy', `${exactY - fromY}px`);
  layer.style.setProperty('--mgw-fx-to-x', `${exactX}px`);
  layer.style.setProperty('--mgw-fx-to-y', `${exactY}px`);
  layer.style.setProperty('--mgw-fx-piece-size', `${exactSize}px`);

  if (!layer.classList.contains('mgw-checkers-live-fx-capture')) {
    const impact = layer.querySelector('.mgw-checkers-live-fx-impact');
    if (impact instanceof HTMLElement) {
      impact.style.left = `${exactX}px`;
      impact.style.top = `${exactY}px`;
    }
  }

  const crown = layer.querySelector('.mgw-checkers-live-fx-crown');
  if (crown instanceof HTMLElement) {
    crown.style.left = `${exactX}px`;
    crown.style.top = `${exactY}px`;
  }

  // Freeze the compositor endpoint after the first successful DOM measurement.
  // Later polling may rebuild the board, but it must never retarget an animation
  // that is already sitting on its destination.
  layer.dataset.mgwCheckersLandingLocked = '1';
  layer.dataset.mgwCheckersLandingX = exactX.toFixed(3);
  layer.dataset.mgwCheckersLandingY = exactY.toFixed(3);
  layer.dataset.mgwCheckersLandingSize = exactSize.toFixed(3);
  layer.dataset.mgwCheckersLandingSizeSource = 'layout-box';
}

function stableLayoutPieceSize(piece){
  if (!(piece instanceof HTMLElement)) return null;
  const style = getComputedStyle(piece);
  const width = Number.parseFloat(style.width);
  const height = Number.parseFloat(style.height);
  if (Number.isFinite(width) && width > 0 && Number.isFinite(height) && height > 0) {
    return Math.min(width, height);
  }
  const fallback = Math.min(piece.offsetWidth, piece.offsetHeight);
  return Number.isFinite(fallback) && fallback > 0 ? fallback : null;
}

function numericCssPx(element, property){
  const numeric = Number.parseFloat(element.style.getPropertyValue(property));
  return Number.isFinite(numeric) ? numeric : null;
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
  link.dataset.mgwCheckersRuntimeCorrective = 'mvp19-6-selection-geometry-mobile-v1';
  link.href = new URL('../../css/games/checkers/runtime-handoff-mobile-v1.css?v=1&mvp19_6=selection-geometry-neutral&mobile=insets-v1', import.meta.url).href;
  document.head.appendChild(link);
}
