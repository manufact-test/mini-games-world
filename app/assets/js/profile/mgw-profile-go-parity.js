import { api } from '../api/client.js?v=47';
import { state } from '../state.js?v=27';

const GROUP_TITLES = Object.freeze({ theme:'Доски', elements:'Камни', effect:'Эффекты' });
const ITEM_ORDER = Object.freeze([
  'game-go-board-wood',
  'game-go-board-dark',
  'game-go-board-stone',
  'game-go-board-neon',
  'game-go-stones-classic',
  'game-go-stones-marble',
  'game-go-stones-glass',
  'game-go-stones-neon',
  'game-go-effect-placement',
  'game-go-effect-group-capture',
  'game-go-effect-territory-finish',
]);
const PROFILE_API_REPAIR_HOOK = Symbol.for('mgw.profile.go-store-parity.profile-v2.v2');
let initialized = false;

ensureGoProfileStyles();
installProfileApiRepairHook();

export function initProfileGoParity(){
  upgradeProfileGoPresentation();
  if (initialized) return;
  initialized = true;

  const screen = document.getElementById('screen-profile');
  screen?.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const card = target.closest('[data-profile-game-cosmetic]');
    if (card instanceof HTMLElement) {
      const itemId = String(card.dataset.profileGameCosmetic || '');
      if (goItemById(itemId)) {
        upgradeGoSheet(itemId);
        scheduleProfileGoParityRepair();
      }
      return;
    }

    const gameTab = target.closest('[data-profile-game-tab]');
    if (gameTab instanceof HTMLElement) {
      const selectedGame = String(gameTab.dataset.profileGameTab || '');
      const panel = screen.querySelector('.profile-v2-game-panel');
      if (selectedGame === 'go') {
        activateGoTab(screen, gameTab);
      } else if (panel instanceof HTMLElement) {
        panel.removeAttribute('data-mgw-go-profile-signature');
      }
      scheduleProfileGoParityRepair();
    }
  });

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (target?.closest('#mgwGameCosmeticEquip')) scheduleProfileGoParityRepair();
  });
  document.addEventListener('mgw:cosmetic-inventory-changed', scheduleProfileGoParityRepair);
  document.addEventListener('mgw:open-profile', scheduleProfileGoParityRepair);
  document.addEventListener('mgw:screen-changed', event => {
    if (event?.detail?.to === 'profile') scheduleProfileGoParityRepair();
  });
}

function ensureGoProfileStyles(){
  ensureStyle('data-mgw-go-store', '../../css/games/go/store-cosmetics-v1.css?v=2&mvp19_8=effects-premium-v2');
  ensureStyle('data-mgw-profile-go-parity', '../../css/screens/profile-go-store-parity-v1.css?v=2&mvp19_8=go-profile-corrective-v2');
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
  const wrapped = async (...args) => {
    try {
      return await current.apply(api, args);
    } finally {
      scheduleProfileGoParityRepair();
    }
  };
  Object.defineProperty(wrapped, PROFILE_API_REPAIR_HOOK, { value:true });
  api.profileV2 = wrapped;
}

function scheduleProfileGoParityRepair(){
  const repair = () => upgradeProfileGoPresentation();
  queueMicrotask(repair);
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(repair);
  globalThis.setTimeout(repair, 0);
  globalThis.setTimeout(repair, 80);
  globalThis.setTimeout(repair, 260);
}

function ensureGoTab(screen){
  const tabs = screen.querySelector('.profile-v2-game-tabs');
  if (!(tabs instanceof HTMLElement)) return null;

  let goTab = tabs.querySelector('[data-profile-game-tab="go"]');
  if (!(goTab instanceof HTMLButtonElement)) {
    goTab = document.createElement('button');
    goTab.className = 'profile-v2-game-tab';
    goTab.type = 'button';
    goTab.setAttribute('role', 'tab');
    goTab.dataset.profileGameTab = 'go';
    goTab.setAttribute('aria-selected', 'false');
    goTab.innerHTML = '<span class="profile-v2-game-tab-mark" aria-hidden="true">●○</span><span>Го</span>';

    const dominoTab = tabs.querySelector('[data-profile-game-tab="domino"]');
    tabs.insertBefore(goTab, dominoTab instanceof HTMLElement ? dominoTab : null);
  }

  const label = goTab.querySelector('span:last-child');
  if (label instanceof HTMLElement) label.textContent = 'Го';
  return goTab;
}

function activateGoTab(screen, goTab){
  screen.querySelectorAll('[data-profile-game-tab]').forEach(button => {
    const active = button === goTab;
    button.classList.toggle('active', active);
    button.setAttribute('aria-selected', active ? 'true' : 'false');
  });

  const panel = screen.querySelector('.profile-v2-game-panel');
  if (!(panel instanceof HTMLElement)) return;
  panel.dataset.profileGamePanel = 'go';
  panel.removeAttribute('data-mgw-checkers-profile-signature');
  panel.removeAttribute('data-mgw-reversi-profile-signature');
}

function upgradeProfileGoPresentation(){
  ensureGoProfileStyles();
  const screen = document.getElementById('screen-profile');
  if (!(screen instanceof HTMLElement)) return;

  const goTab = ensureGoTab(screen);
  const activeGo = goTab instanceof HTMLElement
    && (goTab.classList.contains('active') || goTab.getAttribute('aria-selected') === 'true');
  if (!activeGo) return;

  const panel = screen.querySelector('.profile-v2-game-panel');
  if (!(panel instanceof HTMLElement)) return;
  const items = ownedGoItems();
  const signature = goPanelSignature(items);
  const canonical = panel.querySelector('[data-mgw-go-profile-group], [data-mgw-go-profile-empty]') instanceof HTMLElement;
  if (panel.dataset.mgwGoProfileSignature !== signature || !canonical) {
    panel.innerHTML = renderGoGroups(items);
    panel.dataset.mgwGoProfileSignature = signature;
    panel.dataset.profileGamePanel = 'go';
  }
}

function renderGoGroups(items){
  const groups = ['theme','elements','effect']
    .map(layer => ({ layer, items:items.filter(item => goLayer(item) === layer) }))
    .filter(group => group.items.length > 0);
  if (!groups.length) {
    return '<div class="profile-v2-game-empty" data-mgw-go-profile-empty="1">Купленные предметы для Го появятся здесь.</div>';
  }
  return groups.map(group => `
    <div class="profile-v2-game-group" data-mgw-go-profile-group="${group.layer}">
      <div class="profile-v2-game-group-title">${GROUP_TITLES[group.layer]}</div>
      <div class="profile-v2-game-grid">${group.items.map(goCardMarkup).join('')}</div>
    </div>
  `).join('');
}

function goCardMarkup(item){
  const itemId = String(item.item_id || '');
  const active = isGoItemEquipped(item);
  const available = String(item.catalog_status || '') === 'active';
  const name = goDisplayName(item);
  return `<button class="profile-v2-game-card${active ? ' active' : ''}${available ? '' : ' unavailable'}" type="button" data-profile-game-cosmetic="${escapeHtml(itemId)}" aria-label="${escapeHtml(name)}" aria-pressed="${active ? 'true' : 'false'}">${goPreviewMarkup(item)}<span class="profile-v2-game-card-name">${escapeHtml(name)}</span>${active ? '<i class="profile-v2-selected-check" aria-hidden="true">✓</i>' : ''}</button>`;
}

function upgradeGoSheet(itemId){
  const item = goItemById(itemId);
  if (!item) return;
  const sheet = document.getElementById('sheet');
  if (!(sheet instanceof HTMLElement)) return;
  const previewWrap = sheet.querySelector('.profile-v2-game-preview-wrap');
  if (previewWrap instanceof HTMLElement) previewWrap.innerHTML = goPreviewMarkup(item);
  const title = sheet.querySelector('.sheet-head h2');
  if (title instanceof HTMLElement) title.textContent = goDisplayName(item);
  const strong = sheet.querySelector('.profile-v2-game-preview-meta strong');
  if (strong instanceof HTMLElement) strong.textContent = 'Го';
  const group = sheet.querySelector('.profile-v2-game-preview-meta small');
  if (group instanceof HTMLElement) group.textContent = GROUP_TITLES[goLayer(item)] || 'Оформление';
}

function ownedGoItems(){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const catalog = Array.isArray(inventory?.catalog) ? inventory.catalog : [];
  return catalog
    .filter(item => item && item.owned === true && item.item_type === 'game' && goGameType(item) === 'go')
    .map(item => ({ ...item, item_id:String(item.item_id || ''), equip_slot:String(item.equip_slot || '') }))
    .filter(item => GROUP_TITLES[goLayer(item)] && ITEM_ORDER.includes(item.item_id))
    .sort((left, right) => ITEM_ORDER.indexOf(left.item_id) - ITEM_ORDER.indexOf(right.item_id));
}

function goItemById(itemId){
  return ownedGoItems().find(item => item.item_id === String(itemId || '')) || null;
}

function goGameType(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  const explicit = String(metadata.game_type || '').trim();
  if (explicit) return explicit;
  const family = String(item?.item_family || '').trim();
  return family.startsWith('game_') ? family.slice(5) : '';
}

function goLayer(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  const explicit = String(metadata.layer || '').trim();
  if (GROUP_TITLES[explicit]) return explicit;
  const slot = String(item?.equip_slot || '');
  if (slot.endsWith('_theme')) return 'theme';
  if (slot.endsWith('_elements')) return 'elements';
  if (slot.endsWith('_effect')) return 'effect';
  return '';
}

function goVariant(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  const fallback = goLayer(item) === 'effect' ? 'placement' : (goLayer(item) === 'elements' ? 'classic' : 'wood');
  return String(metadata.variant || fallback).trim().toLowerCase().replace(/[^a-z0-9-]/g, '') || fallback;
}

function goDisplayName(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  return String(metadata.display_name || item?.item_id || 'Оформление Го').trim();
}

function isGoItemEquipped(item){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const equipped = inventory?.equipped && typeof inventory.equipped === 'object' ? inventory.equipped : {};
  const slot = String(item?.equip_slot || '');
  const itemId = String(item?.item_id || '');
  return slot !== '' && itemId !== '' && String(equipped[slot] || '') === itemId;
}

function goPanelSignature(items){
  return JSON.stringify(items.map(item => [
    String(item.item_id || ''), goLayer(item), goVariant(item), goDisplayName(item),
    String(item.catalog_status || ''), isGoItemEquipped(item),
  ]));
}

function goPreviewMarkup(item){
  const layer = goLayer(item);
  const variant = goVariant(item);
  const name = goDisplayName(item);
  return `<div class="store-v2-game-preview" data-game-type="go" data-cosmetic-layer="${layer}" data-cosmetic-variant="${variant}" role="img" aria-label="${escapeHtml(name)}">${previewMarkup(layer, variant)}</div>`;
}

function previewMarkup(layer, variant){
  if (layer === 'theme') return boardMarkup(`theme-${safeVariant(variant)}`, baseScenario(), 'theme');
  if (layer === 'elements') return boardMarkup(`stones-${safeVariant(variant)}`, baseScenario(), 'stones');
  return boardMarkup(`effect-${safeVariant(variant)}`, effectScenario(variant), 'effect');
}

function baseScenario(){
  return new Map([
    [20,{ color:'black' }], [21,{ color:'black' }], [29,{ color:'black' }],
    [31,{ color:'white' }], [39,{ color:'white' }], [40,{ color:'white' }],
    [49,{ color:'black' }], [50,{ color:'white' }],
  ]);
}

function effectScenario(variant){
  if (variant === 'placement') {
    const stones = baseScenario();
    stones.set(30,{ color:'black', classes:['fx-placement-target'] });
    return stones;
  }
  if (variant === 'group-capture') {
    return new Map([
      [19,{ color:'black' }], [20,{ color:'black' }], [21,{ color:'black' }],
      [28,{ color:'black' }], [30,{ color:'black' }], [37,{ color:'black' }], [38,{ color:'black' }], [39,{ color:'black' }],
      [29,{ color:'white', classes:['fx-capture-target'], step:0 }],
      [31,{ color:'white', classes:['fx-capture-target'], step:1 }],
      [40,{ color:'white', classes:['fx-capture-target'], step:2 }],
    ]);
  }
  return new Map([
    [11,{ color:'black' }], [12,{ color:'black' }], [13,{ color:'black' }], [20,{ color:'black' }], [29,{ color:'black' }],
    [51,{ color:'white' }], [52,{ color:'white' }], [53,{ color:'white' }], [44,{ color:'white' }], [35,{ color:'white' }],
    [21,{ marker:'black', step:0 }], [22,{ marker:'black', step:1 }], [30,{ marker:'black', step:2 }],
    [43,{ marker:'white', step:3 }], [42,{ marker:'white', step:4 }], [34,{ marker:'white', step:5 }],
  ]);
}

function boardMarkup(variantClass, points, mode){
  const stones = [];
  const markers = [];
  points.forEach((point, cell) => {
    const pos = pointPosition(cell);
    if (point.color) {
      const classes = ['mgw-go-stone', point.color, ...(point.classes || [])].join(' ');
      const style = Number.isInteger(point.step) ? `${pos};--fx-step:${point.step}` : pos;
      stones.push(`<i class="${classes}" style="${style}"></i>`);
    }
    if (point.marker) {
      const style = Number.isInteger(point.step) ? `${pos};--fx-step:${point.step}` : pos;
      markers.push(`<em class="mgw-go-territory ${point.marker}" style="${style}"></em>`);
    }
  });
  return `<i class="mgw-go-preview ${variantClass} ${mode}" aria-hidden="true"><span class="mgw-go-board">${gridSvg()}${starMarkup()}${stones.join('')}${markers.join('')}</span></i>`;
}

function pointPosition(cell){
  const row = Math.floor(cell / 9);
  const col = cell % 9;
  const inset = 7;
  const span = 86;
  return `--go-x:${inset + (col / 8) * span}%;--go-y:${inset + (row / 8) * span}%`;
}

function gridSvg(){
  const inset = 7;
  const span = 86;
  const lines = [];
  for (let index = 0; index < 9; index += 1) {
    const position = inset + (index / 8) * span;
    lines.push(`<line x1="${inset}" y1="${position}" x2="${100 - inset}" y2="${position}"></line>`);
    lines.push(`<line x1="${position}" y1="${inset}" x2="${position}" y2="${100 - inset}"></line>`);
  }
  return `<svg class="mgw-go-grid" viewBox="0 0 100 100" preserveAspectRatio="none">${lines.join('')}</svg>`;
}

function starMarkup(){
  return [2,4,6].flatMap(row => [2,4,6].map(col => `<b class="mgw-go-star" style="${pointPosition(row * 9 + col)}"></b>`)).join('');
}

function safeVariant(value){
  return String(value || '').replace(/[^a-z0-9-]/gi, '').toLowerCase() || 'base';
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')
    .replaceAll('"','&quot;').replaceAll("'",'&#39;');
}