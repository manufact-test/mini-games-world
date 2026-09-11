// Compatibility sentinel for accepted owner contract: from './store-screen.js?v=44&intent_base=1';
import {
  initStoreScreen as initBaseStoreScreen,
  openStoreTab as openBaseStoreTab,
  openStoreSheet,
} from './store-screen.js?v=45&intent_base=1&mvp19_5=chess-catalog';
import { haptic } from '../telegram/telegram-app.js?v=27';

ensureChessCosmeticStyles();
installChessBoardPreviewParity();

let initialized = false;
let firstOpenPrimePromise = null;
let firstOpenPrimeReady = false;
let firstVisiblePrimeConsumed = false;

export function initStoreScreen(){
  if (initialized) return firstOpenPrimePromise;
  initialized = true;

  // Desktop keeps the existing Store owner/listeners and idle refresh path.
  // Mobile keeps the accepted intent-only listener so there is still no extra
  // background Store refresh racing decorator-owned cosmetic DOM after reveal.
  if (!usesMobileIntentOnlyStore()) {
    initBaseStoreScreen();
  } else {
    document.addEventListener('click', event => {
      const target = event.target instanceof Element ? event.target : null;
      const trigger = target?.closest('#storeOpen');
      if (!trigger) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      void openStoreTab();
    }, true);
  }

  // The accepted base Store owner still performs the actual render/load work.
  // Prime it once under the intro preloader, then remember only whether that
  // completed presentation is safe to publish on the first real Store tap.
  firstOpenPrimePromise = canPrimeStoreUnderPreloader()
    ? Promise.resolve(openBaseStoreTab())
      .then(() => {
        upgradeChessBoardPreviews();
        firstOpenPrimeReady = hasCompletedStorePresentation();
      })
      .catch(() => {
        firstOpenPrimeReady = false;
      })
    : Promise.resolve();
  globalThis.__MGW_STORE_FIRST_OPEN_READY__ = firstOpenPrimePromise;

  return firstOpenPrimePromise;
}

async function openStoreTab(){
  // This wrapper owns no Store state, catalogue, purchase or equipment logic.
  // It only avoids asking the accepted base owner to open the exact same primed
  // DOM twice before the first visible paint; later opens delegate normally.
  if (canConsumePrimedFirstPresentation()) {
    firstVisiblePrimeConsumed = true;
    upgradeChessBoardPreviews();
    haptic('light');
    return;
  }

  const result = await openBaseStoreTab();
  upgradeChessBoardPreviews();
  return result;
}

export { openStoreTab, openStoreSheet };

function ensureChessCosmeticStyles(){
  if (document.querySelector('link[data-mgw-chess-cosmetics]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwChessCosmetics = 'mvp19-5';
  link.href = new URL('../../css/games/chess/runtime-cosmetics.css?v=2&mvp19_5=chess-board-preview-parity', import.meta.url).href;
  document.head.appendChild(link);
}

function installChessBoardPreviewParity(){
  const root = document.documentElement;
  if (!(root instanceof HTMLElement)) return;

  const observer = new MutationObserver(records => {
    for (const record of records) {
      if (record.addedNodes.length > 0) {
        queueMicrotask(upgradeChessBoardPreviews);
        break;
      }
    }
  });
  observer.observe(root, { childList:true, subtree:true });
  queueMicrotask(upgradeChessBoardPreviews);
}

function upgradeChessBoardPreviews(){
  document.querySelectorAll('.store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"] .store-v2-mini-chess-board').forEach(board => {
    if (!(board instanceof HTMLElement) || board.dataset.boardPreviewParity === '8x8') return;

    const fragment = document.createDocumentFragment();
    for (let index = 0; index < 64; index += 1) {
      const row = Math.floor(index / 8);
      const column = index % 8;
      const square = document.createElement('span');
      square.className = ((row + column) % 2 === 0) ? 'light' : 'dark';
      square.setAttribute('aria-hidden', 'true');
      fragment.appendChild(square);
    }

    board.replaceChildren(fragment);
    board.dataset.boardPreviewParity = '8x8';
  });
}

function canConsumePrimedFirstPresentation(){
  if (!firstOpenPrimeReady || firstVisiblePrimeConsumed) return false;
  const preloader = document.getElementById('preloader');
  return preloader instanceof HTMLElement
    && preloader.classList.contains('hidden')
    && hasCompletedStorePresentation();
}

function hasCompletedStorePresentation(){
  return document.querySelector('#storeTabSurface .store-v2-shell:not(.is-pending)') instanceof HTMLElement;
}

function canPrimeStoreUnderPreloader(){
  const preloader = document.getElementById('preloader');
  const gameScreen = document.getElementById('screen-game');
  return preloader instanceof HTMLElement
    && !preloader.classList.contains('hidden')
    && !(gameScreen instanceof HTMLElement && gameScreen.classList.contains('active'));
}

function usesMobileIntentOnlyStore(){
  return typeof window.matchMedia === 'function'
    && window.matchMedia('(max-width: 640px), (pointer: coarse)').matches;
}
