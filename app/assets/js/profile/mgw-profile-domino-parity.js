import { api } from '../api/client.js?v=47';
import { state } from '../state.js?v=27';
import { dominoPreviewMarkup } from '../screens/store-screen-domino-store-v1.js?v=9&mvp19_9=domino-native-render-v9';

const GROUP_TITLES = Object.freeze({ theme:'Столы', elements:'Костяшки', effect:'Эффекты' });
const ITEM_ORDER = Object.freeze([
  'game-domino-table-felt',
  'game-domino-table-midnight',
  'game-domino-table-walnut',
  'game-domino-table-neon',
  'game-domino-tiles-ivory',
  'game-domino-tiles-ebony',
  'game-domino-tiles-marble',
  'game-domino-tiles-neon',
  'game-domino-effect-precision-drop',
  'game-domino-effect-stock-pulse',
  'game-domino-effect-chain-finale',
]);
const PROFILE_API_REPAIR_HOOK = Symbol.for('mgw.profile.domino-store-parity.profile-v2.v5');
let initialized = false;
let repairQueued = false;

ensureDominoProfileStyles();
installProfileApiRepairHook();

export function initProfileDominoParity(){
  upgradeProfileDominoPresentation();
  if (initialized) return;
  initialized = true;

  const screen = document.getElementById('screen-profile');
  screen?.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const card = target.closest('[data-profile-game-cosmetic]');
    if (card instanceof HTMLElement) {
      const itemId = String(card.dataset.profileGameCosmetic || '');
      if (dominoItemById(itemId)) queueMicrotask(() => upgradeDominoSheet(itemId));
      return;
    }

    const gameTab = target.closest('[data-profile-game-tab]');
    if (gameTab instanceof HTMLElement) {
      const selectedGame = String(gameTab.dataset.profileGameTab || '');
      const panel = screen.querySelector('.profile-v2-game-panel');
      if (selectedGame === 'domino') {
        activateDominoTab(screen, gameTab);
      } else if (panel instanceof HTMLElement) {
        panel.removeAttribute('data-mgw-domino-profile-signature');
      }
      queueProfileDominoRepair();
    }
  });

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (target?.closest('#mgwGameCosmeticEquip')) queueProfileDominoRepair();
  });
  document.addEventListener('mgw:cosmetic-inventory-changed', queueProfileDominoRepair);
  document.addEventListener('mgw:open-profile', queueProfileDominoRepair);
  document.addEventListener('mgw:screen-changed', event => {
    if (event?.detail?.to === 'profile') queueProfileDominoRepair();
  });
}

function ensureDominoProfileStyles(){
  ensureStyle('data-mgw-domino-store', '../../css/games/domino/store-cosmetics-v1.css?v=4&mvp19_9=domino-uniform-fullfield-v4');
  ensureStyle('data-mgw-domino-store-card-fill-v5', '../../css/games/domino/store-card-fill-live-pips-v5.css?v=2&mvp19_9=domino-card-fill-live-pips-v6');
  ensureStyle('data-mgw-domino-store-effects-v9', '../../css/games/domino/store-effects-scene-v9.css?v=7&mvp19_9=domino-premium-effects-v15-proportions');
  ensureStyle('data-mgw-profile-domino-parity', '../../css/screens/profile-domino-store-parity-v1.css?v=3&mvp19_9=domino-profile-single-back-v3');
}

function ensureStyle(marker, relativeHref){
  const selector = `link[${marker}]`;
  const href = new URL(relativeHref, import.meta.url).href;
  const existing = document.querySelector(selector);
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    document.head.appendChild(existing);
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
  const wrapped = (...args) => new Promise((resolve, reject) => {
    Promise.resolve()
      .then(() => current.apply(api, args))
      .then(result => {
        resolve(result);
        queueProfileDominoRepair();
      }, error => {
        reject(error);
        queueProfileDominoRepair();
      });
  });
  Object.defineProperty(wrapped, PROFILE_API_REPAIR_HOOK, { value:true });
  api.profileV2 = wrapped;
}

function queueProfileDominoRepair(){
  if (repairQueued) return;
  repairQueued = true;
  queueMicrotask(() => {
    repairQueued = false;
    upgradeProfileDominoPresentation();
  });
}

function ensureDominoTab(screen){
  const tabs = screen.querySelector('.profile-v2-game-tabs');
  if (!(tabs instanceof HTMLElement)) return null;

  let dominoTab = tabs.querySelector('[data-profile-game-tab="domino"]');
  if (!(dominoTab instanceof HTMLButtonElement)) {
    dominoTab = document.createElement('button');
    dominoTab.className = 'profile-v2-game-tab';
    dominoTab.type = 'button';
    dominoTab.setAttribute('role', 'tab');
    dominoTab.dataset.profileGameTab = 'domino';
    dominoTab.setAttribute('aria-selected', 'false');
    tabs.appendChild(dominoTab);
  }

  dominoTab.innerHTML = '<span class="profile-v2-game-tab-mark mgw-domino-profile-tab-mark" aria-hidden="true"></span><span class="profile-v2-game-tab-label">Домино</span>';
  return dominoTab;
}

function activateDominoTab(screen, dominoTab){
  screen.querySelectorAll('[data-profile-game-tab]').forEach(button => {
    const active = button === dominoTab;
    button.classList.toggle('active', active);
    button.setAttribute('aria-selected', active ? 'true' : 'false');
  });

  const panel = screen.querySelector('.profile-v2-game-panel');
  if (!(panel instanceof HTMLElement)) return;
  panel.dataset.profileGamePanel = 'domino';
  panel.removeAttribute('data-mgw-checkers-profile-signature');
  panel.removeAttribute('data-mgw-reversi-profile-signature');
  panel.removeAttribute('data-mgw-go-profile-signature');
}

function upgradeProfileDominoPresentation(){
  ensureDominoProfileStyles();
  const screen = document.getElementById('screen-profile');
  if (!(screen instanceof HTMLElement)) return;

  const dominoTab = ensureDominoTab(screen);
  const activeDomino = dominoTab instanceof HTMLElement
    && (dominoTab.classList.contains('active') || dominoTab.getAttribute('aria-selected') === 'true');
  if (!activeDomino) return;

  const panel = screen.querySelector('.profile-v2-game-panel');
  if (!(panel instanceof HTMLElement)) return;
  const items = ownedDominoItems();
  const signature = dominoPanelSignature(items);
  const canonical = panel.querySelector('[data-mgw-domino-profile-group], [data-mgw-domino-profile-empty]') instanceof HTMLElement;
  if (panel.dataset.mgwDominoProfileSignature !== signature || !canonical) {
    panel.innerHTML = renderDominoGroups(items);
    panel.dataset.mgwDominoProfileSignature = signature;
    panel.dataset.profileGamePanel = 'domino';
  }
}

function renderDominoGroups(items){
  const groups = ['theme','elements','effect']
    .map(layer => ({ layer, items:items.filter(item => dominoLayer(item) === layer) }))
    .filter(group => group.items.length > 0);
  if (!groups.length) {
    return '<div class="profile-v2-game-empty" data-mgw-domino-profile-empty="1">Купленные предметы для Домино появятся здесь.</div>';
  }
  return groups.map(group => `
    <div class="profile-v2-game-group" data-mgw-domino-profile-group="${group.layer}">
      <div class="profile-v2-game-group-title">${GROUP_TITLES[group.layer]}</div>
      <div class="profile-v2-game-grid">${group.items.map(dominoCardMarkup).join('')}</div>
    </div>
  `).join('');
}

function dominoCardMarkup(item){
  const itemId = String(item.item_id || '');
  const active = isDominoItemEquipped(item);
  const available = String(item.catalog_status || '') === 'active';
  const name = dominoDisplayName(item);
  return `<button class="profile-v2-game-card${active ? ' active' : ''}${available ? '' : ' unavailable'}" type="button" data-profile-game-cosmetic="${escapeHtml(itemId)}" aria-label="${escapeHtml(name)}" aria-pressed="${active ? 'true' : 'false'}">${dominoPreview(item)}<span class="profile-v2-game-card-name">${escapeHtml(name)}</span>${active ? '<i class="profile-v2-selected-check" aria-hidden="true">✓</i>' : ''}</button>`;
}

function upgradeDominoSheet(itemId){
  const item = dominoItemById(itemId);
  if (!item) return;
  const sheet = document.getElementById('sheet');
  if (!(sheet instanceof HTMLElement)) return;
  const previewWrap = sheet.querySelector('.profile-v2-game-preview-wrap');
  if (previewWrap instanceof HTMLElement) previewWrap.innerHTML = dominoPreview(item);
  const title = sheet.querySelector('.sheet-head h2');
  if (title instanceof HTMLElement) title.textContent = dominoDisplayName(item);
  const strong = sheet.querySelector('.profile-v2-game-preview-meta strong');
  if (strong instanceof HTMLElement) strong.textContent = 'Домино';
  const group = sheet.querySelector('.profile-v2-game-preview-meta small');
  if (group instanceof HTMLElement) group.textContent = GROUP_TITLES[dominoLayer(item)] || 'Оформление';
}

function ownedDominoItems(){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const catalog = Array.isArray(inventory?.catalog) ? inventory.catalog : [];
  return catalog
    .filter(item => item && item.owned === true && item.item_type === 'game' && dominoGameType(item) === 'domino')
    .map(item => ({ ...item, item_id:String(item.item_id || ''), equip_slot:String(item.equip_slot || '') }))
    .filter(item => GROUP_TITLES[dominoLayer(item)] && ITEM_ORDER.includes(item.item_id))
    .sort((left, right) => ITEM_ORDER.indexOf(left.item_id) - ITEM_ORDER.indexOf(right.item_id));
}

function dominoItemById(itemId){
  return ownedDominoItems().find(item => item.item_id === String(itemId || '')) || null;
}

function dominoGameType(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  const explicit = String(metadata.game_type || '').trim();
  if (explicit) return explicit;
  const family = String(item?.item_family || '').trim();
  return family.startsWith('game_') ? family.slice(5) : '';
}

function dominoLayer(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  const explicit = String(metadata.layer || '').trim();
  if (GROUP_TITLES[explicit]) return explicit;
  const slot = String(item?.equip_slot || '');
  if (slot.endsWith('_theme')) return 'theme';
  if (slot.endsWith('_elements')) return 'elements';
  if (slot.endsWith('_effect')) return 'effect';
  return '';
}

function dominoVariant(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  const fallback = dominoLayer(item) === 'effect' ? 'precision-drop' : (dominoLayer(item) === 'elements' ? 'ivory' : 'felt');
  return String(metadata.variant || fallback).trim().toLowerCase().replace(/[^a-z0-9-]/g, '') || fallback;
}

function dominoDisplayName(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  return String(metadata.display_name || item?.item_id || 'Оформление Домино').trim();
}

function isDominoItemEquipped(item){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const equipped = inventory?.equipped && typeof inventory.equipped === 'object' ? inventory.equipped : {};
  const slot = String(item?.equip_slot || '');
  const itemId = String(item?.item_id || '');
  return slot !== '' && itemId !== '' && String(equipped[slot] || '') === itemId;
}

function dominoPanelSignature(items){
  return JSON.stringify(items.map(item => [
    String(item.item_id || ''), dominoLayer(item), dominoVariant(item), dominoDisplayName(item),
    String(item.catalog_status || ''), isDominoItemEquipped(item),
  ]));
}

function dominoPreview(item){
  const layer = dominoLayer(item);
  const variant = dominoVariant(item);
  const name = dominoDisplayName(item);
  return `<div class="store-v2-game-preview" data-game-type="domino" data-cosmetic-layer="${layer}" data-cosmetic-variant="${variant}" role="img" aria-label="${escapeHtml(name)}">${dominoPreviewMarkup(layer, variant)}</div>`;
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#039;');
}
