const INSTALL_KEY = '__mgwStoreGameSelectorSwipeV1';
const SELECTOR = '.store-v2-content[data-store-v2-panel="games"] .store-v2-game-selector';
const SUPPRESS_MS = 360;
const AXIS_LOCK_PX = 7;

export function installStoreGameSelectorSwipe(){
  ensureStyles();
  if (globalThis[INSTALL_KEY]) return;
  globalThis[INSTALL_KEY] = true;

  let touchState = null;
  let mouseState = null;

  const selectorFromTarget = target => target instanceof Element ? target.closest(SELECTOR) : null;
  const clearSelection = () => {
    try { globalThis.getSelection?.()?.removeAllRanges?.(); } catch (_) {}
  };
  const markDragging = selector => {
    if (!(selector instanceof HTMLElement)) return;
    selector.classList.add('mgw-store-game-selector-dragging');
    clearSelection();
  };
  const finishDragging = (selector, moved) => {
    if (!(selector instanceof HTMLElement)) return;
    selector.classList.remove('mgw-store-game-selector-dragging');
    if (moved) selector.dataset.mgwSuppressGameClickUntil = String(Date.now() + SUPPRESS_MS);
  };

  document.addEventListener('selectstart', event => {
    if (selectorFromTarget(event.target)) event.preventDefault();
  }, true);

  document.addEventListener('dragstart', event => {
    if (selectorFromTarget(event.target)) event.preventDefault();
  }, true);

  document.addEventListener('touchstart', event => {
    if (event.touches.length !== 1) {
      touchState = null;
      return;
    }
    const selector = selectorFromTarget(event.target);
    if (!(selector instanceof HTMLElement)) {
      touchState = null;
      return;
    }
    const touch = event.touches[0];
    touchState = {
      selector,
      identifier:touch.identifier,
      startX:touch.clientX,
      startY:touch.clientY,
      startScrollLeft:selector.scrollLeft,
      axis:null,
      moved:false,
    };
  }, { capture:true, passive:true });

  document.addEventListener('touchmove', event => {
    if (!touchState) return;
    const touch = Array.from(event.touches).find(item => item.identifier === touchState.identifier);
    if (!touch) return;
    const dx = touch.clientX - touchState.startX;
    const dy = touch.clientY - touchState.startY;
    if (touchState.axis === null) {
      if (Math.max(Math.abs(dx), Math.abs(dy)) < AXIS_LOCK_PX) return;
      touchState.axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
    }
    if (touchState.axis !== 'x') return;
    touchState.moved = true;
    markDragging(touchState.selector);
    touchState.selector.scrollLeft = touchState.startScrollLeft - dx;
    if (event.cancelable) event.preventDefault();
  }, { capture:true, passive:false });

  const finishTouch = () => {
    if (!touchState) return;
    finishDragging(touchState.selector, touchState.moved && touchState.axis === 'x');
    touchState = null;
  };
  document.addEventListener('touchend', finishTouch, { capture:true, passive:true });
  document.addEventListener('touchcancel', finishTouch, { capture:true, passive:true });

  document.addEventListener('pointerdown', event => {
    if (event.pointerType !== 'mouse' || event.button !== 0) return;
    const selector = selectorFromTarget(event.target);
    if (!(selector instanceof HTMLElement)) {
      mouseState = null;
      return;
    }
    mouseState = {
      selector,
      pointerId:event.pointerId,
      startX:event.clientX,
      startY:event.clientY,
      startScrollLeft:selector.scrollLeft,
      axis:null,
      moved:false,
    };
  }, true);

  document.addEventListener('pointermove', event => {
    if (!mouseState || event.pointerId !== mouseState.pointerId) return;
    const dx = event.clientX - mouseState.startX;
    const dy = event.clientY - mouseState.startY;
    if (mouseState.axis === null) {
      if (Math.max(Math.abs(dx), Math.abs(dy)) < AXIS_LOCK_PX) return;
      mouseState.axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
    }
    if (mouseState.axis !== 'x') return;
    mouseState.moved = true;
    markDragging(mouseState.selector);
    mouseState.selector.scrollLeft = mouseState.startScrollLeft - dx;
    if (event.cancelable) event.preventDefault();
  }, { capture:true, passive:false });

  const finishMouse = event => {
    if (!mouseState || (event && event.pointerId !== mouseState.pointerId)) return;
    finishDragging(mouseState.selector, mouseState.moved && mouseState.axis === 'x');
    mouseState = null;
  };
  document.addEventListener('pointerup', finishMouse, true);
  document.addEventListener('pointercancel', finishMouse, true);

  document.addEventListener('click', event => {
    const selector = selectorFromTarget(event.target);
    if (!(selector instanceof HTMLElement)) return;
    const suppressUntil = Number(selector.dataset.mgwSuppressGameClickUntil || 0);
    if (!Number.isFinite(suppressUntil) || Date.now() >= suppressUntil) return;
    event.preventDefault();
    event.stopImmediatePropagation();
  }, true);
}

function ensureStyles(){
  const href = new URL('../../css/screens/store-game-selector-swipe-v1.css?v=1&mvp19_7=touch-drag', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-store-game-selector-swipe]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwStoreGameSelectorSwipe = 'v1';
  link.href = href;
  document.head.appendChild(link);
}
