import { initProfileScreen as initChessParityProfileScreen } from './mgw-profile-chess-parity.js?v=1&mvp19_5=chess-profile-store-parity-v1';

ensureProfileChessLayoutStyles();

export function initProfileScreen(){
  ensureProfileChessLayoutStyles();
  initChessParityProfileScreen();
  installGameTabScrollStability();
}

function ensureProfileChessLayoutStyles(){
  if (document.querySelector('link[data-mgw-profile-chess-layout-v2]')) return;

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwProfileChessLayoutV2 = '1';
  link.href = new URL('../../css/games/chess/profile-parity-layout-v2.css?v=3&mvp19_5=profile-card-sheet-fit-v2&secondary=clean-v1&desktop_tabs=instant-v1', import.meta.url).href;
  document.head.appendChild(link);
}

/* The canonical Profile owner still rebuilds the full profile root when the user
   changes the game-cosmetics tab. If the user is already scrolled down to that
   collection, removing/recreating the focused tab can make the browser clamp the
   first switch back toward the top. Keep the canonical click/render owner, but
   preserve the actual scroll carriers across that one synchronous rebuild. */
function installGameTabScrollStability(){
  const screen = document.getElementById('screen-profile');
  if (!(screen instanceof HTMLElement) || screen.dataset.profileGameTabScrollStable === '1') return;
  screen.dataset.profileGameTabScrollStable = '1';

  let pending = null;

  screen.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    const tab = target?.closest('[data-profile-game-tab]');
    if (!(tab instanceof HTMLElement) || tab.classList.contains('active')) {
      pending = null;
      return;
    }

    pending = {
      gameType:String(tab.dataset.profileGameTab || ''),
      restore:scrollSnapshot(screen),
      restoreFocus:document.activeElement === tab || tab.contains(document.activeElement),
    };
  }, true);

  screen.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    const tab = target?.closest('[data-profile-game-tab]');
    const snapshot = pending;
    pending = null;
    if (!(tab instanceof HTMLElement) || !snapshot) return;

    const restore = () => {
      snapshot.restore();
      if (snapshot.restoreFocus) {
        const nextTab = [...screen.querySelectorAll('[data-profile-game-tab]')]
          .find(node => String(node?.dataset?.profileGameTab || '') === snapshot.gameType);
        if (nextTab instanceof HTMLElement && document.activeElement !== nextTab) {
          try { nextTab.focus({ preventScroll:true }); } catch (_) {}
        }
        snapshot.restore();
      }
    };

    // Base Profile click handling is registered before this wrapper and completes
    // its synchronous root render first. Restore before paint, then once more in
    // the next animation frame so layout/scroll anchoring cannot expose a jump.
    restore();
    if (typeof globalThis.requestAnimationFrame === 'function') {
      globalThis.requestAnimationFrame(restore);
    }
  });
}

function scrollSnapshot(screen){
  const candidates = [
    screen.querySelector('.content'),
    screen,
    document.scrollingElement,
  ];
  const seen = new Set();
  const positions = [];

  candidates.forEach(node => {
    if (!(node instanceof Element) || seen.has(node)) return;
    seen.add(node);
    positions.push({ node, top:node.scrollTop, left:node.scrollLeft });
  });

  const windowX = globalThis.scrollX || 0;
  const windowY = globalThis.scrollY || 0;

  return () => {
    positions.forEach(({ node, top, left }) => {
      node.scrollTop = top;
      node.scrollLeft = left;
    });
    if (typeof globalThis.scrollTo === 'function' && (globalThis.scrollX !== windowX || globalThis.scrollY !== windowY)) {
      globalThis.scrollTo(windowX, windowY);
    }
  };
}
