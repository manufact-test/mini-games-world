import {
  initStoreScreen as initBaseStoreScreen,
  openStoreTab,
  openStoreSheet,
} from './store-screen.js?v=44&intent_base=1';

let initialized = false;

export function initStoreScreen(){
  if (initialized) return;
  initialized = true;

  // Mobile Store stays intent-only. The shell now owns a hidden first-paint gate:
  // on the first Store tap it lets the canonical Store owner render/load while
  // screen-store is still hidden, then publishes the route only after that await
  // completes. Keeping the idle Store warm disabled on mobile avoids the second
  // silent refresh/render racing immediately after first presentation and wiping
  // decorator-owned avatar/frame DOM. Desktop keeps the accepted idle warm path.
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
