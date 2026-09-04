import { state } from './state.js?v=27';

const ROUTES = Object.freeze({
  home:Object.freeze({ screen:'home', shell:true }),
  tournaments:Object.freeze({ screen:'tournaments', shell:true }),
  store:Object.freeze({ screen:'store', shell:true }),
  profile:Object.freeze({ screen:'profile', shell:true }),
  friends:Object.freeze({ screen:'friends', shell:false }),
  search:Object.freeze({ screen:'search', shell:false }),
  game:Object.freeze({ screen:'game', shell:false }),
});
const KNOWN_SCREENS = new Set(Object.keys(ROUTES));
const cleanupOwners = new Map();
let deferredProfileTransition = null;
let deferredProfileRaf = 0;
let deferredProfilePaintRaf = 0;

export function routeRegistry(){
  return ROUTES;
}

export function isKnownRoute(name){
  return KNOWN_SCREENS.has(String(name || '').trim());
}

export function currentScreen(){
  const active = document.querySelector('.screen.active');
  const detected = String(active?.dataset.screen || '').trim();
  if (KNOWN_SCREENS.has(detected)) {
    state.screen = detected;
    return detected;
  }
  return KNOWN_SCREENS.has(String(state.screen || '')) ? state.screen : 'home';
}

export function registerScreenCleanup(name, cleanup){
  const screen = String(name || '').trim();
  if (!KNOWN_SCREENS.has(screen) || typeof cleanup !== 'function') return () => {};

  let owners = cleanupOwners.get(screen);
  if (!owners) {
    owners = new Set();
    cleanupOwners.set(screen, owners);
  }
  owners.add(cleanup);

  let registered = true;
  return () => {
    if (!registered) return;
    registered = false;
    owners.delete(cleanup);
    if (owners.size === 0) cleanupOwners.delete(screen);
  };
}

export function showScreen(name){
  const next = String(name || '').trim();
  if (!KNOWN_SCREENS.has(next)) return currentScreen();

  flushDeferredProfileTransition();

  const previous = currentScreen();
  if (previous !== next) runScreenCleanups(previous, next);

  document.querySelectorAll('.screen').forEach(screen => {
    screen.classList.toggle('active', screen.dataset.screen === next);
  });
  state.screen = next;

  if (previous !== next) {
    const detail = { from:previous, to:next };
    if (shouldYieldProfileEffects(next)) deferProfileTransition(detail);
    else dispatchScreenChanged(detail);
  }

  return next;
}

export function onScreenEnter(name, listener){
  const screen = String(name || '').trim();
  if (!KNOWN_SCREENS.has(screen) || typeof listener !== 'function') return () => {};
  const handler = event => {
    if (event?.detail?.to === screen) listener(event);
  };
  document.addEventListener('mgw:screen-changed', handler);
  return () => document.removeEventListener('mgw:screen-changed', handler);
}

export function onScreenLeave(name, listener){
  const screen = String(name || '').trim();
  if (!KNOWN_SCREENS.has(screen) || typeof listener !== 'function') return () => {};
  const handler = event => {
    if (event?.detail?.from === screen) listener(event);
  };
  document.addEventListener('mgw:screen-changed', handler);
  return () => document.removeEventListener('mgw:screen-changed', handler);
}

function shouldYieldProfileEffects(next){
  if (next !== 'profile' || typeof window === 'undefined') return false;
  try {
    return window.matchMedia('(pointer: coarse)').matches || window.matchMedia('(max-width: 900px)').matches;
  } catch (_) {
    return false;
  }
}

function deferProfileTransition(detail){
  deferredProfileTransition = detail;
  if (typeof requestAnimationFrame !== 'function') {
    queueMicrotask(flushDeferredProfileTransition);
    return;
  }

  deferredProfileRaf = requestAnimationFrame(() => {
    deferredProfileRaf = 0;
    deferredProfilePaintRaf = requestAnimationFrame(() => {
      deferredProfilePaintRaf = 0;
      flushDeferredProfileTransition();
    });
  });
}

function flushDeferredProfileTransition(){
  if (!deferredProfileTransition) return;
  if (deferredProfileRaf) cancelAnimationFrame(deferredProfileRaf);
  if (deferredProfilePaintRaf) cancelAnimationFrame(deferredProfilePaintRaf);
  deferredProfileRaf = 0;
  deferredProfilePaintRaf = 0;
  const detail = deferredProfileTransition;
  deferredProfileTransition = null;
  dispatchScreenChanged(detail);
}

function dispatchScreenChanged(detail){
  document.dispatchEvent(new CustomEvent('mgw:screen-changed', { detail }));
}

function runScreenCleanups(from, to){
  const owners = [...(cleanupOwners.get(from) || [])];
  for (const cleanup of owners) {
    try {
      cleanup({ from, to });
    } catch (error) {
      document.dispatchEvent(new CustomEvent('mgw:screen-cleanup-error', {
        detail:{ from, to, error },
      }));
    }
  }
}
