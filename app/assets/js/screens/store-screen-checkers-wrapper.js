import {
  initStoreScreen as initBaseStoreScreen,
  openStoreTab as openBaseStoreTab,
  openStoreSheet as openBaseStoreSheet,
} from './store-screen-intent-wrapper.js?v=19&mvp19_6=accepted-base-preserved';
import { api } from '../api/client.js?v=34';

const CHECKERS_BOARD_COPY = Object.freeze({
  wood:'Тёплое дерево с мягкой фактурой',
  dark:'Строгая тёмная доска с высоким контрастом',
  marble:'Светлый камень с холодными прожилками',
  neon:'Тёмная доска с цианово-фиолетовым свечением',
});
const STORE_API_REPAIR_HOOK = Symbol.for('mgw.store.checkers-board-parity.v2');
let initialized = false;

ensureCheckersCosmeticStyles();
installStoreApiRepairHooks();

export function initStoreScreen(){
  const result = initBaseStoreScreen();
  upgradeCheckersStorePresentation();
  if (!initialized) {
    initialized = true;
    installStoreRepairIntents();
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
  if (existing instanceof HTMLLinkElement) {
    const nextHref = new URL('../../css/games/checkers/cosmetics.css?v=2&mvp19_6=store-corrective', import.meta.url).href;
    if (existing.href !== nextHref) existing.href = nextHref;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersCosmetics = 'mvp19-6-store-corrective';
  link.href = new URL('../../css/games/checkers/cosmetics.css?v=2&mvp19_6=store-corrective', import.meta.url).href;
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
    if (!target.closest('[data-store-v2-tab="games"], [data-store-v2-game], [data-store-v2-buy], #storeV2ConfirmBuy, [data-store-v2-equip], [data-store-v2-unequip]')) return;
    scheduleCheckersStoreRepair();
  });
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
    document.getElementById('sheet'),
  ];
  roots.forEach(root => {
    if (!(root instanceof HTMLElement)) return;
    renameCheckersSelector(root);
    const head = root.querySelector('.store-v2-game-head[data-store-game-type="checkers"]');
    if (head instanceof HTMLElement) upgradeCheckersCatalog(root, head);
    upgradeCheckersBoardCards(root);
  });
}

function renameCheckersSelector(root){
  root.querySelectorAll('[data-store-v2-game="checkers"]').forEach(button => {
    if (button instanceof HTMLElement && button.textContent !== 'Шашки') button.textContent = 'Шашки';
  });
}

function upgradeCheckersCatalog(root, head){
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

  const groups = [...root.querySelectorAll('.store-v2-game-group')];
  groups.forEach((group, index) => {
    if (!(group instanceof HTMLElement)) return;
    const hasProducts = group.querySelector('[data-store-game-product="checkers"]') instanceof HTMLElement;
    if (index === 0) {
      group.hidden = false;
      const groupTitle = group.querySelector('.store-v2-game-title-row h2');
      const subtitle = group.querySelector('.store-v2-game-title-row p');
      if (groupTitle instanceof HTMLElement) groupTitle.textContent = 'Доски';
      if (subtitle instanceof HTMLElement) subtitle.textContent = 'Оформление шашечной доски';
    } else if (!hasProducts) {
      group.hidden = true;
    }
  });
}

function upgradeCheckersBoardCards(root){
  root.querySelectorAll('.store-v2-game-product[data-store-game-product="checkers"]').forEach(product => {
    if (!(product instanceof HTMLElement)) return;
    const preview = product.querySelector('.store-v2-game-preview[data-cosmetic-layer="theme"]');
    if (!(preview instanceof HTMLElement)) return;
    const variant = String(preview.dataset.cosmeticVariant || '');
    if (!Object.prototype.hasOwnProperty.call(CHECKERS_BOARD_COPY, variant)) return;

    preview.dataset.gameType = 'checkers';
    preview.replaceChildren(checkersBoardPreview());

    const kind = product.querySelector('.store-v2-game-product-copy > span');
    const description = product.querySelector('.store-v2-game-product-copy > p');
    if (kind instanceof HTMLElement) kind.textContent = 'Шашечная доска';
    if (description instanceof HTMLElement) description.textContent = CHECKERS_BOARD_COPY[variant];
  });

  root.querySelectorAll('.store-v2-confirm .store-v2-game-preview[data-game-type="checkers"][data-cosmetic-layer="theme"]').forEach(preview => {
    if (!(preview instanceof HTMLElement)) return;
    preview.replaceChildren(checkersBoardPreview());
  });
}

function checkersBoardPreview(){
  const board = document.createElement('i');
  board.className = 'store-v2-mini-checkers-board';
  board.setAttribute('aria-hidden', 'true');
  for (let cell = 0; cell < 64; cell += 1) {
    const row = Math.floor(cell / 8);
    const col = cell % 8;
    const dark = (row + col) % 2 === 1;
    const square = document.createElement('span');
    square.className = dark ? 'dark' : 'light';
    if (dark && (row < 3 || row > 4)) {
      const piece = document.createElement('i');
      piece.className = row < 3 ? 'black' : 'white';
      square.appendChild(piece);
    }
    board.appendChild(square);
  }
  return board;
}
