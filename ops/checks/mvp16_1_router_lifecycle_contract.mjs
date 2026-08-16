const listeners = new Map();
const screens = ['home', 'search', 'game', 'profile', 'tournaments', 'store'].map((name, index) => ({
  dataset:{ screen:name },
  active:index === 0,
  classList:{
    toggle(_name, enabled){ this.owner.active = Boolean(enabled); },
    owner:null,
  },
}));
for (const screen of screens) screen.classList.owner = screen;

globalThis.CustomEvent = class CustomEvent {
  constructor(type, options = {}) { this.type = type; this.detail = options.detail; }
};
globalThis.document = {
  querySelector(selector){
    if (selector === '.screen.active') return screens.find(screen => screen.active) || null;
    return null;
  },
  querySelectorAll(selector){ return selector === '.screen' ? screens : []; },
  addEventListener(type, listener){
    const set = listeners.get(type) || new Set();
    set.add(listener);
    listeners.set(type, set);
  },
  removeEventListener(type, listener){ listeners.get(type)?.delete(listener); },
  dispatchEvent(event){
    for (const listener of [...(listeners.get(event.type) || [])]) listener(event);
    return true;
  },
};

const router = await import('../../app/assets/js/router.js?mvp16-lifecycle-contract');

const routes = Object.keys(router.routeRegistry());
for (const route of ['home','search','game','profile']) {
  if (!routes.includes(route)) throw new Error(`MVP-16.1 core route missing: ${route}`);
}
if (router.currentScreen() !== 'home') throw new Error('initial screen mismatch');
if (router.showScreen('unknown') !== 'home') throw new Error('unknown route must fail closed');

let cleanups = 0;
const unregisterCleanup = router.registerScreenCleanup('home', ({ from, to }) => {
  if (from !== 'home' || to !== 'profile') throw new Error('cleanup transition mismatch');
  cleanups += 1;
});
router.showScreen('profile');
router.showScreen('home');
router.showScreen('profile');
if (cleanups !== 2) throw new Error(`cleanup count mismatch: ${cleanups}`);
unregisterCleanup();
router.showScreen('home');
router.showScreen('profile');
if (cleanups !== 2) throw new Error('cleanup unregister failed');

const before = listeners.get('mgw:screen-changed')?.size || 0;
const stopEnter = router.onScreenEnter('home', () => {});
const stopLeave = router.onScreenLeave('profile', () => {});
const during = listeners.get('mgw:screen-changed')?.size || 0;
if (during !== before + 2) throw new Error('lifecycle listener registration mismatch');
stopEnter();
stopLeave();
const after = listeners.get('mgw:screen-changed')?.size || 0;
if (after !== before) throw new Error('lifecycle listener cleanup mismatch');

console.log('MVP16_1_ROUTER_LIFECYCLE_CONTRACT=PASS');
