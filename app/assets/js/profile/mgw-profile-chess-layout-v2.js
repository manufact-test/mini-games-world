import { initProfileScreen as initCheckersParityProfileScreen } from './mgw-profile-checkers-parity.js?v=3&mvp19_6=checkers-profile-manual-repair-v3';
import { initProfileCheckersHardSquare } from './mgw-profile-checkers-hard-square-v1.js?v=1&mvp19_6=profile-board-effect-hard-square-v1';
import { initProfileReversiParity } from './mgw-profile-reversi-parity.js?v=1&mvp19_7=reversi-profile-parity-v1';

const profileChessArtworkPrewarm = [];

ensureProfileChessLayoutStyles();
ensureProfileGameCosmeticsRepairStyles();
ensureProfileGameCosmeticsManualRepairStyles();
ensureProfileCheckersStoreExactStyles();
prewarmProfileChessArtwork();

export function initProfileScreen(){
  ensureProfileChessLayoutStyles();
  ensureProfileGameCosmeticsRepairStyles();
  ensureProfileGameCosmeticsManualRepairStyles();
  ensureProfileCheckersStoreExactStyles();
  prewarmProfileChessArtwork();
  initCheckersParityProfileScreen();
  initProfileCheckersHardSquare();
  initProfileReversiParity();
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

function prewarmProfileChessArtwork(){
  if (profileChessArtworkPrewarm.length || typeof globalThis.Image !== 'function') return;
  ['wood','tournament-dark','marble','neon'].forEach(variant => {
    const image = new globalThis.Image();
    image.decoding = 'async';
    image.src = new URL(`../../css/games/chess/board-previews/${variant}.svg`, import.meta.url).href;
    profileChessArtworkPrewarm.push(image);
  });
}
