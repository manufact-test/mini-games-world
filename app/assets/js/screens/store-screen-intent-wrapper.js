import {
  initStoreScreen as initBaseStoreScreen,
  openStoreTab as openBaseStoreTab,
  openStoreSheet,
} from './store-screen.js?v=44&intent_base=1';
import { haptic } from '../telegram/telegram-app.js?v=27';

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
    haptic('light');
    return;
  }

  return openBaseStoreTab();
}

export { openStoreTab, openStoreSheet };

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
