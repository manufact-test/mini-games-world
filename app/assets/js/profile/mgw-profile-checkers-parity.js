import { initProfileScreen as initChessParityProfileScreen } from './mgw-profile-chess-parity.js?v=1&mvp19_5=chess-profile-store-parity-v1';
import { api } from '../api/client.js?v=47';

const CHECKERS_PROFILE_ITEMS = Object.freeze({
  'game-checkers-board-wood':Object.freeze({ variant:'wood', name:'Деревянная доска' }),
  'game-checkers-board-dark':Object.freeze({ variant:'dark', name:'Тёмная доска' }),
  'game-checkers-board-marble':Object.freeze({ variant:'marble', name:'Мраморная доска' }),
  'game-checkers-board-neon':Object.freeze({ variant:'neon', name:'Неоновая доска' }),
});
const PROFILE_API_REPAIR_HOOK = Symbol.for('mgw.profile.checkers-board-parity.profile-v2.v1');
let initialized = false;

ensureCheckersPreviewStyles();
installProfileApiRepairHook();

export function initProfileScreen(){
  initChessParityProfileScreen();
  upgradeProfileCheckersPresentation();

  if (initialized) return;
  initialized = true;

  const screen = document.getElementById('screen-profile');
  screen?.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const cosmeticCard = target.closest('[data-profile-game-cosmetic]');
    if (cosmeticCard) {
      const itemId = String(cosmeticCard.getAttribute('data-profile-game-cosmetic') || '');
      if (CHECKERS_PROFILE_ITEMS[itemId]) upgradeCheckersSheet(itemId);
      upgradeProfileCheckersPresentation();
      return;
    }

    if (target.closest('[data-profile-game-tab]')) {
      upgradeProfileCheckersPresentation();
    }
  });

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (target?.closest('#mgwGameCosmeticEquip')) upgradeProfileCheckersPresentation();
  });

  document.addEventListener('mgw:open-profile', upgradeProfileCheckersPresentation);
  document.addEventListener('mgw:screen-changed', event => {
    if (event?.detail?.to === 'profile') upgradeProfileCheckersPresentation();
  });
}

function ensureCheckersPreviewStyles(){
  if (document.querySelector('link[data-mgw-checkers-cosmetics]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersCosmetics = 'mvp19-6-profile-boards';
  link.href = new URL('../../css/games/checkers/cosmetics.css?v=1&mvp19_6=board-themes', import.meta.url).href;
  document.head.appendChild(link);
}

function installProfileApiRepairHook(){
  const current = api?.profileV2;
  if (typeof current !== 'function' || current[PROFILE_API_REPAIR_HOOK]) return;

  const wrapped = async (...args) => {
    try {
      return await current.apply(api, args);
    } finally {
      scheduleProfileCheckersParityRepair();
    }
  };
  Object.defineProperty(wrapped, PROFILE_API_REPAIR_HOOK, { value:true });
  api.profileV2 = wrapped;
}

function scheduleProfileCheckersParityRepair(){
  const repair = () => upgradeProfileCheckersPresentation();
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(repair);
  else globalThis.setTimeout(repair, 0);
}

function upgradeProfileCheckersPresentation(){
  const screen = document.getElementById('screen-profile');
  if (!(screen instanceof HTMLElement)) return;

  const checkersTab = screen.querySelector('[data-profile-game-tab="checkers"]');
  if (checkersTab instanceof HTMLElement && checkersTab.textContent?.trim() === 'checkers') {
    checkersTab.textContent = 'Шашки';
  }

  const activeCheckers = checkersTab instanceof HTMLElement
    && (checkersTab.classList.contains('active') || checkersTab.getAttribute('aria-selected') === 'true');
  if (activeCheckers) {
    const panel = screen.querySelector('.profile-v2-game-panel');
    const firstGroupTitle = panel?.querySelector('.profile-v2-game-group-title');
    if (firstGroupTitle instanceof HTMLElement) firstGroupTitle.textContent = 'Доски';
  }

  screen.querySelectorAll('[data-profile-game-cosmetic]').forEach(card => {
    if (!(card instanceof HTMLElement)) return;
    const itemId = String(card.getAttribute('data-profile-game-cosmetic') || '');
    const definition = CHECKERS_PROFILE_ITEMS[itemId];
    if (!definition) return;

    const preview = card.querySelector('.store-v2-game-preview');
    if (preview instanceof HTMLElement && !isCorrectCheckersPreview(preview, definition)) {
      preview.outerHTML = checkersPreviewMarkup(definition);
    }

    const name = card.querySelector('.profile-v2-game-card-name');
    if (name instanceof HTMLElement && name.textContent !== definition.name) name.textContent = definition.name;
    card.setAttribute('aria-label', definition.name);
  });
}

function upgradeCheckersSheet(itemId){
  const definition = CHECKERS_PROFILE_ITEMS[itemId];
  if (!definition) return;

  const sheet = document.getElementById('sheet');
  if (!(sheet instanceof HTMLElement)) return;

  const previewWrap = sheet.querySelector('.profile-v2-game-preview-wrap');
  if (previewWrap instanceof HTMLElement) previewWrap.innerHTML = checkersPreviewMarkup(definition);

  const title = sheet.querySelector('.sheet-head h2');
  if (title instanceof HTMLElement && title.textContent !== definition.name) title.textContent = definition.name;

  const group = sheet.querySelector('.profile-v2-game-preview-meta small');
  if (group instanceof HTMLElement) group.textContent = 'Доски';
}

function isCorrectCheckersPreview(preview, definition){
  return preview.dataset.gameType === 'checkers'
    && preview.dataset.cosmeticLayer === 'theme'
    && preview.dataset.cosmeticVariant === definition.variant
    && preview.querySelector('.store-v2-mini-checkers-board') instanceof HTMLElement;
}

function checkersPreviewMarkup(definition){
  const cells = Array.from({ length:64 }, (_, cell) => {
    const row = Math.floor(cell / 8);
    const col = cell % 8;
    const dark = (row + col) % 2 === 1;
    let piece = '';
    if (dark && row < 3) piece = 'black';
    if (dark && row > 4) piece = 'white';
    return `<span class="${dark ? 'dark' : 'light'}">${piece ? `<i class="${piece}"></i>` : ''}</span>`;
  }).join('');

  return `<div class="store-v2-game-preview" data-game-type="checkers" data-cosmetic-layer="theme" data-cosmetic-variant="${definition.variant}" role="img" aria-label="${definition.name}"><i class="store-v2-mini-checkers-board" aria-hidden="true">${cells}</i></div>`;
}
