import { initMgwProfileReactions as initBaseReactions } from './mgw-profile-reactions.js?v=2&mvp19_3=ingame-corrective-base';

let initialized = false;
let observer = null;
let resizeBound = false;
let mobileProfilePressBoundaryBound = false;

export function initMgwProfileReactions(){
  initBaseReactions();
  if (initialized) return;
  initialized = true;

  const screen = document.getElementById('screen-game');
  if (!(screen instanceof HTMLElement)) return;

  observer?.disconnect();
  observer = new MutationObserver(records => {
    stabilizeMobileReactionBubbles(records);
    queueMicrotask(syncReactionHeader);
  });
  observer.observe(screen, { childList:true, subtree:true });

  document.addEventListener('mgw:screen-changed', () => queueMicrotask(syncReactionHeader));
  if (!resizeBound) {
    resizeBound = true;
    window.addEventListener('resize', () => queueMicrotask(positionPalette), { passive:true });
  }
  if (!mobileProfilePressBoundaryBound) {
    mobileProfilePressBoundaryBound = true;
    window.addEventListener('pointerdown', suppressLegacyMobileProfilePressFeedback, true);
  }

  queueMicrotask(syncReactionHeader);
}

function isMobilePresentation(){
  return typeof window.matchMedia === 'function'
    && window.matchMedia('(max-width: 640px), (pointer: coarse)').matches;
}

function stabilizeMobileReactionBubbles(records){
  if (!isMobilePresentation()) return;
  const screen = document.getElementById('screen-game');
  if (!(screen instanceof HTMLElement)) return;

  const bubbles = [];
  for (const record of records || []) {
    for (const node of record.addedNodes || []) {
      if (!(node instanceof Element)) continue;
      if (node.matches('.mgw-live-reaction')) bubbles.push(node);
      node.querySelectorAll?.('.mgw-live-reaction').forEach(candidate => bubbles.push(candidate));
    }
  }

  for (const bubble of bubbles) {
    if (!(bubble instanceof HTMLElement) || bubble.dataset.mobileStableReaction === '1') continue;
    const card = bubble.parentElement?.closest('.game-player');
    if (!(card instanceof HTMLElement)) continue;

    const avatar = card.querySelector(':scope > .game-player-avatar');
    const origin = avatar instanceof HTMLElement ? avatar : card;
    const screenRect = screen.getBoundingClientRect();
    const originRect = origin.getBoundingClientRect();

    screen.querySelectorAll(':scope > .mgw-live-reaction[data-mobile-stable-reaction="1"]').forEach(node => {
      if (node !== bubble) node.remove();
    });

    bubble.dataset.mobileStableReaction = '1';
    bubble.classList.remove('from-card');
    bubble.classList.add('from-avatar');
    bubble.style.setProperty('--mgw-reaction-origin-x', `${Math.round(originRect.left - screenRect.left + originRect.width / 2)}px`);
    bubble.style.setProperty('--mgw-reaction-origin-y', `${Math.round(originRect.top - screenRect.top + originRect.height / 2)}px`);
    screen.append(bubble);
  }
}

function suppressLegacyMobileProfilePressFeedback(event){
  if (!isMobilePresentation()) return;
  const target = event.target instanceof Element
    ? event.target.closest('#appBottomNav [data-shell-nav="profile"]')
    : null;
  if (!(target instanceof HTMLButtonElement) || target.disabled) return;
  if (String(document.querySelector('.screen.active')?.dataset.screen || '') === 'profile') return;

  // The earlier reaction corrective painted Profile selection on pointerdown and
  // another capture listener then delayed the real route by two animation frames.
  // On Telegram mobile that produced a sticky button plus a one-frame flash.
  // Stop only that legacy pointerdown helper; do not preventDefault and do not
  // touch click, so the canonical shell click handler owns navigation exactly as
  // it does on desktop and the browser keeps its native pressed-state feedback.
  event.stopPropagation();
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
