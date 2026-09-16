import {
  renderDominoSurface as renderCorrectiveV25,
  dominoMeta,
  dominoPlayerMark,
  dominoStatus,
} from './renderer-cosmetics-corrective-v25-base.js?v=1&internal=v25-v27-compat';

const HAND_DRAG_THRESHOLD = 8;
const pointerOwners = new WeakMap();

export { dominoMeta, dominoPlayerMark, dominoStatus };

export function renderDominoSurface(args){
  renderCorrectiveV25(args);

  const container = args?.container;
  if (!(container instanceof HTMLElement)) return;

  const gameId = String(args?.game?.id || '');
  container.dataset.mgwDominoHandPointer = 'v28';
  ensureStablePointerOwner(container, gameId);
  markCurrentHand(container);
}

function markCurrentHand(container){
  const hand = container.querySelector('.domino-hand');
  if (!(hand instanceof HTMLElement)) return null;
  hand.dataset.dominoHandPointer = 'v28';
  return hand;
}

function ensureStablePointerOwner(container, gameId){
  const existing = pointerOwners.get(container);
  if (existing) {
    existing.gameId = gameId;
    return;
  }

  const drag = {
    gameId,
    pointerId:null,
    hand:null,
    startX:0,
    startY:0,
    startScrollLeft:0,
    lastScrollLeft:0,
    horizontal:false,
    vertical:false,
    suppressClick:false,
  };
  pointerOwners.set(container, drag);

  const reset = event => {
    if (drag.pointerId === null) return;
    if (event && Number(event.pointerId) !== drag.pointerId) return;

    const pointerId = drag.pointerId;
    if (drag.horizontal) drag.suppressClick = true;
    if (drag.hand instanceof HTMLElement) drag.hand.classList.remove('is-dragging');

    drag.pointerId = null;
    drag.hand = null;
    drag.horizontal = false;
    drag.vertical = false;

    if (typeof container.hasPointerCapture === 'function'
      && typeof container.releasePointerCapture === 'function'
      && container.hasPointerCapture(pointerId)) {
      container.releasePointerCapture(pointerId);
    }
  };

  container.addEventListener('pointerdown', event => {
    if (!event.isPrimary || event.pointerType === 'mouse' || drag.pointerId !== null) return;
    const target = event.target instanceof Element ? event.target : null;
    const hand = target?.closest('.domino-hand');
    if (!(hand instanceof HTMLElement) || !container.contains(hand)) return;

    drag.pointerId = Number(event.pointerId);
    drag.hand = hand;
    drag.startX = Number(event.clientX);
    drag.startY = Number(event.clientY);
    drag.startScrollLeft = hand.scrollLeft;
    drag.lastScrollLeft = hand.scrollLeft;
    drag.horizontal = false;
    drag.vertical = false;
    drag.suppressClick = false;
    hand.dataset.dominoHandPointer = 'v28';
  }, { passive:true });

  container.addEventListener('pointermove', event => {
    if (drag.pointerId === null || Number(event.pointerId) !== drag.pointerId || drag.vertical) return;

    let hand = drag.hand;
    if (!(hand instanceof HTMLElement) || !hand.isConnected || !container.contains(hand)) {
      hand = markCurrentHand(container);
      if (!(hand instanceof HTMLElement)) return;
      hand.scrollLeft = drag.lastScrollLeft;
      drag.hand = hand;
    }

    const dx = Number(event.clientX) - drag.startX;
    const dy = Number(event.clientY) - drag.startY;

    if (!drag.horizontal) {
      if (Math.abs(dx) < HAND_DRAG_THRESHOLD && Math.abs(dy) < HAND_DRAG_THRESHOLD) return;
      if (Math.abs(dx) <= Math.abs(dy) * 1.05) {
        drag.vertical = true;
        return;
      }

      drag.horizontal = true;
      hand.classList.add('is-dragging');
      if (typeof container.setPointerCapture === 'function') {
        try { container.setPointerCapture(drag.pointerId); } catch (_) {}
      }
    }

    if (event.cancelable) event.preventDefault();
    const maxScroll = Math.max(0, hand.scrollWidth - hand.clientWidth);
    const next = Math.max(0, Math.min(drag.startScrollLeft - dx, maxScroll));
    hand.scrollLeft = next;
    drag.lastScrollLeft = next;
  }, { passive:false });

  container.addEventListener('pointerup', reset, { passive:true });
  container.addEventListener('pointercancel', reset, { passive:true });

  container.addEventListener('click', event => {
    if (!drag.suppressClick) return;
    const target = event.target instanceof Element ? event.target : null;
    if (!(target?.closest('.domino-hand') instanceof HTMLElement)) return;

    drag.suppressClick = false;
    event.preventDefault();
    event.stopImmediatePropagation();
  }, true);
}
