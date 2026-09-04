import './production-clean-entry-v110.js?v=1131&mvp16=canonical-avatar-owner&mvp17=history-single-owner&mvp19=avatar-presentation&mvp19_4=character-identity&art=illustrated-raster&roster=portrait-v5&mvp19_3=name-colors&mvp19_3_6=profile-badge-avatar-overlay&mvp19_3_7=profile-frames&mvp19_3_8=profile-frame-preview-polish&mvp19_3_9=badge-avatar-card&mvp19_3_10=profile-frame-name-polish&mvp19_3_11=profile-badge-avatar-shape&mvp19_3_12=profile-frame-avatar-card-parity&mvp19_3_15=profile-backgrounds&mvp19_3_16=profile-backgrounds-ux-corrective&mvp19_3_17=background-route-hydration&mvp19_3_20=avatar-store-action&mvp19_3_21=avatar-store-action-post-app-ready&mvp19_3_22=store-freeze-idempotent-decorator&mvp19_3_23=frame-avatar-actions&mvp19_3_24=profile-card-parity';
import { initMgwPurchaseFeedback } from './commerce/mgw-purchase-feedback.js?v=1';

initMgwPurchaseFeedback();

/* Mobile Profile first-route stabilizer.
   The canonical boot already prepares Profile under the preloader. The remaining
   Android/Telegram hitch is the first *real* compositor state change: Profile and
   its metallic nav icon have never actually owned their .active CSS state. Warm
   that exact state only while the canonical covered prewarm pass is running, then
   use a light transition guard around every real enter/leave so the heavy Profile
   background never owns the tap frame. No route/state/event ownership changes. */
const MGW_MOBILE_PROFILE_MEDIA = '(max-width: 640px), (pointer: coarse)';
const MGW_PROFILE_ROUTE_SETTLE_MS = 360;
let mgwProfileRouteSettleTimer = 0;
let mgwExactProfileWarmStarted = false;

function isMgwMobileProfilePresentation(){
  return typeof window.matchMedia === 'function'
    && window.matchMedia(MGW_MOBILE_PROFILE_MEDIA).matches;
}

function currentMgwShellRoute(){
  return String(document.querySelector('.screen.active')?.dataset.screen || '').trim();
}

function routeFromMgwNavigationTarget(target){
  if (!(target instanceof Element)) return '';
  const shellButton = target.closest('[data-shell-nav]');
  if (shellButton instanceof HTMLElement) return String(shellButton.dataset.shellNav || '').trim();
  if (target.closest('#profileOpen')) return 'profile';
  return '';
}

function beginMgwProfileRouteSettle(){
  if (!isMgwMobileProfilePresentation()) return;
  document.documentElement.classList.add('mgw-profile-route-settling');
  if (mgwProfileRouteSettleTimer) window.clearTimeout(mgwProfileRouteSettleTimer);
  mgwProfileRouteSettleTimer = window.setTimeout(() => {
    mgwProfileRouteSettleTimer = 0;
    document.documentElement.classList.remove('mgw-profile-route-settling');
  }, MGW_PROFILE_ROUTE_SETTLE_MS);
}

function handleMgwProfileRouteIntent(event){
  if (!isMgwMobileProfilePresentation()) return;
  const targetRoute = routeFromMgwNavigationTarget(event.target);
  if (!targetRoute) return;
  const currentRoute = currentMgwShellRoute();
  if (targetRoute === 'profile' || currentRoute === 'profile') beginMgwProfileRouteSettle();
}

// pointerdown gets the lightweight visual guard in place before the click task.
// click is a fallback for WebViews / keyboard activation without Pointer Events.
document.addEventListener('pointerdown', handleMgwProfileRouteIntent, true);
document.addEventListener('click', handleMgwProfileRouteIntent, true);

function armMgwExactProfileWarm(){
  if (!isMgwMobileProfilePresentation()) return;
  const screen = document.getElementById('screen-profile');
  if (!(screen instanceof HTMLElement)) return;

  // main-v110-handoff-shell owns whether this warmup is safe (no active-game
  // reload). We only augment its already-covered prewarm pass, so this cannot
  // introduce a second Profile warm path or race an active match.
  const observer = new MutationObserver(() => {
    if (mgwExactProfileWarmStarted || !screen.classList.contains('mgw-profile-prewarm-pass')) return;
    mgwExactProfileWarmStarted = true;
    observer.disconnect();

    const profileNav = document.querySelector('[data-shell-nav="profile"]');
    const screenWasActive = screen.classList.contains('active');
    const navWasActive = profileNav instanceof HTMLElement && profileNav.classList.contains('active');

    if (!screenWasActive) screen.classList.add('active');
    if (profileNav instanceof HTMLElement && !navWasActive) profileNav.classList.add('active');

    // Force one exact active-style calculation under the preloader. This also
    // warms the Profile icon's active filter, which otherwise first compiles on
    // the user's tap on low-end Android WebViews.
    void screen.offsetHeight;
    if (profileNav instanceof HTMLElement) void profileNav.offsetHeight;

    window.requestAnimationFrame(() => {
      window.requestAnimationFrame(() => {
        if (!screenWasActive) screen.classList.remove('active');
        if (profileNav instanceof HTMLElement && !navWasActive) profileNav.classList.remove('active');
        void screen.offsetHeight;
      });
    });
  });

  observer.observe(screen, { attributes:true, attributeFilter:['class'] });
}

document.addEventListener('mgw:app-ready', armMgwExactProfileWarm, { once:true });
