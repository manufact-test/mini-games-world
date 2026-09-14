import { api } from '../api/client.js?v=47';
import { state } from '../state.js?v=27';

const GROUP_TITLES = Object.freeze({ theme:'Поля', elements:'Фишки', effect:'Эффекты' });
const ITEM_ORDER = Object.freeze([
  'game-reversi-field-green',
  'game-reversi-field-dark',
  'game-reversi-field-marble',
  'game-reversi-field-neon',
  'game-reversi-pieces-classic',
  'game-reversi-pieces-marble',
  'game-reversi-pieces-metal',
  'game-reversi-pieces-neon',
  'game-reversi-effect-placement',
  'game-reversi-effect-line',
  'game-reversi-effect-mass-flip',
]);
const PROFILE_API_REPAIR_HOOK = Symbol.for('mgw.profile.reversi-store-parity.profile-v2.v1');
let initialized = false;

ensureReversiProfileStyles();
installProfileApiRepairHook();

export function initProfileReversiParity(){
  upgradeProfileReversiPresentation();

  if (initialized) return;
  initialized = true;

  const screen = document.getElementById('screen-profile');
  screen?.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const card = target.closest('[data-profile-game-cosmetic]');
    if (card instanceof HTMLElement) {
      const itemId = String(card.dataset.profileGameCosmetic || '');
      if (reversiItemById(itemId)) {
        upgradeReversiSheet(itemId);
        scheduleProfileReversiParityRepair();
      }
      return;
    }

    const gameTab = target.closest('[data-profile-game-tab]');
    if (gameTab instanceof HTMLElement) {
      const selectedGame = String(gameTab.dataset.profileGameTab || '');
      const panel = screen.querySelector('.profile-v2-game-panel');
      if (selectedGame !== 'reversi' && panel instanceof HTMLElement) {
        panel.removeAttribute('data-mgw-reversi-profile-signature');
      }
      scheduleProfileReversiParityRepair();
    }
  });

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (target?.closest('#mgwGameCosmeticEquip')) scheduleProfileReversiParityRepair();
  });

  document.addEventListener('mgw:cosmetic-inventory-changed', scheduleProfileReversiParityRepair);
  document.addEventListener('mgw:open-profile', scheduleProfileReversiParityRepair);
  document.addEventListener('mgw:screen-changed', event => {
    if (event?.detail?.to === 'profile') scheduleProfileReversiParityRepair();
  });
}

function ensureReversiProfileStyles(){
  ensureStyle('data-mgw-reversi-store-cosmetics', '../../css/games/reversi/store-cosmetics-v1.css?v=2&mvp19_7=manual-review-corrective-v2');
  ensureStyle('data-mgw-profile-reversi-parity', '../../css/screens/profile-reversi-store-parity-v1.css?v=1&mvp19_7=reversi-profile-parity-v1');
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
      scheduleProfileReversiParityRepair();
    }
  };
  Object.defineProperty(wrapped, PROFILE_API_REPAIR_HOOK, { value:true });
  api.profileV2 = wrapped;
}

function scheduleProfileReversiParityRepair(){
  const repair = () => upgradeProfileReversiPresentation();
  queueMicrotask(repair);
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(repair);
  globalThis.setTimeout(repair, 0);
  globalThis.setTimeout(repair, 80);
}

function upgradeProfileReversiPresentation(){
  ensureReversiProfileStyles();
  const screen = document.getElementById('screen-profile');
  if (!(screen instanceof HTMLElement)) return;

  const reversiTab = screen.querySelector('[data-profile-game-tab="reversi"]');
  if (reversiTab instanceof HTMLElement) {
    const label = reversiTab.querySelector('span:last-child');
    if (label instanceof HTMLElement) label.textContent = 'Реверси';
  }

  const activeReversi = reversiTab instanceof HTMLElement
    && (reversiTab.classList.contains('active') || reversiTab.getAttribute('aria-selected') === 'true');
  if (!activeReversi) return;

  const panel = screen.querySelector('.profile-v2-game-panel');
  if (!(panel instanceof HTMLElement)) return;

  const items = ownedReversiItems();
  const signature = reversiPanelSignature(items);
  const canonical = panel.querySelector('[data-mgw-reversi-profile-group], [data-mgw-reversi-profile-empty]') instanceof HTMLElement;
  if (panel.dataset.mgwReversiProfileSignature !== signature || !canonical) {
    panel.innerHTML = renderReversiGroups(items);
    panel.dataset.mgwReversiProfileSignature = signature;
    panel.dataset.profileGamePanel = 'reversi';
  }
}

function renderReversiGroups(items){
  const groups = ['theme','elements','effect']
    .map(layer => ({ layer, items:items.filter(item => reversiLayer(item) === layer) }))
    .filter(group => group.items.length > 0);

  if (!groups.length) {
    return '<div class="profile-v2-game-empty" data-mgw-reversi-profile-empty="1">Купленные предметы для Реверси появятся здесь.</div>';
  }

  return groups.map(group => `
    <div class="profile-v2-game-group" data-mgw-reversi-profile-group="${group.layer}">
      <div class="profile-v2-game-group-title">${GROUP_TITLES[group.layer]}</div>
      <div class="profile-v2-game-grid">${group.items.map(reversiCardMarkup).join('')}</div>
    </div>
  `).join('');
}

function reversiCardMarkup(item){
  const itemId = String(item.item_id || '');
  const active = isReversiItemEquipped(item);
  const available = String(item.catalog_status || '') === 'active';
  const name = reversiDisplayName(item);
  return `<button class="profile-v2-game-card${active ? ' active' : ''}${available ? '' : ' unavailable'}" type="button" data-profile-game-cosmetic="${escapeHtml(itemId)}" aria-label="${escapeHtml(name)}" aria-pressed="${active ? 'true' : 'false'}">${reversiPreviewMarkup(item)}<span class="profile-v2-game-card-name">${escapeHtml(name)}</span>${active ? '<i class="profile-v2-selected-check" aria-hidden="true">✓</i>' : ''}</button>`;
}

function upgradeReversiSheet(itemId){
  const item = reversiItemById(itemId);
  if (!item) return;
  const sheet = document.getElementById('sheet');
  if (!(sheet instanceof HTMLElement)) return;

  const previewWrap = sheet.querySelector('.profile-v2-game-preview-wrap');
  if (previewWrap instanceof HTMLElement) previewWrap.innerHTML = reversiPreviewMarkup(item);

  const title = sheet.querySelector('.sheet-head h2');
  if (title instanceof HTMLElement) title.textContent = reversiDisplayName(item);

  const strong = sheet.querySelector('.profile-v2-game-preview-meta strong');
  if (strong instanceof HTMLElement) strong.textContent = 'Реверси';

  const group = sheet.querySelector('.profile-v2-game-preview-meta small');
  if (group instanceof HTMLElement) group.textContent = GROUP_TITLES[reversiLayer(item)] || 'Оформление';
}

function ownedReversiItems(){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const catalog = Array.isArray(inventory?.catalog) ? inventory.catalog : [];
  return catalog
    .filter(item => item && item.owned === true && item.item_type === 'game' && reversiGameType(item) === 'reversi')
    .map(item => ({ ...item, item_id:String(item.item_id || ''), equip_slot:String(item.equip_slot || '') }))
    .filter(item => GROUP_TITLES[reversiLayer(item)] && ITEM_ORDER.includes(item.item_id))
    .sort((left, right) => ITEM_ORDER.indexOf(left.item_id) - ITEM_ORDER.indexOf(right.item_id));
}

function reversiItemById(itemId){
  return ownedReversiItems().find(item => item.item_id === String(itemId || '')) || null;
}

function reversiGameType(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  const explicit = String(metadata.game_type || '').trim();
  if (explicit) return explicit;
  const family = String(item?.item_family || '').trim();
  return family.startsWith('game_') ? family.slice(5) : '';
}

function reversiLayer(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  const explicit = String(metadata.layer || '').trim();
  if (GROUP_TITLES[explicit]) return explicit;
  const slot = String(item?.equip_slot || '');
  if (slot.endsWith('_theme')) return 'theme';
  if (slot.endsWith('_elements')) return 'elements';
  if (slot.endsWith('_effect')) return 'effect';
  return '';
}

function reversiVariant(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  const fallback = reversiLayer(item) === 'effect' ? 'placement' : 'green';
  return String(metadata.variant || fallback).trim().toLowerCase().replace(/[^a-z0-9-]/g, '') || fallback;
}

function reversiDisplayName(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  return String(metadata.display_name || item?.item_id || 'Оформление Реверси').trim();
}

function isReversiItemEquipped(item){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const equipped = inventory?.equipped && typeof inventory.equipped === 'object' ? inventory.equipped : {};
  const slot = String(item?.equip_slot || '');
  const itemId = String(item?.item_id || '');
  return slot !== '' && itemId !== '' && String(equipped[slot] || '') === itemId;
}

function reversiPanelSignature(items){
  return JSON.stringify(items.map(item => [
    String(item.item_id || ''),
    reversiLayer(item),
    reversiVariant(item),
    reversiDisplayName(item),
    String(item.catalog_status || ''),
    isReversiItemEquipped(item),
  ]));
}

function reversiPreviewMarkup(item){
  const layer = reversiLayer(item);
  const variant = reversiVariant(item);
  const name = reversiDisplayName(item);
  return `<div class="store-v2-game-preview" data-game-type="reversi" data-cosmetic-layer="${layer}" data-cosmetic-variant="${variant}" role="img" aria-label="${escapeHtml(name)}">${previewMarkup(layer, variant)}</div>`;
}

function previewMarkup(layer, variant){
  if (layer === 'theme') return boardMarkup(`theme-${safeVariant(variant)}`, baseDiscScenario(), 'theme');
  if (layer === 'elements') return boardMarkup(`pieces-${safeVariant(variant)}`, baseDiscScenario(), 'pieces');
  return boardMarkup(`effect-${safeVariant(variant)}`, effectScenario(variant), 'effect');
}

function baseDiscScenario(){
  return new Map([
    [27,{ color:'white' }],
    [28,{ color:'black' }],
    [35,{ color:'black' }],
    [36,{ color:'white' }],
  ]);
}

function effectScenario(variant){
  if (variant === 'placement') {
    return new Map([
      [20,{ color:'black', classes:['placed','fx-placement-target'] }],
      [27,{ color:'white' }],
      [28,{ color:'black' }],
      [35,{ color:'black' }],
      [36,{ color:'white' }],
    ]);
  }
  if (variant === 'line') {
    return new Map([
      [19,{ color:'black', classes:['placed'] }],
      [27,{ color:'white', classes:['fx-line-target'], step:0 }],
      [35,{ color:'white', classes:['fx-line-target'], step:1 }],
      [43,{ color:'white', classes:['fx-line-target'], step:2 }],
      [28,{ color:'black' }],
      [36,{ color:'white' }],
    ]);
  }
  return new Map([
    [28,{ color:'black', classes:['placed'] }],
    [27,{ color:'white', classes:['fx-mass-target'], step:0 }],
    [35,{ color:'white', classes:['fx-mass-target'], step:1 }],
    [36,{ color:'white', classes:['fx-mass-target'], step:2 }],
    [37,{ color:'white', classes:['fx-mass-target'], step:3 }],
    [44,{ color:'white', classes:['fx-mass-target'], step:4 }],
    [20,{ color:'black' }],
    [45,{ color:'black' }],
  ]);
}

function boardMarkup(variantClass, discs, mode){
  const cells = Array.from({ length:64 }, (_, index) => {
    const disc = discs.get(index);
    if (!disc) return '<span></span>';
    const classes = ['mgw-rv-disc', disc.color, ...(disc.classes || [])].join(' ');
    const style = Number.isInteger(disc.step) ? ` style="--fx-step:${disc.step}"` : '';
    return `<span><i class="${classes}"${style}></i></span>`;
  }).join('');
  return `<i class="mgw-reversi-preview ${variantClass} ${mode}" aria-hidden="true"><span class="mgw-rv-board">${cells}</span></i>`;
}

function safeVariant(value){
  return String(value || '').replace(/[^a-z0-9-]/gi, '').toLowerCase() || 'base';
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#39;');
}
