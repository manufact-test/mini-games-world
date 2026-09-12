import { initProfileScreen as initChessParityProfileScreen } from './mgw-profile-chess-parity.js?v=1&mvp19_5=chess-profile-store-parity-v1';

ensureProfileChessLayoutStyles();

export function initProfileScreen(){
  ensureProfileChessLayoutStyles();
  initChessParityProfileScreen();
}

function ensureProfileChessLayoutStyles(){
  if (document.querySelector('link[data-mgw-profile-chess-layout-v2]')) return;

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwProfileChessLayoutV2 = '1';
  link.href = new URL('../../css/games/chess/profile-parity-layout-v2.css?v=3&mvp19_5=profile-card-sheet-fit-v2&secondary=clean-v1&desktop_tabs=instant-v1', import.meta.url).href;
  document.head.appendChild(link);
}
