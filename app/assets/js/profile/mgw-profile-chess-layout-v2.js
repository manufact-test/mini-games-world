import { initProfileScreen as initCheckersParityProfileScreen } from './mgw-profile-checkers-parity.js?v=2&mvp19_6=checkers-full-profile-store-parity-v1';

ensureProfileChessLayoutStyles();
ensureProfileGameCosmeticsRepairStyles();

export function initProfileScreen(){
  ensureProfileChessLayoutStyles();
  ensureProfileGameCosmeticsRepairStyles();
  initCheckersParityProfileScreen();
}

function ensureProfileChessLayoutStyles(){
  if (document.querySelector('link[data-mgw-profile-chess-layout-v2]')) return;

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwProfileChessLayoutV2 = '1';
  link.href = new URL('../../css/games/chess/profile-parity-layout-v2.css?v=4&mvp19_5=profile-card-sheet-fit-v2&secondary=clean-v1&desktop_tabs=panel-only-v1', import.meta.url).href;
  document.head.appendChild(link);
}

function ensureProfileGameCosmeticsRepairStyles(){
  const href = new URL('../../css/screens/profile-game-cosmetics-parity-v1.css?v=2&mvp19_6=profile-card-visual-repair-v2', import.meta.url).href;
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
