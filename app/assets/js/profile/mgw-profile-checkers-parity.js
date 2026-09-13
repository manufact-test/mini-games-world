import { initProfileScreen as initChessParityProfileScreen } from './mgw-profile-chess-parity.js?v=1&mvp19_5=chess-profile-store-parity-v1';
import { api } from '../api/client.js?v=47';
import { state } from '../state.js?v=27';

const CHECKERS_GROUP_TITLES = Object.freeze({ theme:'Доски', elements:'Шашки', effect:'Эффекты' });
const PROFILE_API_REPAIR_HOOK = Symbol.for('mgw.profile.checkers-store-parity.profile-v2.v2');
let initialized = false;
let checkersEffectObserver = null;
let inventoryRefreshTask = null;
let dragState = null;
let suppressNextTabClick = false;

ensureCheckersPreviewStyles();
installProfileApiRepairHook();

export function initProfileScreen(){
  initChessParityProfileScreen();
  upgradeProfileCheckersPresentation();

  if (initialized) return;
  initialized = true;

  const screen = document.getElementById('screen-profile');
  installGameTabScroller(screen);

  screen?.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const cosmeticCard = target.closest('[data-profile-game-cosmetic]');
    if (cosmeticCard) {
      const itemId = String(cosmeticCard.getAttribute('data-profile-game-cosmetic') || '');
      if (checkersItemById(itemId)) upgradeCheckersSheet(itemId);
      upgradeProfileCheckersPresentation();
      return;
    }

    const gameTab = target.closest('[data-profile-game-tab]');
    if (gameTab instanceof HTMLElement) {
      upgradeProfileCheckersPresentation();
      keepProfileGameTabVisible(gameTab, true);
    }
  });

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (target?.closest('#mgwGameCosmeticEquip')) scheduleProfileCheckersParityRepair();
  });

  document.addEventListener('mgw:cosmetic-inventory-changed', refreshAuthoritativeCheckersInventory);
  document.addEventListener('mgw:open-profile', () => {
    upgradeProfileCheckersPresentation();
    scheduleActiveGameTabVisibility();
  });
  document.addEventListener('mgw:screen-changed', event => {
    if (event?.detail?.to === 'profile') {
      upgradeProfileCheckersPresentation();
      scheduleActiveGameTabVisibility();
    }
  });
}

function ensureCheckersPreviewStyles(){
  ensureStyle('data-mgw-checkers-cosmetics', '../../css/games/checkers/cosmetics.css?v=3&mvp19_6=full-store-v1');
  ensureStyle('data-mgw-checkers-store-corrective', '../../css/games/checkers/store-visual-corrective-v3.css?v=1&mvp19_6=manual-review-pass-3');
  ensureStyle('data-mgw-checkers-store-board-source-parity', '../../css/games/checkers/store-boards-pieces-polish-v2.css?v=1&mvp19_6=board-source-parity');
  ensureStyle('data-mgw-checkers-store-board-card-radius', '../../css/games/checkers/store-board-card-radius-v1.css?v=1&mvp19_6=store-card-radius');
  ensureStyle('data-mgw-checkers-store-effects-live-board', '../../css/games/checkers/store-effects-live-board-v1.css?v=3&mvp19_6=promotion-destination-parity-v1');
  ensureStyle('data-mgw-checkers-store-effect-final-centering', '../../css/games/checkers/store-effects-final-centering-v1.css?v=2&mvp19_6=king-readable-v2');
  ensureStyle('data-mgw-profile-game-cosmetics-parity', '../../css/screens/profile-game-cosmetics-parity-v1.css?v=1&mvp19_6=checkers-full-profile-parity');
}

function ensureStyle(marker, relativeHref){
  const selector = `link[${marker}]`;
  const href = new URL(relativeHref, import.meta.url).href;
  const existing = document.querySelector(selector);
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.setAttribute(marker, '1');
  link.href = href;
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

function refreshAuthoritativeCheckersInventory(){
  if (inventoryRefreshTask) return;
  inventoryRefreshTask = Promise.resolve()
    .then(() => api.profileV2())
    .catch(() => null)
    .finally(() => {
      inventoryRefreshTask = null;
      scheduleProfileCheckersParityRepair();
    });
}

function scheduleProfileCheckersParityRepair(){
  const repair = () => upgradeProfileCheckersPresentation();
  queueMicrotask(repair);
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(repair);
  else globalThis.setTimeout(repair, 0);
}

function upgradeProfileCheckersPresentation(){
  const screen = document.getElementById('screen-profile');
  if (!(screen instanceof HTMLElement)) return;

  const checkersTab = screen.querySelector('[data-profile-game-tab="checkers"]');
  if (checkersTab instanceof HTMLElement && checkersTab.textContent?.trim() === 'checkers') {
    const label = checkersTab.querySelector('span:last-child');
    if (label instanceof HTMLElement) label.textContent = 'Русские шашки';
  }

  const activeCheckers = checkersTab instanceof HTMLElement
    && (checkersTab.classList.contains('active') || checkersTab.getAttribute('aria-selected') === 'true');
  if (!activeCheckers) return;

  const panel = screen.querySelector('.profile-v2-game-panel');
  if (!(panel instanceof HTMLElement)) return;
  const items = ownedCheckersItems();
  const signature = checkersPanelSignature(items);
  if (panel.dataset.mgwCheckersProfileSignature !== signature) {
    panel.innerHTML = renderCheckersGroups(items);
    panel.dataset.mgwCheckersProfileSignature = signature;
    panel.dataset.profileGamePanel = 'checkers';
  }
  decorateCheckersPreviews(panel);
}

function renderCheckersGroups(items){
  const layers = ['theme','elements','effect'];
  const groups = layers
    .map(layer => ({ layer, items:items.filter(item => checkersLayer(item) === layer) }))
    .filter(group => group.items.length > 0);
  if (!groups.length) return '<div class="profile-v2-game-empty">Купленные предметы для этой игры появятся здесь.</div>';

  return groups.map(group => `
    <div class="profile-v2-game-group" data-mgw-checkers-profile-group="${group.layer}">
      <div class="profile-v2-game-group-title">${CHECKERS_GROUP_TITLES[group.layer]}</div>
      <div class="profile-v2-game-grid">${group.items.map(checkersCardMarkup).join('')}</div>
    </div>
  `).join('');
}

function checkersCardMarkup(item){
  const itemId = String(item.item_id || '');
  const active = isCheckersItemEquipped(item);
  const available = String(item.catalog_status || '') === 'active';
  const name = checkersDisplayName(item);
  return `<button class="profile-v2-game-card${active ? ' active' : ''}${available ? '' : ' unavailable'}" type="button" data-profile-game-cosmetic="${escapeHtml(itemId)}" aria-label="${escapeHtml(name)}" aria-pressed="${active ? 'true' : 'false'}">${checkersPreviewMarkup(item)}<span class="profile-v2-game-card-name">${escapeHtml(name)}</span>${active ? '<i class="profile-v2-selected-check" aria-hidden="true">✓</i>' : ''}</button>`;
}

function upgradeCheckersSheet(itemId){
  const item = checkersItemById(itemId);
  if (!item) return;
  const sheet = document.getElementById('sheet');
  if (!(sheet instanceof HTMLElement)) return;

  const previewWrap = sheet.querySelector('.profile-v2-game-preview-wrap');
  if (previewWrap instanceof HTMLElement) previewWrap.innerHTML = checkersPreviewMarkup(item);

  const title = sheet.querySelector('.sheet-head h2');
  if (title instanceof HTMLElement) title.textContent = checkersDisplayName(item);

  const group = sheet.querySelector('.profile-v2-game-preview-meta small');
  if (group instanceof HTMLElement) group.textContent = CHECKERS_GROUP_TITLES[checkersLayer(item)] || 'Оформление';

  decorateCheckersPreviews(sheet);
}

function ownedCheckersItems(){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const catalog = Array.isArray(inventory?.catalog) ? inventory.catalog : [];
  const layerOrder = new Map([['theme',0],['elements',1],['effect',2]]);
  return catalog
    .filter(item => item && item.owned === true && item.item_type === 'game' && checkersGameType(item) === 'checkers')
    .map(item => ({ ...item, item_id:String(item.item_id || ''), equip_slot:String(item.equip_slot || '') }))
    .filter(item => layerOrder.has(checkersLayer(item)))
    .sort((left, right) => Number(layerOrder.get(checkersLayer(left))) - Number(layerOrder.get(checkersLayer(right))) || left.item_id.localeCompare(right.item_id));
}

function checkersItemById(itemId){
  return ownedCheckersItems().find(item => item.item_id === String(itemId || '')) || null;
}

function checkersGameType(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  const explicit = String(metadata.game_type || '').trim();
  if (explicit) return explicit;
  const family = String(item?.item_family || '').trim();
  return family.startsWith('game_') ? family.slice(5) : '';
}

function checkersLayer(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  const explicit = String(metadata.layer || '').trim();
  if (CHECKERS_GROUP_TITLES[explicit]) return explicit;
  const slot = String(item?.equip_slot || '');
  if (slot.endsWith('_theme')) return 'theme';
  if (slot.endsWith('_elements')) return 'elements';
  if (slot.endsWith('_effect')) return 'effect';
  return '';
}

function checkersVariant(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  const fallback = checkersLayer(item) === 'effect' ? 'move' : 'base';
  return String(metadata.variant || fallback).trim().toLowerCase().replace(/[^a-z0-9-]/g, '') || fallback;
}

function checkersDisplayName(item){
  const layer = checkersLayer(item);
  const variant = checkersVariant(item);
  if (layer === 'elements' && variant === 'marble') return 'Гранитные шашки';
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  return String(metadata.display_name || item?.item_id || 'Оформление шашек').trim();
}

function isCheckersItemEquipped(item){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const equipped = inventory?.equipped && typeof inventory.equipped === 'object' ? inventory.equipped : {};
  const slot = String(item?.equip_slot || '');
  const itemId = String(item?.item_id || '');
  return slot !== '' && itemId !== '' && String(equipped[slot] || '') === itemId;
}

function checkersPanelSignature(items){
  return JSON.stringify(items.map(item => [
    String(item.item_id || ''),
    checkersLayer(item),
    checkersVariant(item),
    checkersDisplayName(item),
    String(item.catalog_status || ''),
    isCheckersItemEquipped(item),
  ]));
}

function checkersPreviewMarkup(item){
  const layer = checkersLayer(item);
  const variant = checkersVariant(item);
  const name = checkersDisplayName(item);
  let content = '';

  if (layer === 'theme') {
    content = checkersBoardMarkup(true);
  } else if (layer === 'elements') {
    content = '<i class="store-v2-mini-checkers-pieces" aria-hidden="true"><span class="black"></span><span class="white"></span><span class="king"><b class="mgw-checkers-piece-brand" data-mgw-checkers-piece-brand="1"><span class="mgw-checkers-piece-crown">♛</span><span class="mgw-checkers-piece-mark">MG</span></b></span></i>';
  } else {
    content = `<i class="store-v2-mini-checkers-effect checkers-store-fx-${variant}" data-checkers-effect-preview data-mgw-checkers-fx-markup="4" aria-hidden="true">${checkersEffectBoardMarkup()}<i class="checkers-fx-path"></i><b class="checkers-fx-piece from"></b><b class="checkers-fx-piece target"></b><em class="checkers-fx-impact"></em><u class="checkers-fx-crown">♛</u></i>`;
  }

  return `<div class="store-v2-game-preview" data-game-type="checkers" data-cosmetic-layer="${layer}" data-cosmetic-variant="${variant}" role="img" aria-label="${escapeHtml(name)}">${content}</div>`;
}

function checkersBoardMarkup(withPieces){
  const cells = Array.from({ length:64 }, (_, cell) => {
    const row = Math.floor(cell / 8);
    const col = cell % 8;
    const dark = (row + col) % 2 === 1;
    let piece = '';
    if (withPieces && dark && row < 3) piece = 'black';
    if (withPieces && dark && row > 4) piece = 'white';
    return `<span class="${dark ? 'dark' : 'light'}">${piece ? `<i class="${piece}"></i>` : ''}</span>`;
  }).join('');
  return `<i class="store-v2-mini-checkers-board" aria-hidden="true">${cells}</i>`;
}

function checkersEffectBoardMarkup(){
  const cells = Array.from({ length:64 }, (_, cell) => {
    const row = Math.floor(cell / 8);
    const col = cell % 8;
    return `<span class="${(row + col) % 2 ? 'dark' : 'light'}"></span>`;
  }).join('');
  return `<span class="checkers-fx-board">${cells}</span>`;
}

function decorateCheckersPreviews(root){
  root.querySelectorAll('.store-v2-game-preview[data-game-type="checkers"][data-cosmetic-layer="effect"]').forEach(preview => {
    if (!(preview instanceof HTMLElement)) return;
    preview.removeAttribute('tabindex');
    preview.removeAttribute('title');
    preview.classList.remove('is-playing');
    startPassiveEffectPreview(preview);
  });
}

function ensureCheckersEffectObserver(){
  if (checkersEffectObserver || typeof globalThis.IntersectionObserver !== 'function') return checkersEffectObserver;
  checkersEffectObserver = new globalThis.IntersectionObserver(entries => {
    entries.forEach(entry => {
      const preview = entry.target;
      if (!(preview instanceof HTMLElement)) return;
      const visible = entry.isIntersecting && entry.intersectionRatio >= 0.05;
      preview.dataset.mgwCheckersFxVisible = visible ? '1' : '0';
      if (visible) runBoundedEffectPreview(preview);
      else stopPassiveEffectPreview(preview);
    });
  }, { threshold:[0,0.05,0.35,0.7] });
  return checkersEffectObserver;
}

function startPassiveEffectPreview(preview){
  if (globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
    stopPassiveEffectPreview(preview);
    preview.classList.add('is-reduced-preview');
    return;
  }
  preview.classList.remove('is-reduced-preview');
  const observer = ensureCheckersEffectObserver();
  if (observer) {
    if (preview.dataset.mgwCheckersFxObserved !== '1') {
      preview.dataset.mgwCheckersFxObserved = '1';
      observer.observe(preview);
    }
    return;
  }
  preview.dataset.mgwCheckersFxVisible = '1';
  runBoundedEffectPreview(preview);
}

function nextCheckersEffectRunToken(preview){
  const token = Number(preview.dataset.mgwCheckersFxRunToken || 0) + 1;
  preview.dataset.mgwCheckersFxRunToken = String(token);
  return token;
}

function stopPassiveEffectPreview(preview){
  if (!(preview instanceof HTMLElement)) return;
  nextCheckersEffectRunToken(preview);
  preview.classList.remove('is-previewing');
  preview.dataset.mgwCheckersFxBusy = '0';
}

function runBoundedEffectPreview(preview){
  if (!(preview instanceof HTMLElement) || preview.dataset.mgwCheckersFxBusy === '1') return;
  preview.dataset.mgwCheckersFxBusy = '1';
  const runToken = nextCheckersEffectRunToken(preview);
  const isActive = () => preview.isConnected
    && preview.dataset.mgwCheckersFxVisible !== '0'
    && preview.dataset.mgwCheckersFxRunToken === String(runToken)
    && !globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  const finish = () => {
    if (preview.dataset.mgwCheckersFxRunToken !== String(runToken)) return;
    preview.classList.remove('is-previewing');
    preview.dataset.mgwCheckersFxBusy = '0';
  };
  const replay = () => {
    if (!isActive()) {
      finish();
      return;
    }
    preview.classList.remove('is-previewing');
    void preview.offsetWidth;
    preview.classList.add('is-previewing');
    globalThis.setTimeout(() => {
      if (!isActive()) {
        finish();
        return;
      }
      preview.classList.remove('is-previewing');
      globalThis.setTimeout(replay, 260);
    }, 1900);
  };
  replay();
}

function installGameTabScroller(screen){
  if (!(screen instanceof HTMLElement) || screen.dataset.mgwGameTabsScroller === '1') return;
  screen.dataset.mgwGameTabsScroller = '1';

  screen.addEventListener('pointerdown', event => {
    if (event.pointerType !== 'mouse' || event.button !== 0) return;
    const target = event.target instanceof Element ? event.target : null;
    const strip = target?.closest('.profile-v2-game-tabs');
    if (!(strip instanceof HTMLElement) || strip.scrollWidth <= strip.clientWidth) return;
    dragState = { strip, pointerId:event.pointerId, startX:event.clientX, startScrollLeft:strip.scrollLeft, moved:false };
    suppressNextTabClick = false;
    strip.setPointerCapture?.(event.pointerId);
  });

  screen.addEventListener('pointermove', event => {
    if (!dragState || dragState.pointerId !== event.pointerId) return;
    const delta = event.clientX - dragState.startX;
    if (!dragState.moved && Math.abs(delta) < 5) return;
    dragState.moved = true;
    dragState.strip.classList.add('is-dragging');
    dragState.strip.scrollLeft = dragState.startScrollLeft - delta;
    event.preventDefault();
  });

  const finishDrag = event => {
    if (!dragState || dragState.pointerId !== event.pointerId) return;
    suppressNextTabClick = dragState.moved;
    dragState.strip.classList.remove('is-dragging');
    dragState.strip.releasePointerCapture?.(event.pointerId);
    dragState = null;
  };
  screen.addEventListener('pointerup', finishDrag);
  screen.addEventListener('pointercancel', finishDrag);

  screen.addEventListener('click', event => {
    if (!suppressNextTabClick) return;
    const target = event.target instanceof Element ? event.target : null;
    if (!target?.closest('[data-profile-game-tab]')) return;
    suppressNextTabClick = false;
    event.preventDefault();
    event.stopImmediatePropagation();
  }, true);
}

function scheduleActiveGameTabVisibility(){
  const run = () => {
    const active = document.querySelector('#screen-profile .profile-v2-game-tab.active, #screen-profile [data-profile-game-tab][aria-selected="true"]');
    if (active instanceof HTMLElement) keepProfileGameTabVisible(active, false);
  };
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(run);
  else globalThis.setTimeout(run, 0);
}

function keepProfileGameTabVisible(tab, smooth){
  const strip = tab.closest('.profile-v2-game-tabs');
  if (!(strip instanceof HTMLElement) || strip.clientWidth <= 0) return;
  const stripRect = strip.getBoundingClientRect();
  const tabRect = tab.getBoundingClientRect();
  const inset = 6;
  let delta = 0;
  if (tabRect.left < stripRect.left + inset) delta = tabRect.left - stripRect.left - inset;
  else if (tabRect.right > stripRect.right - inset) delta = tabRect.right - stripRect.right + inset;
  if (Math.abs(delta) < 1) return;
  const left = Math.max(0, Math.min(strip.scrollWidth - strip.clientWidth, strip.scrollLeft + delta));
  if (typeof strip.scrollTo === 'function') strip.scrollTo({ left, behavior:smooth ? 'smooth' : 'auto' });
  else strip.scrollLeft = left;
}

function escapeHtml(value){
  return String(value ?? '').replace(/[&<>"']/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[char]);
}
