import { initProfileScreen as initChessParityProfileScreen } from './mgw-profile-chess-parity.js?v=1&mvp19_5=chess-profile-store-parity-v1';

ensureProfileChessLayoutStyles();

export function initProfileScreen(){
  ensureProfileChessLayoutStyles();
  initChessParityProfileScreen();
  installDesktopGameTabFastFeedback();
}

function ensureProfileChessLayoutStyles(){
  if (document.querySelector('link[data-mgw-profile-chess-layout-v2]')) return;

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwProfileChessLayoutV2 = '1';
  link.href = new URL('../../css/games/chess/profile-parity-layout-v2.css?v=3&mvp19_5=profile-card-sheet-fit-v2&secondary=clean-v1&desktop_tabs=instant-v1', import.meta.url).href;
  document.head.appendChild(link);
}

function installDesktopGameTabFastFeedback(){
  const finePointer = globalThis.matchMedia?.('(hover:hover) and (pointer:fine)');
  if (!finePointer?.matches) return;

  const screen = document.getElementById('screen-profile');
  if (!(screen instanceof HTMLElement) || screen.dataset.profileGameTabFastFeedback === '1') return;
  screen.dataset.profileGameTabFastFeedback = '1';

  screen.addEventListener('pointerdown', event => {
    const target = event.target instanceof Element ? event.target : null;
    const tab = target?.closest('[data-profile-game-tab]');
    if (!(tab instanceof HTMLElement) || tab.classList.contains('active')) return;

    screen.querySelectorAll('[data-profile-game-tab]').forEach(button => {
      const active = button === tab;
      button.classList.toggle('active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
  }, { passive:true });
}
