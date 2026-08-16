import { state } from './state.js?v=27';

const KNOWN_SCREENS = new Set(['home', 'search', 'game', 'profile']);

export function currentScreen(){
  const active = document.querySelector('.screen.active');
  const detected = String(active?.dataset.screen || '').trim();
  if (KNOWN_SCREENS.has(detected)) {
    state.screen = detected;
    return detected;
  }
  return KNOWN_SCREENS.has(String(state.screen || '')) ? state.screen : 'home';
}

export function showScreen(name){
  const next = String(name || '').trim();
  if (!KNOWN_SCREENS.has(next)) return currentScreen();

  const previous = currentScreen();
  document.querySelectorAll('.screen').forEach(screen => {
    screen.classList.toggle('active', screen.dataset.screen === next);
  });
  state.screen = next;

  if (previous !== next) {
    document.dispatchEvent(new CustomEvent('mgw:screen-changed', {
      detail:{ from:previous, to:next },
    }));
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
