import { initMgwProfileReactions as initBaseReactions } from './mgw-profile-reactions.js?v=2&mvp19_3=ingame-corrective-base';

let initialized = false;
let observer = null;
let resizeBound = false;

export function initMgwProfileReactions(){
  initBaseReactions();
  if (initialized) return;
  initialized = true;

  const screen = document.getElementById('screen-game');
  if (!(screen instanceof HTMLElement)) return;

  observer?.disconnect();
  observer = new MutationObserver(() => queueMicrotask(syncReactionHeader));
  observer.observe(screen, { childList:true, subtree:true });

  document.addEventListener('mgw:screen-changed', () => queueMicrotask(syncReactionHeader));
  if (!resizeBound) {
    resizeBound = true;
    window.addEventListener('resize', () => queueMicrotask(positionPalette), { passive:true });
  }

  queueMicrotask(syncReactionHeader);
}

function syncReactionHeader(){
  const screen = document.getElementById('screen-game');
  const host = screen?.querySelector('.turn-actions');
  const toolbar = document.getElementById('mgwReactionToolbar');
  if (!(host instanceof HTMLElement) || !(toolbar instanceof HTMLElement)) return;

  if (toolbar.parentElement !== host) {
    const rules = host.querySelector('[data-game-rules-current]');
    host.insertBefore(toolbar, rules || host.firstChild);
  }
  positionPalette();
}

function positionPalette(){
  const toolbar = document.getElementById('mgwReactionToolbar');
  const palette = toolbar?.querySelector('.mgw-reaction-palette');
  if (!(toolbar instanceof HTMLElement) || !(palette instanceof HTMLElement)) return;

  const toolbarRect = toolbar.getBoundingClientRect();
  const paletteRect = palette.getBoundingClientRect();
  const gutter = 8;
  const width = Math.max(1, paletteRect.width || palette.scrollWidth || 1);
  const height = Math.max(1, paletteRect.height || palette.scrollHeight || 1);
  const maxLeft = Math.max(gutter, window.innerWidth - width - gutter);
  const left = Math.min(maxLeft, Math.max(gutter, toolbarRect.right - width));
  const below = toolbarRect.bottom + 6;
  const maxTop = Math.max(gutter, window.innerHeight - height - gutter);
  const top = Math.min(maxTop, below);

  palette.style.position = 'fixed';
  palette.style.left = `${Math.round(left)}px`;
  palette.style.right = 'auto';
  palette.style.top = `${Math.round(top)}px`;
  palette.style.transform = 'none';
}
