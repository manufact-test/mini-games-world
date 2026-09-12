import {
  initStoreScreen as initAcceptedCheckersStore,
  openStoreTab as openAcceptedCheckersStoreTab,
  openStoreSheet as openAcceptedCheckersStoreSheet,
} from './store-screen-checkers-wrapper.js?v=4&mvp19_6=visual-corrective-v3&base_rev=19&visual_rev=4&effects_live_board=v1&effects_loop=v1&boards_pieces=v1';

ensureBoardSourceParityStyles();
ensureBoardCardRadiusStyles();

export function initStoreScreen(){
  ensureBoardSourceParityStyles();
  ensureBoardCardRadiusStyles();
  return initAcceptedCheckersStore();
}

export async function openStoreTab(){
  ensureBoardSourceParityStyles();
  ensureBoardCardRadiusStyles();
  return openAcceptedCheckersStoreTab();
}

export async function openStoreSheet(){
  ensureBoardSourceParityStyles();
  ensureBoardCardRadiusStyles();
  return openAcceptedCheckersStoreSheet();
}

function ensureBoardSourceParityStyles(){
  const href = new URL('../../css/games/checkers/store-boards-pieces-polish-v2.css?v=1&mvp19_6=board-source-parity', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-checkers-store-board-source-parity]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersStoreBoardSourceParity = 'mvp19-6-board-source-parity-v1';
  link.href = href;
  document.head.appendChild(link);
}

function ensureBoardCardRadiusStyles(){
  const href = new URL('../../css/games/checkers/store-board-card-radius-v1.css?v=1&mvp19_6=store-card-radius', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-checkers-store-board-card-radius]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersStoreBoardCardRadius = 'mvp19-6-store-card-radius-v1';
  link.href = href;
  document.head.appendChild(link);
}
