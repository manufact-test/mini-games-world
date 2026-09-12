import { initProfileScreen as initBaseProfileScreen } from '../screens/profile-screen-v110.js?v=1126&profile_base=accepted-game-cosmetics';
import { api } from '../api/client.js?v=47';

const CHESS_PROFILE_ITEMS = Object.freeze({
  'game-chess-board-wood':Object.freeze({ layer:'theme', variant:'wood', name:'Деревянная доска' }),
  'game-chess-board-tournament-dark':Object.freeze({ layer:'theme', variant:'tournament-dark', name:'Тёмная турнирная доска' }),
  'game-chess-board-marble':Object.freeze({ layer:'theme', variant:'marble', name:'Мраморная доска' }),
  'game-chess-board-neon':Object.freeze({ layer:'theme', variant:'neon', name:'Неоновая доска' }),
  'game-chess-pieces-wood':Object.freeze({ layer:'elements', variant:'wood', name:'Деревянные фигуры' }),
  'game-chess-pieces-marble':Object.freeze({ layer:'elements', variant:'marble', name:'Мраморные фигуры' }),
  'game-chess-pieces-metal':Object.freeze({ layer:'elements', variant:'metal', name:'Металлические фигуры' }),
  'game-chess-pieces-neon':Object.freeze({ layer:'elements', variant:'neon', name:'Неоновые фигуры' }),
  'game-chess-effect-move':Object.freeze({ layer:'effect', variant:'move', name:'Эффект хода' }),
  'game-chess-effect-capture':Object.freeze({ layer:'effect', variant:'capture', name:'Эффект взятия' }),
  // Backend identity intentionally stays stable; accepted product presentation is Quantum Echo.
  'game-chess-effect-check':Object.freeze({ layer:'effect', variant:'quantum-echo', scene:'check', name:'Квантовый след' }),
});

const PROFILE_API_REPAIR_HOOK = Symbol.for('mgw.profile.chess-parity.profile-v2.v1');
let initialized = false;

ensureChessPreviewStyles();
installProfileApiRepairHook();

export function initProfileScreen(){
  initBaseProfileScreen();
  upgradeProfileChessPresentation();

  if (initialized) return;
  initialized = true;

  const screen = document.getElementById('screen-profile');
  screen?.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const cosmeticCard = target.closest('[data-profile-game-cosmetic]');
    if (cosmeticCard) {
      const itemId = String(cosmeticCard.getAttribute('data-profile-game-cosmetic') || '');
      if (CHESS_PROFILE_ITEMS[itemId]) upgradeChessSheet(itemId);
      upgradeProfileChessPresentation();
      return;
    }

    if (target.closest('[data-profile-game-tab]')) {
      // Base Profile listener is registered first and has already rendered the selected panel.
      upgradeProfileChessPresentation();
    }
  });

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (target?.closest('#mgwGameCosmeticEquip')) {
      // The base button listener performs its optimistic render synchronously before bubbling here.
      upgradeProfileChessPresentation();
    }
  });

  document.addEventListener('mgw:open-profile', upgradeProfileChessPresentation);
  document.addEventListener('mgw:screen-changed', event => {
    if (event?.detail?.to === 'profile') upgradeProfileChessPresentation();
  });
}

function ensureChessPreviewStyles(){
  if (!document.querySelector('link[data-mgw-chess-cosmetics]')) {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.dataset.mgwChessCosmetics = 'mvp19-5-profile-parity';
    link.href = new URL('../../css/games/chess/runtime-cosmetics.css?v=3&mvp19_5=native-board-preview&fx_preview=move-live-parity-v4', import.meta.url).href;
    document.head.appendChild(link);
  }

  if (!document.querySelector('link[data-mgw-chess-quantum-echo]')) {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.dataset.mgwChessQuantumEcho = 'mvp19-5-profile-parity';
    link.href = new URL('../../css/games/chess/store-quantum-echo-preview-v1.css?v=1&mvp19_5=quantum-echo-preview-parity-v1', import.meta.url).href;
    document.head.appendChild(link);
  }
}

function installProfileApiRepairHook(){
  const current = api?.profileV2;
  if (typeof current !== 'function' || current[PROFILE_API_REPAIR_HOOK]) return;

  const wrapped = async (...args) => {
    try {
      return await current.apply(api, args);
    } finally {
      scheduleProfileChessParityRepair();
    }
  };
  Object.defineProperty(wrapped, PROFILE_API_REPAIR_HOOK, { value:true });
  api.profileV2 = wrapped;
}

function scheduleProfileChessParityRepair(){
  const repair = () => upgradeProfileChessPresentation();
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(repair);
  else globalThis.setTimeout(repair, 0);
}

function upgradeProfileChessPresentation(){
  const screen = document.getElementById('screen-profile');
  if (!(screen instanceof HTMLElement)) return;

  screen.querySelectorAll('[data-profile-game-cosmetic]').forEach(card => {
    if (!(card instanceof HTMLElement)) return;
    const itemId = String(card.getAttribute('data-profile-game-cosmetic') || '');
    const definition = CHESS_PROFILE_ITEMS[itemId];
    if (!definition) return;

    const preview = card.querySelector('.store-v2-game-preview');
    if (preview instanceof HTMLElement && !isCorrectChessPreview(preview, definition)) {
      preview.outerHTML = chessPreviewMarkup(itemId, definition);
    }

    const name = card.querySelector('.profile-v2-game-card-name');
    if (name instanceof HTMLElement && name.textContent !== definition.name) name.textContent = definition.name;
    card.setAttribute('aria-label', definition.name);
  });
}

function upgradeChessSheet(itemId){
  const definition = CHESS_PROFILE_ITEMS[itemId];
  if (!definition) return;

  const sheet = document.getElementById('sheet');
  if (!(sheet instanceof HTMLElement)) return;

  const previewWrap = sheet.querySelector('.profile-v2-game-preview-wrap');
  if (previewWrap instanceof HTMLElement) previewWrap.innerHTML = chessPreviewMarkup(itemId, definition);

  const title = sheet.querySelector('.sheet-head h2');
  if (title instanceof HTMLElement && title.textContent !== definition.name) title.textContent = definition.name;
}

function isCorrectChessPreview(preview, definition){
  return preview.dataset.gameType === 'chess'
    && preview.dataset.cosmeticLayer === definition.layer
    && preview.dataset.cosmeticVariant === definition.variant;
}

function chessPreviewMarkup(itemId, definition){
  let content = '';
  let fieldPreview = '';

  if (definition.layer === 'theme') {
    const pieces = ['♜','','','♚','','♟','','','','','♙','','♔','','','♖'];
    content = `<i class="store-v2-mini-chess-board">${pieces.map(piece => `<span>${piece ? `<b>${piece}</b>` : ''}</span>`).join('')}</i>`;
  } else if (definition.layer === 'elements') {
    content = '<i class="store-v2-mini-chess-pieces"><span>♚</span><span>♞</span><span>♟</span></i>';
  } else {
    const scene = definition.scene || definition.variant;
    const scenes = {
      move:[['mover white','♞'],['static-a black','♟'],['static-b white','♟']],
      capture:[['mover white','♝'],['target black','♜'],['static-a black','♟'],['static-b white','♟']],
      check:[['mover white','♜'],['king black','♚'],['static-a black','♟'],['static-b white','♟']],
    };
    const squares = Array.from({ length:16 }, (_, index) => {
      const row = Math.floor(index / 4);
      const column = index % 4;
      return `<span class="${(row + column) % 2 === 0 ? 'light' : 'dark'}"></span>`;
    }).join('');
    const pieces = (scenes[scene] || []).map(([className, glyph]) => `<strong class="chess-effect-piece ${className}">${glyph}</strong>`).join('');
    content = `<i class="store-v2-mini-chess-field-effect" aria-hidden="true">${squares}${pieces}<b></b><em></em><u></u></i>`;
    fieldPreview = ' data-field-effect-preview="v2-action-demo"';
  }

  return `<div class="store-v2-game-preview" data-game-type="chess" data-cosmetic-layer="${definition.layer}" data-cosmetic-variant="${definition.variant}"${fieldPreview} role="img" aria-label="${definition.name}">${content}</div>`;
}
