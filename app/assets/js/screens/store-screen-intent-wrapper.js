import {
  initStoreScreen as initBaseStoreScreen,
  openStoreTab as openBaseStoreTab,
  openStoreSheet as openBaseStoreSheet,
} from './store-screen.js?v=44&intent_base=1';

let initialized = false;
let firstOpenPrimePromise = null;

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

  // The first visible Store used to expose its stable head/tabs while the lower
  // catalogue replaced a pending skeleton. Prime the canonical Store owner once
  // under the intro preloader instead. This loads and renders the real catalogue
  // before the user can navigate there, without changing purchase/equip semantics.
  firstOpenPrimePromise = canPrimeStoreUnderPreloader()
    ? Promise.resolve(openBaseStoreTab()).catch(() => {})
    : Promise.resolve();
  globalThis.__MGW_STORE_FIRST_OPEN_READY__ = firstOpenPrimePromise;

  return firstOpenPrimePromise;
}

export function openStoreTab(){
  return openBaseStoreTab();
}

export function openStoreSheet(){
  return openBaseStoreSheet();
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
