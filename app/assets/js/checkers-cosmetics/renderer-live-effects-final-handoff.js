import {
  renderCheckersSurface as renderLiveEffectsSurface,
  checkersMeta,
  checkersPlayerMark,
  checkersStatus,
} from './renderer-live-effects-v1.js?v=11&mvp19_6=runtime-smoothing-v11&pieces=king-brand-v3&events=move-through-capture-v4&landing=direct-box-flight-v4&grid_rows=equal-v1&selection=geometry-neutral-v1&last_from=flat-v1&legend=stable-paint-v1&mobile=insets-v1&promotion=authoritative-only-v1';

ensureFinalHandoffStyles();

export { checkersMeta, checkersPlayerMark, checkersStatus };

/* Final live Checkers handoff owner.
 *
 * The prior corrective retargeted the paid overlay from queueMicrotask/rAF after the
 * live-effects module had already created a CSS animation. Chromium/WebView can
 * snapshot custom-property values used inside keyframes when the animation is first
 * resolved, so changing --mgw-fx-dx/--mgw-fx-dy a frame later does not guarantee
 * the running animation will adopt the corrected endpoint. That exactly matches the
 * real-device symptom: the overlay keeps landing on the old cell-derived point and
 * then the real checker appears a few pixels lower/sideways.
 *
 * This wrapper keeps the accepted live-effects implementation intact, but retargets
 * the newly-created overlay synchronously in the SAME JavaScript task, before the
 * browser can paint/resolve its first animation frame. The destination is the real
 * hidden checker DOM box, not an inferred cell center.
 */
export function renderCheckersSurface({ game, me, container, onAction }){
  ensureFinalHandoffStyles();
  renderLiveEffectsSurface({ game, me, container, onAction });
  syncOverlayEndpointBeforePaint(container);
}

function syncOverlayEndpointBeforePaint(container){
  if (!(container instanceof HTMLElement) || container.dataset.mgwCheckersPaidEffect !== '1') return;
  const board = container.querySelector('.checkers-board');
  if (!(board instanceof HTMLElement)) return;

  const layer = nearestLiveEffectLayer(board);
  if (!(layer instanceof HTMLElement)) return;

  const toCells = Array.from(board.querySelectorAll('.checkers-cell.mgw-checkers-live-fx-hide-piece'))
    .filter(node => node instanceof HTMLElement && node.querySelector('.checkers-piece'));
  if (toCells.length === 0) return;

  const oldTo = layerPoint(layer, '--mgw-fx-to-x', '--mgw-fx-to-y');
  const oldFrom = layerPoint(layer, '--mgw-fx-from-x', '--mgw-fx-from-y');
  if (!oldTo || !oldFrom) return;

  const destinationCell = nearestCellToPoint(board, toCells, oldTo);
  const destinationPiece = destinationCell?.querySelector('.checkers-piece');
  if (!(destinationPiece instanceof HTMLElement)) return;

  const boardRect = board.getBoundingClientRect();
  const pieceRect = destinationPiece.getBoundingClientRect();
  if (!(pieceRect.width > 0) || !(pieceRect.height > 0)) return;

  const toX = pieceRect.left - boardRect.left + pieceRect.width / 2;
  const toY = pieceRect.top - boardRect.top + pieceRect.height / 2;
  const pieceSize = Math.min(pieceRect.width, pieceRect.height);
  const dx = toX - oldFrom.x;
  const dy = toY - oldFrom.y;

  layer.style.setProperty('--mgw-fx-to-x', `${toX}px`);
  layer.style.setProperty('--mgw-fx-to-y', `${toY}px`);
  layer.style.setProperty('--mgw-fx-dx', `${dx}px`);
  layer.style.setProperty('--mgw-fx-dy', `${dy}px`);
  layer.style.setProperty('--mgw-fx-piece-size', `${pieceSize}px`);
  layer.dataset.mgwFinalHandoff = 'settled-piece-before-paint-v1';

  const impact = layer.querySelector('.mgw-checkers-live-fx-impact');
  if (impact instanceof HTMLElement && !layer.classList.contains('mgw-checkers-live-fx-capture')) {
    impact.style.left = `${toX}px`;
    impact.style.top = `${toY}px`;
  }

  const crown = layer.querySelector('.mgw-checkers-live-fx-crown');
  if (crown instanceof HTMLElement) {
    crown.style.left = `${toX}px`;
    crown.style.top = `${toY}px`;
  }
}

function nearestLiveEffectLayer(board){
  const boardRect = board.getBoundingClientRect();
  let best = null;
  let bestScore = Infinity;
  document.querySelectorAll('.mgw-checkers-live-fx').forEach(candidate => {
    if (!(candidate instanceof HTMLElement) || !candidate.isConnected) return;
    const rect = candidate.getBoundingClientRect();
    const score = Math.abs(rect.left - boardRect.left)
      + Math.abs(rect.top - boardRect.top)
      + Math.abs(rect.width - boardRect.width)
      + Math.abs(rect.height - boardRect.height);
    if (score < bestScore) {
      best = candidate;
      bestScore = score;
    }
  });
  return bestScore <= 8 ? best : null;
}

function nearestCellToPoint(board, cells, point){
  const boardRect = board.getBoundingClientRect();
  let best = null;
  let bestDistance = Infinity;
  cells.forEach(cell => {
    const rect = cell.getBoundingClientRect();
    const x = rect.left - boardRect.left + rect.width / 2;
    const y = rect.top - boardRect.top + rect.height / 2;
    const distance = Math.hypot(x - point.x, y - point.y);
    if (distance < bestDistance) {
      best = cell;
      bestDistance = distance;
    }
  });
  return best;
}

function layerPoint(layer, xName, yName){
  const x = Number.parseFloat(layer.style.getPropertyValue(xName));
  const y = Number.parseFloat(layer.style.getPropertyValue(yName));
  return Number.isFinite(x) && Number.isFinite(y) ? { x, y } : null;
}

function ensureFinalHandoffStyles(){
  const href = new URL('../../css/games/checkers/runtime-handoff-mobile-v1.css?v=6&mvp19_6=destination-anchor-v2&legend=stable-paint-v1&grid_rows=equal-v1&mobile=insets-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-checkers-final-handoff]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    document.head.appendChild(existing);
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersFinalHandoff = 'destination-anchor-v2';
  link.href = href;
  document.head.appendChild(link);
}
