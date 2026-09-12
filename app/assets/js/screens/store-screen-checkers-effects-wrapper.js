import {
  initStoreScreen as initBaseStoreScreen,
  openStoreTab as openBaseStoreTab,
  openStoreSheet as openBaseStoreSheet,
} from './store-screen-checkers-wrapper.js?v=4&mvp19_6=visual-corrective-v3&base_rev=19&visual_rev=4';

ensureEffectPreviewStyles();

export function initStoreScreen(){
  return initBaseStoreScreen();
}

export function openStoreTab(){
  return openBaseStoreTab();
}

export function openStoreSheet(){
  return openBaseStoreSheet();
}

function ensureEffectPreviewStyles(){
  const href = new URL('../../css/games/checkers/store-effects-live-board-v1.css?v=2&mvp19_6=effects-live-board-only', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-checkers-store-effects-live-board]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersStoreEffectsLiveBoard = 'mvp19-6-effects-live-board-v1';
  link.href = href;
  document.head.appendChild(link);
}
