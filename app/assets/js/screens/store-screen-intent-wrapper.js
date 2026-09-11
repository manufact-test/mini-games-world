// Compatibility sentinel for accepted owner contract: from './store-screen.js?v=44&intent_base=1';
import {
  initStoreScreen as initBaseStoreScreen,
  openStoreTab as openBaseStoreTab,
  openStoreSheet as openBaseStoreSheet,
} from './store-screen.js?v=45&intent_base=1&mvp19_5=chess-catalog';
import { haptic } from '../telegram/telegram-app.js?v=27';

ensureChessCosmeticStyles();
ensureChessBoardPreviewCorrectiveStyles();
installStoreGamePresentationCorrective();

let initialized = false;
let firstOpenPrimePromise = null;
let firstOpenPrimeReady = false;
let firstVisiblePrimeConsumed = false;

export function initStoreScreen(){
  if (initialized) return firstOpenPrimePromise;
  initialized = true;

  // Desktop keeps the existing Store owner/listeners and idle refresh path.
  // Mobile keeps the accepted intent-only listener so there is still no extra
  // background Store refresh racing decorator-owned cosmetic DOM after reveal.
  if (!usesMobileIntentOnlyStore()) {
    initBaseStoreScreen();
  } else {
    document.addEventListener('click', event => {
      const target = event.target instanceof Element ? event.target : null;
      const trigger = target?.closest('#storeOpen');
      if (!trigger) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      void openStoreTab();
    }, true);
  }

  // The accepted base Store owner still performs the actual render/load work.
  // Prime it once under the intro preloader, then remember only whether that
  // completed presentation is safe to publish on the first real Store tap.
  firstOpenPrimePromise = canPrimeStoreUnderPreloader()
    ? Promise.resolve(openBaseStoreTab())
      .then(() => {
        upgradeStoreGamePresentation();
        firstOpenPrimeReady = hasCompletedStorePresentation();
      })
      .catch(() => {
        firstOpenPrimeReady = false;
      })
    : Promise.resolve();
  globalThis.__MGW_STORE_FIRST_OPEN_READY__ = firstOpenPrimePromise;

  return firstOpenPrimePromise;
}

async function openStoreTab(){
  // This wrapper owns no Store state, catalogue, purchase or equipment logic.
  // It only avoids asking the accepted base owner to open the exact same primed
  // DOM twice before the first visible paint; later opens delegate normally.
  if (canConsumePrimedFirstPresentation()) {
    firstVisiblePrimeConsumed = true;
    upgradeStoreGamePresentation();
    haptic('light');
    return;
  }

  const result = await openBaseStoreTab();
  upgradeStoreGamePresentation();
  return result;
}

async function openStoreSheet(){
  const result = await openBaseStoreSheet();
  upgradeStoreGamePresentation();
  return result;
}

export { openStoreTab, openStoreSheet };

function ensureChessCosmeticStyles(){
  if (document.querySelector('link[data-mgw-chess-cosmetics]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwChessCosmetics = 'mvp19-5';
  link.href = new URL('../../css/games/chess/runtime-cosmetics.css?v=3&mvp19_5=chess-board-preview-v2', import.meta.url).href;
  document.head.appendChild(link);
}

function ensureChessBoardPreviewCorrectiveStyles(){
  if (document.querySelector('style[data-mgw-chess-board-preview-v2]')) return;
  const style = document.createElement('style');
  style.dataset.mgwChessBoardPreviewV2 = 'true';
  style.textContent = `
    .store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"]{
      display:grid!important;
      place-items:center!important;
      padding:0!important;
      border:0!important;
      background:transparent!important;
      box-shadow:none!important;
      overflow:visible!important;
    }
    .store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"] .store-v2-mini-chess-board{
      display:grid!important;
      grid-template-columns:repeat(8,minmax(0,1fr))!important;
      grid-template-rows:repeat(8,minmax(0,1fr))!important;
      width:108px!important;
      height:108px!important;
      max-width:108px!important;
      aspect-ratio:1!important;
      gap:0!important;
      padding:0!important;
      margin:0!important;
      overflow:hidden!important;
      box-sizing:border-box!important;
      border:1px solid rgba(12,18,25,.72)!important;
      border-radius:8px!important;
      font-style:normal!important;
      box-shadow:0 10px 24px rgba(0,0,0,.30)!important;
    }
    .store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"] .store-v2-mini-chess-board>span{
      display:block!important;
      min-width:0!important;
      min-height:0!important;
      width:auto!important;
      height:auto!important;
      padding:0!important;
      margin:0!important;
      border:0!important;
      border-radius:0!important;
      color:transparent!important;
      font-size:0!important;
    }
    .store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"] .store-v2-mini-chess-board>span>*{display:none!important}

    .store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"][data-cosmetic-variant="wood"] .store-v2-mini-chess-board{background:linear-gradient(145deg,#5d321b,#25150d)!important}
    .store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"][data-cosmetic-variant="wood"] .store-v2-mini-chess-board>span.light{background:linear-gradient(135deg,rgba(255,229,184,.16),transparent 48%),repeating-linear-gradient(7deg,rgba(92,48,21,.07) 0 2px,transparent 2px 8px),#d7aa73!important}
    .store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"][data-cosmetic-variant="wood"] .store-v2-mini-chess-board>span.dark{background:linear-gradient(135deg,rgba(255,197,121,.08),transparent 52%),repeating-linear-gradient(-8deg,rgba(38,20,10,.11) 0 2px,transparent 2px 9px),#80502f!important}

    .store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"][data-cosmetic-variant="tournament-dark"] .store-v2-mini-chess-board{background:#0b1019!important}
    .store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"][data-cosmetic-variant="tournament-dark"] .store-v2-mini-chess-board>span.light{background:linear-gradient(145deg,#8090a8,#68778f)!important}
    .store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"][data-cosmetic-variant="tournament-dark"] .store-v2-mini-chess-board>span.dark{background:linear-gradient(145deg,#273448,#1a2637)!important}

    .store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"][data-cosmetic-variant="marble"] .store-v2-mini-chess-board{background:linear-gradient(145deg,#d9dde4,#787f89)!important}
    .store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"][data-cosmetic-variant="marble"] .store-v2-mini-chess-board>span.light{background:linear-gradient(121deg,transparent 0 36%,rgba(113,126,140,.16) 38% 39%,transparent 41% 68%,rgba(255,255,255,.48) 70% 71%,transparent 73%),linear-gradient(145deg,#f2efe9,#d9d9d4)!important}
    .store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"][data-cosmetic-variant="marble"] .store-v2-mini-chess-board>span.dark{background:linear-gradient(58deg,transparent 0 29%,rgba(235,239,243,.16) 31% 32%,transparent 34% 67%,rgba(48,57,68,.22) 69% 70%,transparent 72%),linear-gradient(145deg,#7d858c,#565e68)!important}

    .store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"][data-cosmetic-variant="neon"] .store-v2-mini-chess-board{background:#050711!important;box-shadow:0 10px 24px rgba(0,0,0,.34),0 0 22px rgba(129,68,255,.23)!important}
    .store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"][data-cosmetic-variant="neon"] .store-v2-mini-chess-board>span.light{background:linear-gradient(145deg,rgba(64,226,255,.16),rgba(21,44,71,.72))!important;box-shadow:inset 0 0 0 1px rgba(80,222,255,.08)!important}
    .store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"][data-cosmetic-variant="neon"] .store-v2-mini-chess-board>span.dark{background:linear-gradient(145deg,rgba(182,72,255,.18),rgba(35,20,65,.80))!important;box-shadow:inset 0 0 0 1px rgba(202,82,255,.10)!important}

    @media(max-width:380px){
      .store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"] .store-v2-mini-chess-board{width:104px!important;height:104px!important;max-width:104px!important}
    }
  `;
  document.head.appendChild(style);
}

function installStoreGamePresentationCorrective(){
  const root = document.documentElement;
  if (!(root instanceof HTMLElement)) return;

  const observer = new MutationObserver(records => {
    for (const record of records) {
      if (record.addedNodes.length > 0) {
        queueMicrotask(upgradeStoreGamePresentation);
        break;
      }
    }
  });
  observer.observe(root, { childList:true, subtree:true });
  queueMicrotask(upgradeStoreGamePresentation);
}

function upgradeStoreGamePresentation(){
  upgradeChessBoardPreviews();
  humanizeGameGroupCopy();
}

function upgradeChessBoardPreviews(){
  document.querySelectorAll('.store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="theme"] .store-v2-mini-chess-board').forEach(board => {
    if (!(board instanceof HTMLElement) || board.dataset.boardPreviewParity === '8x8-v2') return;

    const fragment = document.createDocumentFragment();
    for (let index = 0; index < 64; index += 1) {
      const row = Math.floor(index / 8);
      const column = index % 8;
      const square = document.createElement('span');
      square.className = ((row + column) % 2 === 0) ? 'light' : 'dark';
      square.setAttribute('aria-hidden', 'true');
      fragment.appendChild(square);
    }

    board.replaceChildren(fragment);
    board.dataset.boardPreviewParity = '8x8-v2';
  });
}

function humanizeGameGroupCopy(){
  document.querySelectorAll('.store-v2-content[data-store-v2-panel="games"], [data-store-v2-panel="games"]').forEach(panel => {
    if (!(panel instanceof HTMLElement)) return;
    const gameType = String(panel.querySelector('.store-v2-game-head')?.getAttribute('data-store-game-type') || '');
    panel.querySelectorAll('.store-v2-game-title-row').forEach(row => {
      const title = row.querySelector('h2')?.textContent?.trim() || '';
      const subtitle = row.querySelector('p');
      if (!(subtitle instanceof HTMLElement)) return;

      if (gameType === 'chess') {
        if (title === 'Доски') subtitle.textContent = 'Оформление шахматной доски';
        if (title === 'Фигуры') subtitle.textContent = 'Внешний вид фигур';
        if (title === 'Эффекты') subtitle.textContent = 'Анимации для ходов, взятий и шаха';
        return;
      }

      if (gameType === 'tictactoe') {
        if (title === 'Поля') subtitle.textContent = 'Фон и сетка игрового поля';
        if (title === 'Знаки') subtitle.textContent = 'Внешний вид крестиков и ноликов';
        if (title === 'Эффекты') subtitle.textContent = 'Анимации при каждом ходе';
      }
    });
  });
}

function canConsumePrimedFirstPresentation(){
  if (!firstOpenPrimeReady || firstVisiblePrimeConsumed) return false;
  const preloader = document.getElementById('preloader');
  return preloader instanceof HTMLElement
    && preloader.classList.contains('hidden')
    && hasCompletedStorePresentation();
}

function hasCompletedStorePresentation(){
  return document.querySelector('#storeTabSurface .store-v2-shell:not(.is-pending)') instanceof HTMLElement;
}

function canPrimeStoreUnderPreloader(){
  const preloader = document.getElementById('preloader');
  const gameScreen = document.getElementById('screen-game');
  return preloader instanceof HTMLElement
    && !preloader.classList.contains('hidden')
    && !(gameScreen instanceof HTMLElement && gameScreen.classList.contains('active'));
}

function usesMobileIntentOnlyStore(){
  return typeof window.matchMedia === 'function'
    && window.matchMedia('(max-width: 640px), (pointer: coarse)').matches;
}
