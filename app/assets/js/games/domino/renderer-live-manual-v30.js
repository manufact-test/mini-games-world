import {
  renderDominoSurface as renderCorrectiveV25,
  dominoMeta,
  dominoPlayerMark,
  dominoStatus,
} from './renderer-cosmetics-corrective-v25.js?v=2&mvp19_9=manual-corrective-v25&hand_drag=v26&pointer_owner=v28&hand_layout=v29';

const handObservers = new WeakMap();

ensureStabilityStyles();

export { dominoMeta, dominoPlayerMark, dominoStatus };

export function renderDominoSurface(args){
  renderCorrectiveV25(args);

  const container = args?.container;
  if (!(container instanceof HTMLElement)) return;

  container.dataset.mgwDominoManualStability = 'v30';
  markHandLayout(container);
  ensureHandLayoutObserver(container);
  correctPrecisionContact(container);
}

function markHandLayout(container){
  const hand = container.querySelector('.domino-hand');
  if (!(hand instanceof HTMLElement)) return;

  const count = hand.querySelectorAll(':scope > .domino-hand-tile').length;
  const twoRow = count >= 9;
  const columns = twoRow ? Math.max(1, Math.ceil(count / 2)) : Math.max(1, count);

  hand.dataset.dominoHandLayout = twoRow ? 'two-row' : 'single-row';
  hand.dataset.dominoHandCount = String(count);
  hand.style.setProperty('--mgw-domino-hand-columns', String(columns));
}

function ensureHandLayoutObserver(container){
  if (handObservers.has(container) || typeof MutationObserver !== 'function') return;

  let scheduled = false;
  const observer = new MutationObserver(() => {
    if (scheduled) return;
    scheduled = true;
    queueMicrotask(() => {
      scheduled = false;
      if (container.isConnected) markHandLayout(container);
    });
  });
  observer.observe(container, { childList:true, subtree:true });
  handObservers.set(container, observer);
}

function correctPrecisionContact(container){
  const accent = document.querySelector('.domino-native-fx-accent.is-precision[data-domino-native-game]');
  const latestSlot = container.querySelector('.domino-chain-slot.latest');
  const latestTile = latestSlot?.querySelector('.domino-tile');
  if (!(accent instanceof HTMLElement) || !(latestSlot instanceof HTMLElement) || !(latestTile instanceof HTMLElement)) return;

  const slots = [...container.querySelectorAll('.domino-chain-slot')]
    .filter(slot => slot instanceof HTMLElement);
  const latestIndex = slots.indexOf(latestSlot);
  const neighborSlot = adjacentSlot(slots, latestIndex);
  const neighborTile = neighborSlot?.querySelector('.domino-tile');
  const latestRect = latestTile.getBoundingClientRect();

  if (!(neighborTile instanceof HTMLElement)) {
    const center = rectCenter(latestRect);
    accent.style.left = `${center.x}px`;
    accent.style.top = `${center.y}px`;
    return;
  }

  const neighborRect = neighborTile.getBoundingClientRect();
  const contact = seamBetweenRects(latestRect, neighborRect);
  accent.style.left = `${contact.x}px`;
  accent.style.top = `${contact.y}px`;
  accent.style.setProperty('--mgw-domino-native-angle', `${contact.angle}deg`);
  accent.dataset.dominoPrecisionAnchor = 'seam-v30';
}

function adjacentSlot(slots, latestIndex){
  if (latestIndex < 0 || slots.length < 2) return null;
  if (latestIndex === 0) return slots[1] || null;
  if (latestIndex === slots.length - 1) return slots[latestIndex - 1] || null;
  return slots[latestIndex - 1] || slots[latestIndex + 1] || null;
}

function seamBetweenRects(latestRect, neighborRect){
  const latest = rectCenter(latestRect);
  const neighbor = rectCenter(neighborRect);
  const dx = neighbor.x - latest.x;
  const dy = neighbor.y - latest.y;
  const length = Math.hypot(dx, dy);

  if (length <= 0.5) {
    return { x:latest.x, y:latest.y, angle:0 };
  }

  const ux = dx / length;
  const uy = dy / length;
  const latestDistance = rayBoxDistance(latestRect, ux, uy);
  const neighborDistance = rayBoxDistance(neighborRect, -ux, -uy);
  const latestEdge = {
    x:latest.x + ux * latestDistance,
    y:latest.y + uy * latestDistance,
  };
  const neighborEdge = {
    x:neighbor.x - ux * neighborDistance,
    y:neighbor.y - uy * neighborDistance,
  };

  return {
    x:(latestEdge.x + neighborEdge.x) / 2,
    y:(latestEdge.y + neighborEdge.y) / 2,
    angle:Math.atan2(latest.y - neighbor.y, latest.x - neighbor.x) * 180 / Math.PI,
  };
}

function rayBoxDistance(rect, ux, uy){
  const halfWidth = Math.max(0, Number(rect?.width || 0) / 2);
  const halfHeight = Math.max(0, Number(rect?.height || 0) / 2);
  const tx = Math.abs(ux) > 0.0001 ? halfWidth / Math.abs(ux) : Number.POSITIVE_INFINITY;
  const ty = Math.abs(uy) > 0.0001 ? halfHeight / Math.abs(uy) : Number.POSITIVE_INFINITY;
  const distance = Math.min(tx, ty);
  return Number.isFinite(distance) ? distance : 0;
}

function rectCenter(rect){
  return {
    x:Number(rect?.left || 0) + Number(rect?.width || 0) / 2,
    y:Number(rect?.top || 0) + Number(rect?.height || 0) / 2,
  };
}

function ensureStabilityStyles(){
  if (typeof document === 'undefined') return;
  const href = new URL('../../../css/games/domino/live-mobile-stability-v30.css?v=1&mvp19_9=mobile-stability-v30', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-domino-mobile-stability]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwDominoMobileStability = 'v30';
    return;
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwDominoMobileStability = 'v30';
  link.href = href;
  document.head.appendChild(link);
}
