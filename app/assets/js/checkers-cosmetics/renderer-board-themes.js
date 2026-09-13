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

  queueMicrotask(() => {
    // The paid mover is a detached fixed overlay while the authoritative
    // checker already exists (hidden) in the destination cell. Measure that
    // real destination checker after the live owner has rendered and bind the
    // overlay endpoint + size to its exact DOM geometry.
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

function syncExactLiveLanding(container){
  if (!(container instanceof HTMLElement)) return;

  const layer = [...document.querySelectorAll('.mgw-checkers-live-fx')].at(-1) || null;
  if (!(layer instanceof HTMLElement)) return;

  const destinationPiece = container.querySelector(
    '.checkers-cell.last-to.mgw-checkers-live-fx-hide-piece .checkers-piece',
  );
  const movingPiece = layer.querySelector('.mgw-checkers-live-fx-piece');
  if (!(destinationPiece instanceof HTMLElement) || !(movingPiece instanceof HTMLElement)) return;

  const layerRect = layer.getBoundingClientRect();
  const pieceRect = destinationPiece.getBoundingClientRect();
  if (layerRect.width <= 0 || layerRect.height <= 0 || pieceRect.width <= 0 || pieceRect.height <= 0) return;

  const fromX = numericCssPx(layer, '--mgw-fx-from-x');
  const fromY = numericCssPx(layer, '--mgw-fx-from-y');
  if (fromX === null || fromY === null) return;

  const exactX = pieceRect.left - layerRect.left + pieceRect.width / 2;
  const exactY = pieceRect.top - layerRect.top + pieceRect.height / 2;
  const exactSize = Math.min(pieceRect.width, pieceRect.height);

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
