import {
  initStoreScreen as initAcceptedStoreScreen,
  openStoreTab as openAcceptedStoreTab,
  openStoreSheet as openAcceptedStoreSheet,
} from './store-screen-checkers-board-source-wrapper.js?v=7&mvp19_6=board-source-parity-v1&card_radius=v1&promotion_preview=king-readable-v2&final_centering=king-readable-v2&parent=store-screen-checkers-wrapper.js?v=4&mvp19_6=visual-corrective-v3&visual_rev=4&effects_live_board=v1&effects_loop=v1&boards_pieces=v1&mvp19_7=reversi-store-v1&review=manual-corrective-v2&selector_swipe=v1&mvp19_8=go-store-v1&go_effects=premium-v2';
import {
  installDominoStorePresentation,
  upgradeDominoStorePresentation,
} from './store-screen-domino-store-v1.js?v=1&mvp19_9=store-profile-preview-8x5-v1';

ensureDominoScaleStyles();
installDominoStorePresentation();

export function initStoreScreen(){
  ensureDominoScaleStyles();
  installDominoStorePresentation();
  const result = initAcceptedStoreScreen();
  upgradeDominoStorePresentation();
  return result;
}

export async function openStoreTab(){
  ensureDominoScaleStyles();
  installDominoStorePresentation();
  const result = await openAcceptedStoreTab();
  upgradeDominoStorePresentation();
  return result;
}

export async function openStoreSheet(){
  ensureDominoScaleStyles();
  installDominoStorePresentation();
  const result = await openAcceptedStoreSheet();
  upgradeDominoStorePresentation();
  return result;
}

function ensureDominoScaleStyles(){
  const href = new URL('../../css/games/domino/store-cosmetics-scale-v2.css?v=1&mvp19_9=container-relative-v2', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-domino-scale]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    document.head.appendChild(existing);
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwDominoScale = 'container-relative-v2';
  link.href = href;
  document.head.appendChild(link);
}
