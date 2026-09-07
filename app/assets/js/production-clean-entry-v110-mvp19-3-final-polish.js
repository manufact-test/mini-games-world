import './production-clean-entry-v110.js?v=1131&mvp16=canonical-avatar-owner&mvp17=history-single-owner&mvp19=avatar-presentation&mvp19_4=character-identity&art=illustrated-raster&roster=portrait-v5&mvp19_3=name-colors&mvp19_3_6=profile-badge-avatar-overlay&mvp19_3_7=profile-frames&mvp19_3_8=profile-frame-preview-polish&mvp19_3_9=badge-avatar-card&mvp19_3_10=profile-frame-name-polish&mvp19_3_11=profile-badge-avatar-shape&mvp19_3_12=profile-frame-avatar-card-parity&mvp19_3_15=profile-backgrounds&mvp19_3_16=profile-backgrounds-ux-corrective&mvp19_3_17=background-route-hydration&mvp19_3_20=avatar-store-action&mvp19_3_21=avatar-store-action-post-app-ready&mvp19_3_22=store-freeze-idempotent-decorator&mvp19_3_23=frame-avatar-actions&mvp19_3_24=profile-card-parity';
import { initMgwPurchaseFeedback } from './commerce/mgw-purchase-feedback.js?v=1';

initMgwPurchaseFeedback();

/* MVP-19.3 Entry Effects — authoritative live image owner.
   Store already proves the canonical SVG artwork is loadable. Telegram manual
   review showed the live card-background path can render the overlay/text while
   omitting the artwork, so live presentation must have one concrete replaced
   element owner. Mount the same canonical SVGs as real <img> nodes directly under
   the overlay. Store presentation, lifecycle, equip, skip and game state are not
   changed here. */
const MGW_ENTRY_LIVE_ART = Object.freeze({
  'entry-01':'/app/assets/media/cosmetics/entry-effects/store-entry-01-celestial-gate.svg?asset=live-node-svg-v2',
  'entry-02':'/app/assets/media/cosmetics/entry-effects/store-entry-02-king-ascension.svg?asset=live-node-svg-v2',
  'entry-03':'/app/assets/media/cosmetics/entry-effects/store-entry-03-lord-blade.svg?asset=live-node-svg-v2',
});

function mountMgwEntryLiveArt(layer){
  if (!(layer instanceof HTMLElement) || !layer.classList.contains('mgw-entry-effect-layer')) return;
  const cards = [...layer.querySelectorAll('.mgw-entry-effect-live-card[data-entry-effect-variant]')];
  for (const card of cards) {
    const variant = String(card.dataset.entryEffectVariant || '').trim();
    const src = MGW_ENTRY_LIVE_ART[variant];
    if (!src) continue;
    const playerIndex = String(card.dataset.playerIndex || '0');
    const key = `${variant}:${playerIndex}`;
    if (layer.querySelector(`.mgw-entry-effect-live-art[data-entry-live-art-key="${key}"]`)) continue;

    const image = document.createElement('img');
    image.className = 'mgw-entry-effect-live-art';
    image.dataset.entryLiveArtKey = key;
    image.dataset.entryEffectVariant = variant;
    image.alt = '';
    image.setAttribute('aria-hidden', 'true');
    image.decoding = 'async';
    image.loading = 'eager';
    image.fetchPriority = 'high';
    image.src = src;
    Object.assign(image.style, {
      position:'absolute',
      left:'50%',
      top:'50%',
      zIndex:'1',
      display:'block',
      width:variant === 'entry-03' ? 'min(144vw, 720px)' : (variant === 'entry-02' ? 'min(136vw, 680px)' : 'min(132vw, 640px)'),
      height:variant === 'entry-03' ? 'min(88vh, 620px)' : (variant === 'entry-02' ? 'min(86vh, 590px)' : 'min(82vh, 560px)'),
      maxWidth:'none',
      objectFit:'contain',
      objectPosition:'center',
      opacity:'1',
      visibility:'visible',
      pointerEvents:'none',
      transform:'translate(-50%, -50%) scale(.86)',
      transformOrigin:'50% 55%',
      filter:variant === 'entry-01'
        ? 'drop-shadow(0 20px 34px rgba(0,0,0,.58)) drop-shadow(0 0 38px rgba(92,173,255,.42))'
        : 'drop-shadow(0 24px 38px rgba(0,0,0,.62)) drop-shadow(0 0 44px rgba(255,181,55,.44))',
    });
    const grid = layer.querySelector('.mgw-entry-effect-live-grid');
    if (grid instanceof HTMLElement) layer.insertBefore(image, grid);
    else layer.append(image);

    if (!window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches && typeof image.animate === 'function') {
      const frames = variant === 'entry-03'
        ? [
            { opacity:0, transform:'translate(-50%, -50%) scale(.72) rotate(-5deg)' },
            { opacity:1, offset:.24, transform:'translate(-50%, -50%) scale(1.03) rotate(1deg)' },
            { opacity:1, transform:'translate(-50%, -50%) scale(1) rotate(0deg)' },
          ]
        : variant === 'entry-02'
          ? [
              { opacity:0, transform:'translate(-50%, -42%) scale(.76)' },
              { opacity:1, offset:.3, transform:'translate(-50%, -50%) scale(1.03)' },
              { opacity:1, transform:'translate(-50%, -50%) scale(1)' },
            ]
          : [
              { opacity:0, transform:'translate(-50%, -50%) scale(.68)' },
              { opacity:1, offset:.32, transform:'translate(-50%, -50%) scale(1.04)' },
              { opacity:1, transform:'translate(-50%, -50%) scale(1)' },
            ];
      image.animate(frames, {
        duration:variant === 'entry-03' ? 3600 : (variant === 'entry-02' ? 3000 : 2400),
        easing:'cubic-bezier(.16,.84,.18,1)',
        fill:'both',
      });
    } else {
      image.style.transform = 'translate(-50%, -50%) scale(1)';
    }
  }
}

function scanMgwEntryLiveArt(root = document){
  if (root instanceof HTMLElement && root.classList.contains('mgw-entry-effect-layer')) mountMgwEntryLiveArt(root);
  root.querySelectorAll?.('.mgw-entry-effect-layer').forEach(mountMgwEntryLiveArt);
}

function armMgwEntryLiveArtOwner(){
  scanMgwEntryLiveArt(document);
  const observer = new MutationObserver(records => {
    for (const record of records) {
      for (const node of record.addedNodes) {
        if (!(node instanceof HTMLElement)) continue;
        scanMgwEntryLiveArt(node);
      }
    }
  });
  observer.observe(document.documentElement, { childList:true, subtree:true });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', armMgwEntryLiveArtOwner, { once:true });
else armMgwEntryLiveArtOwner();

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