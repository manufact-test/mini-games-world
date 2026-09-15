let initialized = false;
let resizeTimer = 0;
const HEIGHT_RATIO = 5 / 8;

export function initProfileDominoHardRatio(){
  const screen = document.getElementById('screen-profile');
  if (!(screen instanceof HTMLElement)) return;

  scheduleHardRatioRepair(screen);
  if (initialized) return;
  initialized = true;

  screen.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    if (target.closest('[data-profile-game-tab="domino"], [data-profile-game-cosmetic^="game-domino-"]')) {
      scheduleHardRatioRepair(screen);
    }
  });

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (target?.closest('#mgwGameCosmeticEquip, #sheet')) scheduleHardRatioRepair(screen);
  });
  document.addEventListener('mgw:open-profile', () => scheduleHardRatioRepair(screen));
  document.addEventListener('mgw:cosmetic-inventory-changed', () => scheduleHardRatioRepair(screen));
  document.addEventListener('mgw:screen-changed', event => {
    if (event?.detail?.to === 'profile') scheduleHardRatioRepair(screen);
  });
  globalThis.addEventListener?.('resize', () => {
    if (resizeTimer) globalThis.clearTimeout(resizeTimer);
    resizeTimer = globalThis.setTimeout(() => {
      resizeTimer = 0;
      scheduleHardRatioRepair(screen);
    }, 40);
  }, { passive:true });
}

function scheduleHardRatioRepair(screen){
  const run = () => enforceProfileDominoRatio(screen);
  queueMicrotask(run);
  if (typeof globalThis.requestAnimationFrame === 'function') {
    globalThis.requestAnimationFrame(() => globalThis.requestAnimationFrame(run));
  } else {
    globalThis.setTimeout(run, 0);
  }
  globalThis.setTimeout(run, 80);
  globalThis.setTimeout(run, 260);
}

function enforceProfileDominoRatio(screen){
  if (!(screen instanceof HTMLElement)) return;
  const dominoTab = screen.querySelector('[data-profile-game-tab="domino"]');
  const active = dominoTab instanceof HTMLElement
    && (dominoTab.classList.contains('active') || dominoTab.getAttribute('aria-selected') === 'true');

  if (active) {
    screen.querySelectorAll('.profile-v2-game-card .store-v2-game-preview[data-game-type="domino"]').forEach(preview => {
      if (preview instanceof HTMLElement) enforcePreview(preview, 'card');
    });
  }

  document.querySelectorAll('#sheet .profile-v2-game-preview-wrap .store-v2-game-preview[data-game-type="domino"]').forEach(preview => {
    if (preview instanceof HTMLElement) enforcePreview(preview, 'sheet');
  });
}

function enforcePreview(preview, context){
  const width = preview.getBoundingClientRect().width;
  if (!(width > 0)) return;
  const height = Math.round(width * HEIGHT_RATIO * 100) / 100;
  const px = `${height}px`;

  preview.dataset.mgwProfileDominoHardRatio = context;
  setImportant(preview, 'box-sizing', 'border-box');
  setImportant(preview, 'position', 'relative');
  setImportant(preview, 'display', 'block');
  setImportant(preview, 'width', '100%');
  setImportant(preview, 'max-width', context === 'sheet' ? '320px' : '100%');
  setImportant(preview, 'height', px);
  setImportant(preview, 'min-height', px);
  setImportant(preview, 'max-height', px);
  setImportant(preview, 'aspect-ratio', '8 / 5');
  setImportant(preview, 'margin', context === 'sheet' ? '0 auto' : '0');
  setImportant(preview, 'padding', '0');
  setImportant(preview, 'overflow', 'hidden');

  const artwork = preview.querySelector(':scope > .mgw-domino-preview');
  if (!(artwork instanceof HTMLElement)) return;
  setImportant(artwork, 'position', 'absolute');
  setImportant(artwork, 'inset', '0');
  setImportant(artwork, 'display', 'block');
  setImportant(artwork, 'width', '100%');
  setImportant(artwork, 'height', '100%');
  setImportant(artwork, 'max-width', 'none');
  setImportant(artwork, 'max-height', 'none');
}

function setImportant(element, property, value){
  element.style.setProperty(property, value, 'important');
}
