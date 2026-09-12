import { initProfileScreen as initChessParityProfileScreen } from './mgw-profile-chess-parity.js?v=1&mvp19_5=chess-profile-store-parity-v1';

ensureProfileChessLayoutStyles();

export function initProfileScreen(){
  ensureProfileChessLayoutStyles();
  initChessParityProfileScreen();
  installProfileGameTabScrollStability();
}

function ensureProfileChessLayoutStyles(){
  if (document.querySelector('link[data-mgw-profile-chess-layout-v2]')) return;

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwProfileChessLayoutV2 = '1';
  link.href = new URL('../../css/games/chess/profile-parity-layout-v2.css?v=3&mvp19_5=profile-card-sheet-fit-v2&secondary=clean-v1&desktop_tabs=instant-v1', import.meta.url).href;
  document.head.appendChild(link);
}

function installProfileGameTabScrollStability(){
  const screen = document.getElementById('screen-profile');
  if (!(screen instanceof HTMLElement) || screen.dataset.profileGameTabScrollStability === '1') return;
  screen.dataset.profileGameTabScrollStability = '1';

  let pendingSnapshot = null;

  // The canonical Profile tab owner rebuilds the entire Profile root synchronously.
  // On the first game-tab switch Chromium can lose its scroll anchor when that root
  // is replaced and jump the Profile toward the top. Capture the real scroll owners
  // before the canonical click handler runs, then restore them after its synchronous
  // rebuild. No visual state is changed here and no polling/observer/timer is added.
  screen.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target?.closest('[data-profile-game-tab]')) return;
    pendingSnapshot = captureProfileScrollOwners(screen);
  }, true);

  screen.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target?.closest('[data-profile-game-tab]') || !pendingSnapshot) return;
    restoreProfileScrollOwners(pendingSnapshot);
    pendingSnapshot = null;
  });
}

function captureProfileScrollOwners(screen){
  const content = screen.querySelector('.content');
  const candidates = [document.scrollingElement, screen, content];
  const seen = new Set();

  return candidates
    .filter(node => node && !seen.has(node) && seen.add(node))
    .map(node => ({
      node,
      top:Number(node.scrollTop || 0),
      left:Number(node.scrollLeft || 0),
    }));
}

function restoreProfileScrollOwners(snapshot){
  snapshot.forEach(({ node, top, left }) => {
    if (!node || !node.isConnected) return;
    node.scrollTop = top;
    node.scrollLeft = left;
  });
}
