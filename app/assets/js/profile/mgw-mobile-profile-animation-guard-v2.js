/* Mobile Profile animation lifecycle guard v2.
 *
 * The previous CSS-only hidden-animation corrective fixed the largest persistent
 * GPU cost, but it did so with ancestor-dependent universal descendant selectors.
 * Toggling #screen-profile.active therefore invalidated style for the entire long
 * Profile subtree on every enter/leave. On Android Telegram WebView that residual
 * style walk is visible as a short hitch exactly on Profile navigation.
 *
 * Keep route/state ownership untouched. Pause only the real Animation objects
 * while Profile is hidden or inside the existing route-settle window, then resume
 * only animations this guard paused after the active Profile has painted. Profile
 * DOM mutations are observed only for newly-created animations while hidden; route
 * class changes themselves never trigger a whole-subtree decorator/style pass.
 */

const MOBILE_PROFILE_MEDIA = '(max-width: 640px), (pointer: coarse)';
const PROFILE_ROUTE_SETTLING_CLASS = 'mgw-profile-route-settling';
const PROFILE_CSS_FALLBACK_CLASS = 'mgw-profile-animation-css-fallback';

const pausedByGuard = new Set();
const knownProfileAnimations = new Set();
let profileObserver = null;
let routeClassObserver = null;
let resumeFrameOne = 0;
let resumeFrameTwo = 0;
let initialized = false;

function isMobileProfilePresentation(){
  return typeof window.matchMedia === 'function'
    && window.matchMedia(MOBILE_PROFILE_MEDIA).matches;
}

function profileScreen(){
  const screen = document.getElementById('screen-profile');
  return screen instanceof HTMLElement ? screen : null;
}

function currentShellRoute(){
  return String(document.querySelector('.screen.active')?.dataset.screen || '').trim();
}

function routeFromNavigationTarget(target){
  if (!(target instanceof Element)) return '';
  const shellButton = target.closest('[data-shell-nav]');
  if (shellButton instanceof HTMLElement) return String(shellButton.dataset.shellNav || '').trim();
  if (target.closest('#profileOpen')) return 'profile';
  return '';
}

function animationsFor(root){
  if (!(root instanceof Element) || typeof root.getAnimations !== 'function') return null;
  try {
    return root.getAnimations({ subtree:true });
  } catch (_) {
    try { return root.getAnimations(); }
    catch (_) { return []; }
  }
}

function pauseAnimations(root = profileScreen()){
  if (!isMobileProfilePresentation() || !(root instanceof Element)) return;
  const animations = animationsFor(root);
  if (animations === null) {
    document.documentElement.classList.add(PROFILE_CSS_FALLBACK_CLASS);
    return;
  }

  for (const animation of animations) {
    knownProfileAnimations.add(animation);
    const state = String(animation?.playState || '');
    if (state !== 'running' && state !== 'pending') continue;
    try {
      animation.pause();
      pausedByGuard.add(animation);
    } catch (_) {}
  }
}

function pauseKnownAnimations(){
  const screen = profileScreen();
  if (!screen) return;
  for (const animation of [...knownProfileAnimations]) {
    if (!animationStillBelongsToProfile(animation, screen)) {
      knownProfileAnimations.delete(animation);
      pausedByGuard.delete(animation);
      continue;
    }
    const state = String(animation?.playState || '');
    if (state !== 'running' && state !== 'pending') continue;
    try {
      animation.pause();
      pausedByGuard.add(animation);
    } catch (_) {}
  }
}

function animationStillBelongsToProfile(animation, screen){
  const target = animation?.effect?.target;
  if (!(target instanceof Element)) return true;
  return target === screen || screen.contains(target);
}

function resumePausedAnimations(){
  cancelResumeFrames();
  const screen = profileScreen();
  if (!screen || currentShellRoute() !== 'profile') return;
  if (document.documentElement.classList.contains(PROFILE_ROUTE_SETTLING_CLASS)) return;

  for (const animation of [...pausedByGuard]) {
    pausedByGuard.delete(animation);
    if (!animationStillBelongsToProfile(animation, screen)) {
      knownProfileAnimations.delete(animation);
      continue;
    }
    if (String(animation?.playState || '') !== 'paused') continue;
    try { animation.play(); } catch (_) {}
  }
}

function cancelResumeFrames(){
  if (resumeFrameOne) window.cancelAnimationFrame(resumeFrameOne);
  if (resumeFrameTwo) window.cancelAnimationFrame(resumeFrameTwo);
  resumeFrameOne = 0;
  resumeFrameTwo = 0;
}

function resumeAfterTwoPaints(){
  cancelResumeFrames();
  if (currentShellRoute() !== 'profile') return;
  resumeFrameOne = window.requestAnimationFrame(() => {
    resumeFrameOne = 0;
    resumeFrameTwo = window.requestAnimationFrame(() => {
      resumeFrameTwo = 0;
      resumePausedAnimations();
    });
  });
}

function handleRouteIntent(event){
  if (!isMobileProfilePresentation()) return;
  const targetRoute = routeFromNavigationTarget(event.target);
  if (!targetRoute) return;
  const currentRoute = currentShellRoute();
  if (targetRoute !== 'profile' && currentRoute !== 'profile') return;

  // Never traverse the full Profile subtree on pointerdown. The hidden observer
  // already discovers/pauses new animations off the input path. Leaving Profile
  // only touches the already-known animation objects, which is O(active effects)
  // instead of forcing a first-use style walk across the long collection.
  cancelResumeFrames();
  if (currentRoute === 'profile' && targetRoute !== 'profile') pauseKnownAnimations();
}

function handleScreenChanged(event){
  if (!isMobileProfilePresentation()) return;
  const from = String(event?.detail?.from || '');
  const to = String(event?.detail?.to || '');
  if (from !== 'profile' && to !== 'profile') return;

  if (to !== 'profile') {
    cancelResumeFrames();
    pauseKnownAnimations();
    return;
  }

  // Pointer navigation is still inside the existing 360 ms settle guard. Its
  // class observer resumes after that guard; programmatic opens resume after the
  // first two Profile paint opportunities instead.
  if (!document.documentElement.classList.contains(PROFILE_ROUTE_SETTLING_CLASS)) {
    resumeAfterTwoPaints();
  }
}

function handleRouteClassMutation(){
  if (!isMobileProfilePresentation()) return;
  if (document.documentElement.classList.contains(PROFILE_ROUTE_SETTLING_CLASS)) {
    cancelResumeFrames();
    if (currentShellRoute() === 'profile') pauseKnownAnimations();
    return;
  }
  if (currentShellRoute() === 'profile') resumeAfterTwoPaints();
}

function handleProfileMutations(records){
  if (!isMobileProfilePresentation()) return;
  const hiddenOrSettling = currentShellRoute() !== 'profile'
    || document.documentElement.classList.contains(PROFILE_ROUTE_SETTLING_CLASS);
  if (!hiddenOrSettling) return;

  for (const record of records) {
    for (const node of record.addedNodes) {
      if (node instanceof Element) pauseAnimations(node);
    }
  }
}

function initMobileProfileAnimationGuard(){
  if (initialized || !isMobileProfilePresentation()) return;
  const screen = profileScreen();
  if (!screen) return;
  initialized = true;

  if (typeof screen.getAnimations !== 'function') {
    document.documentElement.classList.add(PROFILE_CSS_FALLBACK_CLASS);
    return;
  }

  // Profile is boot-rendered under the preloader. Freeze that prepared hidden
  // presentation once; first real entry will resume only after its initial paint.
  pauseAnimations(screen);

  profileObserver = new MutationObserver(handleProfileMutations);
  profileObserver.observe(screen, { childList:true, subtree:true });

  routeClassObserver = new MutationObserver(handleRouteClassMutation);
  routeClassObserver.observe(document.documentElement, { attributes:true, attributeFilter:['class'] });

  document.addEventListener('pointerdown', handleRouteIntent, true);
  document.addEventListener('click', handleRouteIntent, true);
  document.addEventListener('mgw:screen-changed', handleScreenChanged);

  // One full scan after app-ready is allowed off the user's first tap. It catches
  // pseudo-element/CSS animations introduced by late Profile decorators without
  // turning pointerdown into a forced subtree style/animation enumeration.
  document.addEventListener('mgw:app-ready', () => {
    const prime = () => {
      if (currentShellRoute() !== 'profile') pauseAnimations(screen);
    };
    if (typeof globalThis.requestIdleCallback === 'function') {
      globalThis.requestIdleCallback(prime, { timeout:700 });
    } else {
      globalThis.setTimeout(prime, 120);
    }
  }, { once:true });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initMobileProfileAnimationGuard, { once:true });
} else {
  initMobileProfileAnimationGuard();
}
