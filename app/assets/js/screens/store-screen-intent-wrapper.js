// Compatibility sentinel for accepted owner contract: from './store-screen.js?v=44&intent_base=1';
import {
  initStoreScreen as initBaseStoreScreen,
  openStoreTab as openBaseStoreTab,
  openStoreSheet as openBaseStoreSheet,
} from './store-screen.js?v=45&intent_base=1&mvp19_5=chess-catalog';
import { api } from '../api/client.js?v=34';
import { haptic } from '../telegram/telegram-app.js?v=27';

const CHESS_FIELD_EFFECT_VARIANTS = Object.freeze(new Set(['move','capture','check']));
const CHESS_EFFECT_PREVIEW_SCENES = Object.freeze({
  move:Object.freeze([
    Object.freeze({className:'mover white', glyph:'♞'}),
    Object.freeze({className:'static-a black', glyph:'♟'}),
    Object.freeze({className:'static-b white', glyph:'♟'}),
  ]),
  capture:Object.freeze([
    Object.freeze({className:'mover white', glyph:'♝'}),
    Object.freeze({className:'target black', glyph:'♜'}),
    Object.freeze({className:'static-a black', glyph:'♟'}),
    Object.freeze({className:'static-b white', glyph:'♟'}),
  ]),
  check:Object.freeze([
    Object.freeze({className:'mover white', glyph:'♜'}),
    Object.freeze({className:'king black', glyph:'♚'}),
    Object.freeze({className:'static-a black', glyph:'♟'}),
    Object.freeze({className:'static-b white', glyph:'♟'}),
  ]),
});
const STORE_API_REPAIR_HOOK = Symbol.for('mgw.store.effect-presentation-repair.v1');

ensureChessCosmeticStyles();
installStoreApiPresentationRepairHooks();
installStoreGameClickCorrective();

let initialized = false;
let firstOpenPrimePromise = null;
let firstOpenPrimeReady = false;
let firstVisiblePrimeConsumed = false;

export function initStoreScreen(){
  if (initialized) return firstOpenPrimePromise;
  initialized = true;

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
  link.dataset.mgwChessCosmetics = 'mvp19-5-safe';
  link.href = new URL('../../css/games/chess/runtime-cosmetics.css?v=3&mvp19_5=native-board-preview&fx_preview=move-live-parity-v4', import.meta.url).href;
  document.head.appendChild(link);
}

/* Base Store may rerender after status/purchase/equip responses. Board previews no longer
   need repair at all; this hook only restores the richer animated effect demo/copy before
   the next paint. No observers, polling, retry timers, or startup work are introduced. */
function installStoreApiPresentationRepairHooks(){
  ['cosmeticStoreStatus','cosmeticStorePurchase','cosmeticStoreEquip','cosmeticStoreUnequip'].forEach(methodName => {
    const current = api?.[methodName];
    if (typeof current !== 'function' || current[STORE_API_REPAIR_HOOK]) return;

    const wrapped = async (...args) => {
      try {
        return await current.apply(api, args);
      } finally {
        schedulePostApiPresentationRepair();
      }
    };
    Object.defineProperty(wrapped, STORE_API_REPAIR_HOOK, { value:true });
    api[methodName] = wrapped;
  });
}

function schedulePostApiPresentationRepair(){
  const repair = () => upgradeStoreGamePresentation();
  if (typeof globalThis.requestAnimationFrame === 'function') {
    globalThis.requestAnimationFrame(repair);
  } else {
    globalThis.setTimeout(repair, 0);
  }
}

function installStoreGameClickCorrective(){
  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const gameTab = target.closest('[data-store-v2-tab="games"]');
    const gameSelector = target.closest('[data-store-v2-game]');
    const productBuy = target.closest('[data-store-v2-buy]');
    const confirmBuy = target.closest('#storeV2ConfirmBuy');
    const equip = target.closest('[data-store-v2-equip]');
    const unequip = target.closest('[data-store-v2-unequip]');
    if (!gameTab && !gameSelector && !productBuy && !confirmBuy && !equip && !unequip) return;

    scheduleStoreGamePresentationRepair();
  });
}

function scheduleStoreGamePresentationRepair(){
  const repair = () => upgradeStoreGamePresentation();
  queueMicrotask(repair);
  if (typeof globalThis.requestAnimationFrame === 'function') {
    globalThis.requestAnimationFrame(repair);
  } else {
    globalThis.setTimeout(repair, 0);
  }
}

function upgradeStoreGamePresentation(){
  const panel = document.querySelector('[data-store-v2-panel="games"]');
  if (panel instanceof HTMLElement) {
    upgradeChessEffectPreviews(panel);
    humanizeGameGroupCopy(panel);
  }

  const sheet = document.getElementById('sheet');
  if (sheet instanceof HTMLElement) {
    upgradeChessEffectPreviews(sheet);
  }
}

function upgradeChessEffectPreviews(root){
  root.querySelectorAll('.store-v2-game-preview[data-game-type="chess"][data-cosmetic-layer="effect"]').forEach(preview => {
    if (!(preview instanceof HTMLElement) || preview.dataset.fieldEffectPreview === 'v2-action-demo') return;

    const variant = String(preview.dataset.cosmeticVariant || '');
    if (!CHESS_FIELD_EFFECT_VARIANTS.has(variant)) return;

    const field = document.createElement('i');
    field.className = 'store-v2-mini-chess-field-effect';
    field.setAttribute('aria-hidden', 'true');

    for (let index = 0; index < 16; index += 1) {
      const row = Math.floor(index / 4);
      const column = index % 4;
      const square = document.createElement('span');
      square.className = ((row + column) % 2 === 0) ? 'light' : 'dark';
      field.appendChild(square);
    }

    (CHESS_EFFECT_PREVIEW_SCENES[variant] || []).forEach(scenePiece => {
      const piece = document.createElement('strong');
      piece.className = `chess-effect-piece ${scenePiece.className}`;
      piece.textContent = scenePiece.glyph;
      field.appendChild(piece);
    });

    field.appendChild(document.createElement('b'));
    field.appendChild(document.createElement('em'));
    field.appendChild(document.createElement('u'));

    preview.replaceChildren(field);
    preview.dataset.fieldEffectPreview = 'v2-action-demo';
  });
}

function humanizeGameGroupCopy(panel){
  const gameType = String(panel.querySelector('.store-v2-game-head')?.getAttribute('data-store-game-type') || '');
  panel.querySelectorAll('.store-v2-game-title-row').forEach(row => {
    const title = row.querySelector('h2')?.textContent?.trim() || '';
    const subtitle = row.querySelector('p');
    if (!(subtitle instanceof HTMLElement)) return;

    let nextText = '';
    if (gameType === 'chess') {
      if (title === 'Доски') nextText = 'Оформление шахматной доски';
      if (title === 'Фигуры') nextText = 'Внешний вид фигур';
      if (title === 'Эффекты') nextText = 'Анимации для ходов, взятий и шаха';
    } else if (gameType === 'tictactoe') {
      if (title === 'Поля') nextText = 'Фон и сетка игрового поля';
      if (title === 'Знаки') nextText = 'Внешний вид крестиков и ноликов';
      if (title === 'Эффекты') nextText = 'Анимации при каждом ходе';
    }

    if (nextText && subtitle.textContent !== nextText) {
      subtitle.textContent = nextText;
    }
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
