let initialized = false;
let resizeTimer = 0;

export function initProfileReversiHardSquare(){
  const screen = document.getElementById('screen-profile');
  if (!(screen instanceof HTMLElement)) return;

  scheduleHardSquareRepair(screen);
  if (initialized) return;
  initialized = true;

  screen.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    if (target.closest('[data-profile-game-tab="reversi"], [data-profile-game-cosmetic^="game-reversi-"]')) {
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
  const run = () => enforceProfileReversiSquares(screen);
  queueMicrotask(run);
  if (typeof globalThis.requestAnimationFrame === 'function') {
    globalThis.requestAnimationFrame(() => globalThis.requestAnimationFrame(run));
  } else {
    globalThis.setTimeout(run, 0);
  }
  globalThis.setTimeout(run, 80);
}

function enforceProfileReversiSquares(screen){
  if (!(screen instanceof HTMLElement)) return;
  const reversiTab = screen.querySelector('[data-profile-game-tab="reversi"]');
  const active = reversiTab instanceof HTMLElement
    && (reversiTab.classList.contains('active') || reversiTab.getAttribute('aria-selected') === 'true');
  if (!active) return;

  screen.querySelectorAll('.profile-v2-game-card .store-v2-game-preview[data-game-type="reversi"]').forEach(preview => {
    if (!(preview instanceof HTMLElement)) return;
    const width = preview.getBoundingClientRect().width;
    if (!(width > 0)) return;
    const px = `${Math.round(width * 100) / 100}px`;

    preview.dataset.mgwProfileReversiHardSquare = '1';
    setImportant(preview, 'box-sizing', 'border-box');
    setImportant(preview, 'position', 'relative');
    setImportant(preview, 'display', 'grid');
    setImportant(preview, 'place-items', 'center');
    setImportant(preview, 'width', '100%');
    setImportant(preview, 'max-width', '100%');
    setImportant(preview, 'height', px);
    setImportant(preview, 'min-height', px);
    setImportant(preview, 'max-height', 'none');
    setImportant(preview, 'aspect-ratio', '1 / 1');
    setImportant(preview, 'margin', '0');
    setImportant(preview, 'padding', '0');
    setImportant(preview, 'overflow', 'hidden');

    const artwork = preview.querySelector(':scope > .mgw-reversi-preview');
    if (!(artwork instanceof HTMLElement)) return;
    setImportant(artwork, 'position', 'absolute');
    setImportant(artwork, 'inset', '0');
    setImportant(artwork, 'display', 'grid');
    setImportant(artwork, 'place-items', 'center');
    setImportant(artwork, 'width', '100%');
    setImportant(artwork, 'height', '100%');
    setImportant(artwork, 'max-width', 'none');
    setImportant(artwork, 'max-height', 'none');

    const board = artwork.querySelector(':scope > .mgw-rv-board');
    if (!(board instanceof HTMLElement)) return;
    setImportant(board, 'box-sizing', 'border-box');
    setImportant(board, 'width', '84%');
    setImportant(board, 'max-width', 'none');
    setImportant(board, 'height', 'auto');
    setImportant(board, 'min-height', '0');
    setImportant(board, 'aspect-ratio', '1 / 1');
    setImportant(board, 'margin', '0');
    setImportant(board, 'transform', 'none');
  });
}

function setImportant(element, property, value){
  element.style.setProperty(property, value, 'important');
}
