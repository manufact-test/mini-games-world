import {
  initStoreScreen as initBaseStoreScreen,
  openStoreTab,
  openStoreSheet,
} from './store-screen.js?v=44&intent_base=1';

let initialized = false;

export function initStoreScreen(){
  if (initialized) return;
  initialized = true;

  // The canonical shell now calls this owner only after the mobile Profile raster
  // warm has completed. That makes the accepted idle Store status warm safe again
  // on the normal shell route and removes the cold first-open replacement frame.
  // Keep active-game mobile reloads intent-only: warming Store there can still
  // compete with the authoritative game/bootstrap worker burst for no user value.
  if (!keepsMobileIntentOnlyStore()) {
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

function keepsMobileIntentOnlyStore(){
  if (!usesMobileIntentOnlyStore()) return false;
  const active = document.querySelector('.screen.active');
  return String(active?.dataset.screen || '') === 'game';
}

function usesMobileIntentOnlyStore(){
  return typeof window.matchMedia === 'function'
    && window.matchMedia('(max-width: 640px), (pointer: coarse)').matches;
}

export { openStoreTab, openStoreSheet };
