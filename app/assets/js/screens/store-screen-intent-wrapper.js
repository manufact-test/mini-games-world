import {
  initStoreScreen as initBaseStoreScreen,
  openStoreTab,
  openStoreSheet,
} from './store-screen.js?v=44&intent_base=1';

let initialized = false;

export function initStoreScreen(){
  if (initialized) return;
  initialized = true;

  // Mobile Profile first-frame warmup owns the preloader window. Do not start
  // Store's idle network/render warm in parallel with that work: it can finish
  // immediately after first Store intent and replace decorator-owned DOM, and it
  // also competes with the exact first Profile raster we are trying to prewarm.
  // Desktop keeps the accepted eager Store warm path unchanged.
  if (!usesMobileIntentOnlyStore()) {
    initBaseStoreScreen();
    return;
  }

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    const trigger = target?.closest('#storeOpen');
    if (!trigger) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    void openStoreTab();
  }, true);
}

function usesMobileIntentOnlyStore(){
  return typeof window.matchMedia === 'function'
    && window.matchMedia('(max-width: 640px), (pointer: coarse)').matches;
}

export { openStoreTab, openStoreSheet };
