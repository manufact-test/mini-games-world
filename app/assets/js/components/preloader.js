const APP_ENTRY_MIN_VISIBLE_MS = 2100;
const APP_ENTRY_FADE_MS = 380;
const APP_ENTRY_ANIMATION_SETTLE_MS = 90;

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

  window.setTimeout(() => {
    el.classList.add('hidden');

    // Wait for the opacity/visibility transition to finish before allowing
    // important in-app alerts. Otherwise a toast can be created underneath
    // the preloader and disappear before the player ever sees it.
    window.setTimeout(() => {
      document.dispatchEvent(new CustomEvent('mgw:app-ready'));
    }, APP_ENTRY_FADE_MS);
  }, remaining);
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
