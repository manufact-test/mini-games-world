// Compatibility sentinel for accepted owner contract: from './store-screen.js?v=44&intent_base=1';
import {
  initStoreScreen as initBaseStoreScreen,
  openStoreTab as openBaseStoreTab,
  openStoreSheet as openBaseStoreSheet,
} from './store-screen.js?v=46&intent_base=1&mvp19_5=native-chess-render';
import { haptic } from '../telegram/telegram-app.js?v=27';

ensureChessCosmeticStyles();

let initialized = false;
let firstOpenPrimePromise = null;
let firstOpenPrimeReady = false;
let firstVisiblePrimeConsumed = false;

export function initStoreScreen(){
  if (initialized) return firstOpenPrimePromise;
  initialized = true;

  // Preserve the accepted Store lifecycle. No MutationObserver or global DOM
  // corrective runs here: game cosmetics are rendered by the Store owner itself.
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

  firstOpenPrimePromise = canPrimeStoreUnderPreloader()
    ? Promise.resolve(openBaseStoreTab())
      .then(() => {
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
  if (canConsumePrimedFirstPresentation()) {
    firstVisiblePrimeConsumed = true;
    haptic('light');
    return;
  }

  return openBaseStoreTab();
}

async function openStoreSheet(){
  return openBaseStoreSheet();
}

export { openStoreTab, openStoreSheet };

function ensureChessCosmeticStyles(){
  if (document.querySelector('link[data-mgw-chess-cosmetics]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwChessCosmetics = 'mvp19-5';
  link.href = new URL('../../css/games/chess/runtime-cosmetics.css?v=4&mvp19_5=native-store-render', import.meta.url).href;
  document.head.appendChild(link);
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
