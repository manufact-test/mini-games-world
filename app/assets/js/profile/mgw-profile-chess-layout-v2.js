import { initProfileScreen as initCheckersParityProfileScreen } from './mgw-profile-checkers-parity.js?v=4&mvp19_6=checkers-profile-manual-repair-v3&mvp27_1=localized-v1';
import { initProfileCheckersHardSquare } from './mgw-profile-checkers-hard-square-v1.js?v=1&mvp19_6=profile-board-effect-hard-square-v1';
import { initProfileReversiParity } from './mgw-profile-reversi-parity.js?v=3&mvp19_7=reversi-profile-parity-v1&card_geometry=full-square-v2&mvp27_1=localized-v1';
import { initProfileReversiHardSquare } from './mgw-profile-reversi-hard-square-v1.js?v=1&mvp19_7=profile-hard-square-v1';
import { initProfileGoParity } from './mgw-profile-go-parity.js?v=3&mvp19_8=go-profile-corrective-v2&mvp27_1=localized-v1';
import { initProfileGoHardSquare } from './mgw-profile-go-hard-square-v1.js?v=1&mvp19_8=go-profile-hard-square-v1';
import { initProfileDominoParity } from './mgw-profile-domino-parity.js?v=2&mvp19_9=store-profile-parity-8x5-v1&mvp27_1=localized-v1';
import { initProfileDominoHardRatio } from './mgw-profile-domino-hard-ratio-v1.js?v=1&mvp19_9=hard-8x5-v1';
import { initProfileFourInARowParity } from './mgw-profile-four-in-a-row-parity.js?v=9&four_profile=live-previews-v3&four_module=export-v11&geometry=7x6&fx=victory-test-exact-v3&effect2=random-chain-v4&victory=overdrive-v3&copy=compact-v3&mvp27_1=localized-v1';
import { initProfileBattleshipParity } from './mgw-profile-battleship-parity.js?v=15&mvp19_12=profile-four-parity-v3&store=preview-parity-v14&geometry=square&header=steel-ship&neon_fleet=tube-v4&fleet_preview=svg-models-v3&neon_map_ships=white-v1&preview_geometry=svg-circles-v6&hydration=observer-v1&inline_owner=svg-v5&effects=live-parity-destroy-v3&copy=four-pattern&mvp27_1=localized-v1';
import { t } from '@mgw/i18n';

const profileChessArtworkPrewarm = [];
const PROFILE_GAME_TAB_DRAG_THRESHOLD = 5;
let profileGameTabDrag = null;
let suppressProfileGameTabClick = false;

ensureProfileChessLayoutStyles();
ensureProfileGameCosmeticsRepairStyles();
ensureProfileGameCosmeticsManualRepairStyles();
ensureProfileCheckersStoreExactStyles();
ensureProfileReversiTabsTouchFixStyles();
ensureProfileReversiStoreExactStyles();
prewarmProfileChessArtwork();

export function initProfileScreen(){
  ensureProfileChessLayoutStyles();
  ensureProfileGameCosmeticsRepairStyles();
  ensureProfileGameCosmeticsManualRepairStyles();
  ensureProfileCheckersStoreExactStyles();
  ensureProfileReversiTabsTouchFixStyles();
  ensureProfileReversiStoreExactStyles();
  prewarmProfileChessArtwork();
  prepareProfileGameTabInputMode();
  initCheckersParityProfileScreen();
  initProfileCheckersHardSquare();
  initProfileReversiParity();
  initProfileReversiHardSquare();
  initProfileGoParity();
  initProfileGoHardSquare();
  initProfileDominoParity();
  initProfileDominoHardRatio();
  initProfileFourInARowParity();
  initProfileBattleshipParity();
}

function prepareProfileGameTabInputMode(){
  const screen = document.getElementById('screen-profile');
  if (!(screen instanceof HTMLElement) || screen.dataset.mgwGameTabsInputV2 === '1') return;

  screen.dataset.mgwGameTabsInputV2 = '1';
  screen.dataset.mgwGameTabsScroller = '1';
  screen.dataset.mgwGameTabsScrollerMode = 'delayed-capture-v2';
  screen.dataset.mgwGameTabsScrollerAffordance = 'arrows-wheel-v1';
  prepareProfileGameTabAffordance(screen);

  // The old Profile rail captured the pointer on pointerdown. In Telegram/WebView
  // that retargeted pointerup/click to the whole rail, so the nested game button
  // never received a real click. Keep pointerdown passive; capture only after an
  // actual horizontal drag crosses the threshold. Touch pointers remain native.
  screen.addEventListener('pointerdown', event => {
    if (event.pointerType !== 'mouse' || event.button !== 0) return;
    const target = event.target instanceof Element ? event.target : null;
    const strip = target?.closest('.profile-v2-game-tabs');
    if (!(strip instanceof HTMLElement) || strip.scrollWidth <= strip.clientWidth) return;

    profileGameTabDrag = {
      strip,
      pointerId:event.pointerId,
      startX:event.clientX,
      startScrollLeft:strip.scrollLeft,
      moved:false,
      captured:false,
    };
    suppressProfileGameTabClick = false;
  });

  screen.addEventListener('pointermove', event => {
    const drag = profileGameTabDrag;
    if (!drag || drag.pointerId !== event.pointerId) return;

    const delta = event.clientX - drag.startX;
    if (!drag.moved && Math.abs(delta) < PROFILE_GAME_TAB_DRAG_THRESHOLD) return;

    if (!drag.moved) {
      drag.moved = true;
      drag.strip.classList.add('is-dragging');
      drag.strip.setPointerCapture?.(event.pointerId);
      drag.captured = true;
    }

    drag.strip.scrollLeft = drag.startScrollLeft - delta;
    event.preventDefault();
  });

  const finishDrag = event => {
    const drag = profileGameTabDrag;
    if (!drag || drag.pointerId !== event.pointerId) return;

    suppressProfileGameTabClick = drag.moved;
    drag.strip.classList.remove('is-dragging');
    if (drag.captured) drag.strip.releasePointerCapture?.(event.pointerId);
    profileGameTabDrag = null;
  };

  screen.addEventListener('pointerup', finishDrag);
  screen.addEventListener('pointercancel', finishDrag);

  screen.addEventListener('click', event => {
    if (!suppressProfileGameTabClick) return;
    const target = event.target instanceof Element ? event.target : null;
    suppressProfileGameTabClick = false;
    if (!target?.closest('[data-profile-game-tab]')) return;
    event.preventDefault();
    event.stopImmediatePropagation();
  }, true);
}


function prepareProfileGameTabAffordance(screen){
  let decorateScheduled = false;

  const scheduleDecorate = () => {
    if (decorateScheduled) return;
    decorateScheduled = true;
    queueMicrotask(() => {
      decorateScheduled = false;
      screen.querySelectorAll('.profile-v2-game-tabs').forEach(strip => ensureProfileGameTabRail(strip));
    });
  };

  const observer = new MutationObserver(scheduleDecorate);
  observer.observe(screen, { childList:true, subtree:true });

  screen.addEventListener('click', event => {
    const arrow = event.target instanceof Element ? event.target.closest('[data-profile-game-tabs-scroll]') : null;
    if (!(arrow instanceof HTMLButtonElement)) return;
    const shell = arrow.closest('.profile-v2-game-tabs-shell');
    const strip = shell?.querySelector('.profile-v2-game-tabs');
    if (!(strip instanceof HTMLElement)) return;
    event.preventDefault();
    event.stopPropagation();
    const direction = Number(arrow.dataset.profileGameTabsScroll || 0);
    const step = Math.max(120, Math.min(260, Math.round(strip.clientWidth * 0.72)));
    strip.scrollBy({ left:direction * step, behavior:'smooth' });
  });

  screen.addEventListener('wheel', event => {
    const target = event.target instanceof Element ? event.target : null;
    const strip = target?.closest('.profile-v2-game-tabs');
    if (!(strip instanceof HTMLElement) || strip.scrollWidth <= strip.clientWidth + 2) return;
    if (Math.abs(event.deltaX) >= Math.abs(event.deltaY) || Math.abs(event.deltaY) < 1) return;
    const before = strip.scrollLeft;
    strip.scrollLeft += event.deltaY;
    if (strip.scrollLeft !== before && event.cancelable) event.preventDefault();
  }, { passive:false });

  screen.addEventListener('scroll', event => {
    const strip = event.target;
    if (strip instanceof HTMLElement && strip.classList.contains('profile-v2-game-tabs')) {
      updateProfileGameTabRail(strip);
    }
  }, true);

  globalThis.addEventListener?.('resize', scheduleDecorate, { passive:true });
  scheduleDecorate();
}

function ensureProfileGameTabRail(strip){
  if (!(strip instanceof HTMLElement)) return;
  let shell = strip.parentElement;
  if (!(shell instanceof HTMLElement) || !shell.classList.contains('profile-v2-game-tabs-shell')) {
    shell = document.createElement('div');
    shell.className = 'profile-v2-game-tabs-shell';

    const left = document.createElement('button');
    left.type = 'button';
    left.className = 'profile-v2-game-tabs-arrow is-left';
    left.dataset.profileGameTabsScroll = '-1';
    left.setAttribute('aria-label', t('arena.scroll_left'));
    left.textContent = '‹';

    const right = document.createElement('button');
    right.type = 'button';
    right.className = 'profile-v2-game-tabs-arrow is-right';
    right.dataset.profileGameTabsScroll = '1';
    right.setAttribute('aria-label', t('arena.scroll_right'));
    right.textContent = '›';

    strip.before(shell);
    shell.append(left, strip, right);
  }

  updateProfileGameTabRail(strip);
  globalThis.requestAnimationFrame?.(() => updateProfileGameTabRail(strip));
}

function updateProfileGameTabRail(strip){
  const shell = strip?.closest?.('.profile-v2-game-tabs-shell');
  if (!(strip instanceof HTMLElement) || !(shell instanceof HTMLElement)) return;
  const maxScroll = Math.max(0, strip.scrollWidth - strip.clientWidth);
  const hasOverflow = maxScroll > 3;
  shell.classList.toggle('has-overflow', hasOverflow);

  const left = shell.querySelector('[data-profile-game-tabs-scroll="-1"]');
  const right = shell.querySelector('[data-profile-game-tabs-scroll="1"]');
  if (left instanceof HTMLButtonElement) {
    left.hidden = !hasOverflow;
    left.disabled = !hasOverflow || strip.scrollLeft <= 3;
  }
  if (right instanceof HTMLButtonElement) {
    right.hidden = !hasOverflow;
    right.disabled = !hasOverflow || strip.scrollLeft >= maxScroll - 3;
  }
}

function ensureProfileChessLayoutStyles(){
  if (document.querySelector('link[data-mgw-profile-chess-layout-v2]')) return;

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwProfileChessLayoutV2 = '1';
  link.href = new URL('../../css/games/chess/profile-parity-layout-v2.css?v=5&mvp19_5=profile-card-sheet-fit-v3&secondary=clean-v1&desktop_tabs=panel-only-v1', import.meta.url).href;
  document.head.appendChild(link);
}

function ensureProfileGameCosmeticsRepairStyles(){
  const href = new URL('../../css/screens/profile-game-cosmetics-parity-v1.css?v=4&mvp19_6=profile-card-visual-repair-v3&manual_acceptance=scroll-arrows-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-profile-game-cosmetics-parity]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    return;
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.setAttribute('data-mgw-profile-game-cosmetics-parity', '1');
  link.href = href;
  document.head.appendChild(link);
}

function ensureProfileGameCosmeticsManualRepairStyles(){
  const href = new URL('../../css/screens/profile-game-cosmetics-manual-repair-v3.css?v=4&mvp19_6=checkers-store-parity-exact-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-profile-game-cosmetics-manual-repair-v3]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    return;
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.setAttribute('data-mgw-profile-game-cosmetics-manual-repair-v3', '1');
  link.href = href;
  document.head.appendChild(link);
}

function ensureProfileCheckersStoreExactStyles(){
  const href = new URL('../../css/screens/profile-checkers-store-exact-v2.css?v=1&mvp19_6=board-effect-full-square-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-profile-checkers-store-exact-v2]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    document.head.appendChild(existing);
    return;
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.setAttribute('data-mgw-profile-checkers-store-exact-v2', '1');
  link.href = href;
  document.head.appendChild(link);
}

function ensureProfileReversiTabsTouchFixStyles(){
  const href = new URL('../../css/screens/profile-reversi-tabs-touch-fix-v1.css?v=1&mvp19_7=tappable-tabs-and-separated-mark-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-profile-reversi-tabs-touch-fix]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    document.head.appendChild(existing);
  } else {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.setAttribute('data-mgw-profile-reversi-tabs-touch-fix', '1');
    link.href = href;
    document.head.appendChild(link);
  }

  const parityHref = new URL('../../css/screens/profile-reversi-store-parity-v1.css?v=2&mvp19_7=full-card-store-square-v2', import.meta.url).href;
  const parity = document.querySelector('link[data-mgw-profile-reversi-parity]');
  if (parity instanceof HTMLLinkElement && parity.href !== parityHref) parity.href = parityHref;
}

function ensureProfileReversiStoreExactStyles(){
  const href = new URL('../../css/screens/profile-reversi-store-exact-v2.css?v=1&mvp19_7=direct-card-full-square-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-profile-reversi-store-exact-v2]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    document.head.appendChild(existing);
    return;
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.setAttribute('data-mgw-profile-reversi-store-exact-v2', '1');
  link.href = href;
  document.head.appendChild(link);
}

function prewarmProfileChessArtwork(){
  if (profileChessArtworkPrewarm.length || typeof globalThis.Image !== 'function') return;
  ['wood','tournament-dark','marble','neon'].forEach(variant => {
    const image = new globalThis.Image();
    image.decoding = 'async';
    image.src = new URL(`../../css/games/chess/board-previews/${variant}.svg`, import.meta.url).href;
    profileChessArtworkPrewarm.push(image);
  });
}