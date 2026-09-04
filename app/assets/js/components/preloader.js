const APP_ENTRY_MIN_VISIBLE_MS = 2100;
const APP_ENTRY_FADE_MS = 380;
const APP_ENTRY_ANIMATION_SETTLE_MS = 90;
const STORE_FIRST_OPEN_HOLD_MAX_MS = 1400;

export function hidePreloader(){
  const el = document.getElementById('preloader');

  if (!el) {
    document.dispatchEvent(new CustomEvent('mgw:app-ready'));
    return;
  }

  // Bootstrap may complete before the visual sequence. Keep the existing
  // readiness owner, but reveal the app only after both the minimum intro time
  // and every finite animation inside the intro has actually reached its end.
  const elapsed = Number.isFinite(performance?.now?.()) ? performance.now() : APP_ENTRY_MIN_VISIBLE_MS;
  const minimumRemaining = Math.max(0, APP_ENTRY_MIN_VISIBLE_MS - elapsed);
  const animationRemaining = getFiniteAnimationRemainingMs(el);
  const remaining = Math.max(minimumRemaining, animationRemaining + APP_ENTRY_ANIMATION_SETTLE_MS);

  // Normal shell startup primes the canonical Store surface under this same
  // preloader. Usually that request finishes inside the already-required intro
  // time, so there is no added delay. If boot itself was unusually slow, give the
  // Store a short bounded grace window so its first visible frame is the complete
  // catalogue instead of head/tabs followed by a lower-content replacement.
  const visualReady = new Promise(resolve => window.setTimeout(resolve, remaining));
  const storeReady = waitForStoreFirstOpenPrime();

  void Promise.all([visualReady, storeReady]).then(() => {
    el.classList.add('hidden');

    // Wait for the opacity/visibility transition to finish before allowing
    // important in-app alerts. Otherwise a toast can be created underneath
    // the preloader and disappear before the player ever sees it.
    window.setTimeout(() => {
      document.dispatchEvent(new CustomEvent('mgw:app-ready'));
    }, APP_ENTRY_FADE_MS);
  });
}

function waitForStoreFirstOpenPrime(){
  const ready = globalThis.__MGW_STORE_FIRST_OPEN_READY__;
  if (!ready || typeof ready.then !== 'function') return Promise.resolve();

  return Promise.race([
    Promise.resolve(ready).catch(() => {}),
    new Promise(resolve => window.setTimeout(resolve, STORE_FIRST_OPEN_HOLD_MAX_MS)),
  ]);
}

function getFiniteAnimationRemainingMs(root){
  if (typeof root?.getAnimations !== 'function') return 0;

  let longest = 0;
  for (const animation of root.getAnimations({ subtree:true })) {
    const timing = animation.effect?.getComputedTiming?.();
    const endTime = Number(timing?.endTime);
    const currentTime = Number(animation.currentTime);
    if (!Number.isFinite(endTime) || !Number.isFinite(currentTime)) continue;
    longest = Math.max(longest, endTime - currentTime);
  }
  return Math.max(0, longest);
}
