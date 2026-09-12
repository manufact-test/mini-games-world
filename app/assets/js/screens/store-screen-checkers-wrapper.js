import {
  initStoreScreen as initBaseStoreScreen,
  openStoreTab as openBaseStoreTab,
  openStoreSheet as openBaseStoreSheet,
} from './store-screen-intent-wrapper.js?v=19&mvp19_6=accepted-base-preserved';
import { api } from '../api/client.js?v=34';

const STORE_API_REPAIR_HOOK = Symbol.for('mgw.store.checkers-full-store-parity.v1');
let initialized = false;
let activeEffectPreview = null;
let effectCleanupTimer = 0;

ensureCheckersCosmeticStyles();
installStoreApiRepairHooks();

export function initStoreScreen(){
  const result = initBaseStoreScreen();
  upgradeCheckersStorePresentation();
  if (!initialized) {
    initialized = true;
    installStoreRepairIntents();
    installCheckersEffectPreviewIntents();
  }
  return result;
}

export async function openStoreTab(){
  const result = await openBaseStoreTab();
  upgradeCheckersStorePresentation();
  return result;
}

export async function openStoreSheet(){
  const result = await openBaseStoreSheet();
  upgradeCheckersStorePresentation();
  return result;
}

function ensureCheckersCosmeticStyles(){
  const existing = document.querySelector('link[data-mgw-checkers-cosmetics]');
  const nextHref = new URL('../../css/games/checkers/cosmetics.css?v=3&mvp19_6=full-store-v1', import.meta.url).href;
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== nextHref) existing.href = nextHref;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersCosmetics = 'mvp19-6-full-store-v1';
  link.href = nextHref;
  document.head.appendChild(link);
}

function installStoreApiRepairHooks(){
  ['cosmeticStoreStatus','cosmeticStorePurchase','cosmeticStoreEquip','cosmeticStoreUnequip'].forEach(methodName => {
    const current = api?.[methodName];
    if (typeof current !== 'function' || current[STORE_API_REPAIR_HOOK]) return;
    const wrapped = async (...args) => {
      try {
        return await current.apply(api, args);
      } finally {
        scheduleCheckersStoreRepair();
      }
    };
    Object.defineProperty(wrapped, STORE_API_REPAIR_HOOK, { value:true });
    api[methodName] = wrapped;
  });
}

function installStoreRepairIntents(){
  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    if (!target.closest('[data-store-v2-tab="games"], [data-store-v2-tab="bundles"], [data-store-v2-game], [data-store-v2-buy], #storeV2ConfirmBuy, [data-store-v2-equip], [data-store-v2-unequip]')) return;
    scheduleCheckersStoreRepair();
  });
}

function installCheckersEffectPreviewIntents(){
  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    const preview = target?.closest('.store-v2-game-preview[data-game-type="checkers"][data-cosmetic-layer="effect"]');
    if (!(preview instanceof HTMLElement)) return;
    event.preventDefault();
    playCheckersEffectPreview(preview);
  });
  document.addEventListener('keydown', event => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const target = event.target instanceof Element ? event.target : null;
    const preview = target?.closest('.store-v2-game-preview[data-game-type="checkers"][data-cosmetic-layer="effect"]');
    if (!(preview instanceof HTMLElement)) return;
    event.preventDefault();
    playCheckersEffectPreview(preview);
  });
}

function playCheckersEffectPreview(preview){
  stopCheckersEffectPreview();
  activeEffectPreview = preview;
  preview.classList.remove('is-playing');
  void preview.offsetWidth;
  preview.classList.add('is-playing');
  if (globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
    preview.classList.add('is-reduced-preview');
  }
  effectCleanupTimer = globalThis.setTimeout(() => {
    if (activeEffectPreview === preview) stopCheckersEffectPreview();
  }, 1100);
}

function stopCheckersEffectPreview(){
  if (effectCleanupTimer) globalThis.clearTimeout(effectCleanupTimer);
  effectCleanupTimer = 0;
  if (activeEffectPreview instanceof HTMLElement) {
    activeEffectPreview.classList.remove('is-playing','is-reduced-preview');
  }
  activeEffectPreview = null;
}

function scheduleCheckersStoreRepair(){
  const repair = () => upgradeCheckersStorePresentation();
  queueMicrotask(repair);
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(repair);
  else globalThis.setTimeout(repair, 0);
}

function upgradeCheckersStorePresentation(){
  const roots = [
    document.querySelector('[data-store-v2-panel="games"]'),
    document.querySelector('[data-store-v2-panel="bundles"]'),
    document.getElementById('sheet'),
  ];
  roots.forEach(root => {
    if (!(root instanceof HTMLElement)) return;
    renameCheckersSelector(root);
    upgradeCheckersHeader(root);
    upgradeCheckersEffectPreviews(root);
  });
}

function renameCheckersSelector(root){
  root.querySelectorAll('[data-store-v2-game="checkers"]').forEach(button => {
    if (button instanceof HTMLElement && button.textContent !== 'Шашки') button.textContent = 'Шашки';
  });
}

function upgradeCheckersHeader(root){
  const head = root.querySelector('.store-v2-game-head[data-store-game-type="checkers"]');
  if (!(head instanceof HTMLElement)) return;
  const title = head.querySelector('h2');
  if (title instanceof HTMLElement) title.textContent = 'Шашки';
  const marks = head.querySelectorAll('.store-v2-game-head-marks b');
  marks.forEach((mark, index) => {
    if (!(mark instanceof HTMLElement)) return;
    mark.textContent = '';
    mark.classList.add('checkers-store-head-piece');
    mark.classList.toggle('black', index === 0);
    mark.classList.toggle('white', index === 1);
    mark.setAttribute('aria-hidden', 'true');
  });
}

function upgradeCheckersEffectPreviews(root){
  root.querySelectorAll('.store-v2-game-preview[data-game-type="checkers"][data-cosmetic-layer="effect"]').forEach(preview => {
    if (!(preview instanceof HTMLElement)) return;
    preview.tabIndex = 0;
    preview.setAttribute('role', 'button');
    const variant = String(preview.dataset.cosmeticVariant || '');
    const label = ({
      move:'Показать эффект хода',
      capture:'Показать эффект взятия',
      promotion:'Показать эффект превращения в дамку',
    })[variant] || 'Показать эффект шашек';
    preview.setAttribute('aria-label', label);
    preview.title = 'Нажмите, чтобы посмотреть';
  });
}
