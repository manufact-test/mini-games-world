import { initProfileScreen as initCheckersParityProfileScreen } from './mgw-profile-checkers-parity.js?v=3&mvp19_6=checkers-profile-manual-repair-v3';
import { initProfileCheckersHardSquare } from './mgw-profile-checkers-hard-square-v1.js?v=1&mvp19_6=profile-board-effect-hard-square-v1';
import { initProfileReversiParity } from './mgw-profile-reversi-parity.js?v=2&mvp19_7=reversi-profile-parity-v1&card_geometry=full-square-v2';
import { initProfileReversiHardSquare } from './mgw-profile-reversi-hard-square-v1.js?v=1&mvp19_7=profile-hard-square-v1';
import { initProfileGoParity } from './mgw-profile-go-parity.js?v=2&mvp19_8=go-profile-corrective-v2';
import { initProfileGoHardSquare } from './mgw-profile-go-hard-square-v1.js?v=1&mvp19_8=go-profile-hard-square-v1';
import { initProfileDominoParity } from './mgw-profile-domino-parity.js?v=1&mvp19_9=store-profile-parity-8x5-v1';
import { initProfileDominoHardRatio } from './mgw-profile-domino-hard-ratio-v1.js?v=1&mvp19_9=hard-8x5-v1';
import { initProfileFourInARowParity } from './mgw-profile-four-in-a-row-parity.js?v=8&four_profile=live-previews-v3&four_module=export-v11&geometry=7x6&fx=victory-test-exact-v3&effect2=random-chain-v4&victory=overdrive-v3&copy=compact-v3';
import { initProfileBattleshipParity } from './mgw-profile-battleship-parity.js?v=1&mvp19_12=profile-store-parity-v1&store=polish-v3&geometry=square&header=steel-ship&effects=unchanged-v2';

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

function ensureProfileChessLayoutStyles(){
  if (document.querySelector('link[data-mgw-profile-chess-layout-v2]')) return;

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwProfileChessLayoutV2 = '1';
  link.href = new URL('../../css/games/chess/profile-parity-layout-v2.css?v=5&mvp19_5=profile-card-sheet-fit-v3&secondary=clean-v1&desktop_tabs=panel-only-v1', import.meta.url).href;
  document.head.appendChild(link);
}

function ensureProfileGameCosmeticsRepairStyles(){
  const href = new URL('../../css/screens/profile-game-cosmetics-parity-v1.css?v=3&mvp19_6=profile-card-visual-repair-v3', import.meta.url).href;
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