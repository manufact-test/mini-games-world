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
  /* The legend is immutable UI copy. Keep one physical DOM node across optimistic
   * and authoritative board snapshots so Chromium never has to re-rasterize the
   * tiny muted labels during the paid-effect handoff. */
  const stableLegend = container.querySelector('.checkers-legend');

  renderBaseCheckersSurface({ game, me, container, onAction });

  const renderedLegend = container.querySelector('.checkers-legend');
  if (stableLegend instanceof HTMLElement && renderedLegend instanceof HTMLElement && stableLegend !== renderedLegend) {
    renderedLegend.replaceWith(stableLegend);
  }

  container.dataset.checkersTheme = checkersBoardVariant(game, me);
  container.dataset.mgwCheckersPaidEffect = viewerHasPaidCheckersEffect(game, me) ? '1' : '0';

  /* The paid checker is a detached overlay, while the settled checker is a real
   * 72%-wide child centered by CSS Grid. At fractional cell widths Chromium may
   * round that percentage-sized child by a device pixel differently from the
   * mathematical cell midpoint. That is why the last handoff could still move down
   * on one phone but sideways on desktop even though both used the same cell center.
   *
   * Re-read the hidden authoritative checker's own physical border box after the
   * outer live-effect wrapper has masked it. The overlay now finishes on the exact
   * pixels occupied by the checker that will be revealed, not an inferred square
   * center. Rules, hit targets, optimistic state, timers and settlement stay frozen. */
  queueExactLiveLanding(container);
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

function queueExactLiveLanding(container){
  const run = () => {
    if (container instanceof HTMLElement && container.isConnected) syncExactLiveLanding(container);
  };
  if (typeof globalThis.queueMicrotask === 'function') globalThis.queueMicrotask(run);
  else Promise.resolve().then(run);
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(run);
}

function syncExactLiveLanding(container){
  if (!(container instanceof HTMLElement) || container.dataset.mgwCheckersPaidEffect !== '1') return;
  const board = container.querySelector('.checkers-board');
  if (!(board instanceof HTMLElement)) return;
  const layer = nearestLiveEffectLayer(board);
  if (!(layer instanceof HTMLElement)) return;

  const cells = Array.from(board.querySelectorAll('[data-checkers-cell]')).filter(cell => cell instanceof HTMLElement);
  if (cells.length !== 64) return;

  const oldFrom = layerPoint(layer, '--mgw-fx-from-x', '--mgw-fx-from-y');
  const oldTo = layerPoint(layer, '--mgw-fx-to-x', '--mgw-fx-to-y');
  if (!oldFrom || !oldTo) return;

  const sourceCell = nearestCell(board, cells, oldFrom);
  const hiddenCells = cells.filter(cell => cell.classList.contains('mgw-checkers-live-fx-hide-piece'));
  const destinationCell = nearestCell(board, hiddenCells.length ? hiddenCells : cells, oldTo);
  if (!(sourceCell instanceof HTMLElement) || !(destinationCell instanceof HTMLElement)) return;

  const fromPoint = cellCenter(board, sourceCell);
  const destinationCellPoint = cellCenter(board, destinationCell);
  const settledPiecePoint = pieceCenter(board, destinationCell);
  const toPoint = settledPiecePoint || destinationCellPoint;
  if (!fromPoint || !destinationCellPoint || !toPoint) return;

  const dx = toPoint.x - fromPoint.x;
  const dy = toPoint.y - fromPoint.y;
  const distance = Math.hypot(dx, dy);
  const angle = Math.atan2(dy, dx) * 180 / Math.PI;
  const cellSize = Math.min(fromPoint.size, destinationCellPoint.size);
  const exactPieceSize = settledPiecePoint?.size || Math.max(20, cellSize * .72);

  layer.style.setProperty('--mgw-fx-from-x', `${fromPoint.x}px`);
  layer.style.setProperty('--mgw-fx-from-y', `${fromPoint.y}px`);
  layer.style.setProperty('--mgw-fx-to-x', `${toPoint.x}px`);
  layer.style.setProperty('--mgw-fx-to-y', `${toPoint.y}px`);
  layer.style.setProperty('--mgw-fx-dx', `${dx}px`);
  layer.style.setProperty('--mgw-fx-dy', `${dy}px`);
  layer.style.setProperty('--mgw-fx-piece-size', `${exactPieceSize}px`);

  const path = layer.querySelector('.mgw-checkers-live-fx-path');
  if (path instanceof HTMLElement) {
    path.style.left = `${fromPoint.x}px`;
    path.style.top = `${fromPoint.y}px`;
    path.style.width = `${distance}px`;
    path.style.transform = `translateY(-50%) rotate(${angle}deg)`;
  }

  const crown = layer.querySelector('.mgw-checkers-live-fx-crown');
  if (crown instanceof HTMLElement) {
    crown.style.left = `${toPoint.x}px`;
    crown.style.top = `${toPoint.y}px`;
  }

  const impact = layer.querySelector('.mgw-checkers-live-fx-impact');
  const captureTarget = layer.querySelector('.mgw-checkers-live-fx-target');
  if (captureTarget instanceof HTMLElement) {
    const targetPoint = nearestPointFromInlinePosition(board, cells, captureTarget);
    if (targetPoint) {
      captureTarget.style.left = `${targetPoint.x}px`;
      captureTarget.style.top = `${targetPoint.y}px`;
      impact?.style.setProperty('left', `${targetPoint.x}px`);
      impact?.style.setProperty('top', `${targetPoint.y}px`);
    }
  } else if (impact instanceof HTMLElement) {
    impact.style.left = `${toPoint.x}px`;
    impact.style.top = `${toPoint.y}px`;
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

function layerPoint(layer, xName, yName){
  const x = Number.parseFloat(layer.style.getPropertyValue(xName));
  const y = Number.parseFloat(layer.style.getPropertyValue(yName));
  return Number.isFinite(x) && Number.isFinite(y) ? { x, y } : null;
}

function nearestCell(board, cells, point){
  if (!Array.isArray(cells) || cells.length === 0 || !point) return null;
  let best = null;
  let bestDistance = Infinity;
  cells.forEach(cell => {
    const center = cellCenter(board, cell);
    if (!center) return;
    const distance = Math.hypot(center.x - point.x, center.y - point.y);
    if (distance < bestDistance) {
      best = cell;
      bestDistance = distance;
    }
  });
  return best;
}

function nearestPointFromInlinePosition(board, cells, element){
  const x = Number.parseFloat(element.style.left);
  const y = Number.parseFloat(element.style.top);
  if (!Number.isFinite(x) || !Number.isFinite(y)) return null;
  const cell = nearestCell(board, cells, { x, y });
  return cell instanceof HTMLElement ? cellCenter(board, cell) : null;
}

function cellCenter(board, cell){
  if (!(board instanceof HTMLElement) || !(cell instanceof HTMLElement)) return null;
  const boardRect = board.getBoundingClientRect();
  const cellRect = cell.getBoundingClientRect();
  return {
    x:cellRect.left - boardRect.left + cellRect.width / 2,
    y:cellRect.top - boardRect.top + cellRect.height / 2,
    size:Math.min(cellRect.width, cellRect.height),
  };
}

function pieceCenter(board, cell){
  if (!(board instanceof HTMLElement) || !(cell instanceof HTMLElement)) return null;
  const piece = cell.querySelector('.checkers-piece');
  if (!(piece instanceof HTMLElement)) return null;
  const boardRect = board.getBoundingClientRect();
  const pieceRect = piece.getBoundingClientRect();
  if (!(pieceRect.width > 0) || !(pieceRect.height > 0)) return null;
  return {
    x:pieceRect.left - boardRect.left + pieceRect.width / 2,
    y:pieceRect.top - boardRect.top + pieceRect.height / 2,
    size:Math.min(pieceRect.width, pieceRect.height),
  };
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
  const href = new URL('../../css/games/checkers/runtime-handoff-mobile-v1.css?v=4&mvp19_6=exact-live-centers&legend=stable-paint-v1&grid_rows=equal-v1&mobile=insets-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-checkers-runtime-corrective]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwCheckersRuntimeCorrective = 'mvp19-6-exact-live-centers-v4';
    document.head.appendChild(existing);
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersRuntimeCorrective = 'mvp19-6-exact-live-centers-v4';
  link.href = href;
  document.head.appendChild(link);
}
