import {
  initStoreScreen as initAcceptedCheckersStore,
  openStoreTab as openAcceptedCheckersStoreTab,
  openStoreSheet as openAcceptedCheckersStoreSheet,
} from './store-screen-checkers-wrapper.js?v=4&mvp19_6=visual-corrective-v3&base_rev=19&visual_rev=4&effects_live_board=v1&effects_loop=v1&boards_pieces=v1';
import {
  installReversiStorePresentation,
  upgradeReversiStorePresentation,
} from './store-screen-reversi-store-v1.js?v=4&mvp19_7=store-only&review=manual-corrective-v2&paid_default=copy-human-v1';
import {
  installGoStorePresentation,
  upgradeGoStorePresentation,
} from './store-screen-go-store-v1.js?v=4&mvp19_8=effects-premium-v2&paid_default=copy-human-v1';
import {
  installDominoStorePresentation,
  upgradeDominoStorePresentation,
} from './store-screen-domino-store-v1.js?v=16&mvp19_9=domino-svg-pips-v48&paid_default=copy-human-v1';
import { installDominoStoreCardFillV5 } from './store-screen-domino-card-fill-v5.js?v=2&mvp19_9=domino-card-fill-live-pips-v6';
import { installDominoStoreEffectsV9 } from './store-screen-domino-effects-v9.js?v=12&mvp19_9=domino-svg-pips-v48';
import {
  installFourInARowStorePresentation,
  upgradeFourInARowStorePresentation,
} from './store-screen-four-in-a-row-store-v1.js?v=7&four_store=static-v5&effect2=pulse-v2&export=profile-preview-v1&copy=human-v1';
import {
  installPaidDefaultDedupV1,
  upgradePaidDefaultDedupV1,
} from './store-paid-default-dedup-v1.js?v=2&paid_default=dedup-v2';
import { installStoreGameSelectorSwipe } from './store-game-selector-swipe-v1.js?v=1&mvp19_7=touch-drag';

ensureBoardSourceParityStyles();
ensureBoardCardRadiusStyles();
ensureEffectPreviewStyles();
ensureEffectFinalCenteringStyles();
installStoreGameSelectorSwipe();
installReversiStorePresentation();
installGoStorePresentation();
installDominoStorePresentation();
installDominoStoreCardFillV5();
installDominoStoreEffectsV9();
installFourInARowStorePresentation();
installPaidDefaultDedupV1();

export function initStoreScreen(){
  ensureBoardSourceParityStyles();
  ensureBoardCardRadiusStyles();
  ensureEffectPreviewStyles();
  ensureEffectFinalCenteringStyles();
  installStoreGameSelectorSwipe();
  installReversiStorePresentation();
  installGoStorePresentation();
  installDominoStorePresentation();
  installDominoStoreCardFillV5();
  installDominoStoreEffectsV9();
installFourInARowStorePresentation();
installPaidDefaultDedupV1();
  const result = initAcceptedCheckersStore();
  ensureEffectPreviewStyles();
  ensureEffectFinalCenteringStyles();
  upgradeReversiStorePresentation();
  upgradeGoStorePresentation();
  upgradeDominoStorePresentation();
  upgradeFourInARowStorePresentation();
  upgradePaidDefaultDedupV1();
  return result;
}

export async function openStoreTab(){
  ensureBoardSourceParityStyles();
  ensureBoardCardRadiusStyles();
  ensureEffectPreviewStyles();
  ensureEffectFinalCenteringStyles();
  installStoreGameSelectorSwipe();
  installReversiStorePresentation();
  installGoStorePresentation();
  installDominoStorePresentation();
  installDominoStoreCardFillV5();
  installDominoStoreEffectsV9();
installFourInARowStorePresentation();
installPaidDefaultDedupV1();
  const result = await openAcceptedCheckersStoreTab();
  ensureBoardSourceParityStyles();
  ensureBoardCardRadiusStyles();
  ensureEffectPreviewStyles();
  ensureEffectFinalCenteringStyles();
  upgradeReversiStorePresentation();
  upgradeGoStorePresentation();
  upgradeDominoStorePresentation();
  upgradeFourInARowStorePresentation();
  upgradePaidDefaultDedupV1();
  return result;
}

export async function openStoreSheet(){
  ensureBoardSourceParityStyles();
  ensureBoardCardRadiusStyles();
  ensureEffectPreviewStyles();
  ensureEffectFinalCenteringStyles();
  installStoreGameSelectorSwipe();
  installReversiStorePresentation();
  installGoStorePresentation();
  installDominoStorePresentation();
  installDominoStoreCardFillV5();
  installDominoStoreEffectsV9();
installFourInARowStorePresentation();
installPaidDefaultDedupV1();
  const result = await openAcceptedCheckersStoreSheet();
  ensureBoardSourceParityStyles();
  ensureBoardCardRadiusStyles();
  ensureEffectPreviewStyles();
  ensureEffectFinalCenteringStyles();
  upgradeReversiStorePresentation();
  upgradeGoStorePresentation();
  upgradeDominoStorePresentation();
  upgradeFourInARowStorePresentation();
  upgradePaidDefaultDedupV1();
  return result;
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

function ensureEffectPreviewStyles(){
  const href = new URL('../../css/games/checkers/store-effects-live-board-v1.css?v=3&mvp19_6=promotion-destination-parity-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-checkers-store-effects-live-board]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwCheckersStoreEffectsLiveBoard = 'mvp19-6-promotion-destination-parity-v1';
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersStoreEffectsLiveBoard = 'mvp19-6-promotion-destination-parity-v1';
  link.href = href;
  document.head.appendChild(link);
}

function ensureEffectFinalCenteringStyles(){
  const href = new URL('../../css/games/checkers/store-effects-final-centering-v1.css?v=2&mvp19_6=king-readable-v2', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-checkers-store-effect-final-centering]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwCheckersStoreEffectFinalCentering = 'mvp19-6-king-readable-v2';
    document.head.appendChild(existing);
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersStoreEffectFinalCentering = 'mvp19-6-king-readable-v2';
  link.href = href;
  document.head.appendChild(link);
}
