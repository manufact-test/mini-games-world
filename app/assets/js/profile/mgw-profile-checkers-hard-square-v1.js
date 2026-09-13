let initialized = false;
let resizeTimer = 0;

export function initProfileCheckersHardSquare(){
  const screen = document.getElementById('screen-profile');
  if (!(screen instanceof HTMLElement)) return;

  scheduleHardSquareRepair(screen);
  if (initialized) return;
  initialized = true;

  screen.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    if (target.closest('[data-profile-game-tab="checkers"], [data-profile-game-cosmetic]')) {
      scheduleHardSquareRepair(screen);
    }
  });

  document.addEventListener('mgw:open-profile', () => scheduleHardSquareRepair(screen));
  document.addEventListener('mgw:cosmetic-inventory-changed', () => scheduleHardSquareRepair(screen));
  document.addEventListener('mgw:screen-changed', event => {
    if (event?.detail?.to === 'profile') scheduleHardSquareRepair(screen);
  });
  globalThis.addEventListener?.('resize', () => {
    if (resizeTimer) globalThis.clearTimeout(resizeTimer);
    resizeTimer = globalThis.setTimeout(() => {
      resizeTimer = 0;
      scheduleHardSquareRepair(screen);
    }, 40);
  }, { passive:true });
}

function scheduleHardSquareRepair(screen){
  const run = () => enforceProfileCheckersSquares(screen);
  queueMicrotask(run);
  if (typeof globalThis.requestAnimationFrame === 'function') {
    globalThis.requestAnimationFrame(() => globalThis.requestAnimationFrame(run));
  } else {
    globalThis.setTimeout(run, 0);
  }
}

function enforceProfileCheckersSquares(screen){
  if (!(screen instanceof HTMLElement)) return;
  const checkersTab = screen.querySelector('[data-profile-game-tab="checkers"]');
  const active = checkersTab instanceof HTMLElement
    && (checkersTab.classList.contains('active') || checkersTab.getAttribute('aria-selected') === 'true');
  if (!active) return;

  screen.querySelectorAll('.profile-v2-game-card .store-v2-game-preview[data-game-type="checkers"][data-cosmetic-layer="theme"], .profile-v2-game-card .store-v2-game-preview[data-game-type="checkers"][data-cosmetic-layer="effect"]').forEach(preview => {
    if (!(preview instanceof HTMLElement)) return;
    const width = preview.getBoundingClientRect().width;
    if (!(width > 0)) return;
    const px = `${Math.round(width * 100) / 100}px`;

    preview.dataset.mgwProfileCheckersHardSquare = '1';
    setImportant(preview, 'box-sizing', 'border-box');
    setImportant(preview, 'display', 'grid');
    setImportant(preview, 'place-items', 'center');
    setImportant(preview, 'width', '100%');
    setImportant(preview, 'max-width', '100%');
    setImportant(preview, 'height', px);
    setImportant(preview, 'min-height', px);
    setImportant(preview, 'aspect-ratio', '1 / 1');
    setImportant(preview, 'padding', '0');
    setImportant(preview, 'overflow', 'hidden');

    const layer = String(preview.dataset.cosmeticLayer || '');
    const primitive = layer === 'theme'
      ? preview.querySelector(':scope > .store-v2-mini-checkers-board')
      : preview.querySelector(':scope > .store-v2-mini-checkers-effect');
    if (!(primitive instanceof HTMLElement)) return;

    setImportant(primitive, 'box-sizing', 'border-box');
    setImportant(primitive, 'width', '100%');
    setImportant(primitive, 'max-width', 'none');
    setImportant(primitive, 'height', '100%');
    setImportant(primitive, 'min-height', '0');
    setImportant(primitive, 'aspect-ratio', '1 / 1');
    setImportant(primitive, 'margin', '0');
    setImportant(primitive, 'transform', 'none');

    if (layer === 'theme') {
      setImportant(primitive, 'display', 'grid');
      setImportant(primitive, 'grid-template-columns', 'repeat(8,minmax(0,1fr))');
      setImportant(primitive, 'grid-template-rows', 'repeat(8,minmax(0,1fr))');
    } else {
      setImportant(primitive, 'display', 'block');
      const board = primitive.querySelector(':scope > .checkers-fx-board');
      if (board instanceof HTMLElement) {
        setImportant(board, 'inset', '0');
        setImportant(board, 'width', '100%');
        setImportant(board, 'height', '100%');
        setImportant(board, 'aspect-ratio', '1 / 1');
        setImportant(board, 'grid-template-columns', 'repeat(8,minmax(0,1fr))');
        setImportant(board, 'grid-template-rows', 'repeat(8,minmax(0,1fr))');
      }
    }
  });
}

function setImportant(element, property, value){
  element.style.setProperty(property, value, 'important');
}
