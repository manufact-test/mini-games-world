import { initProfileScreen as initCheckersParityProfileScreen } from './mgw-profile-checkers-parity.js?v=1&mvp19_6=checkers-board-parity';

ensureProfileChessLayoutStyles();

export function initProfileScreen(){
  ensureProfileChessLayoutStyles();
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
