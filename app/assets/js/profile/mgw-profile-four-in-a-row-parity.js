import { api } from '../api/client.js?v=47';
import { state } from '../state.js?v=27';
import { fourInARowPreviewMarkup } from '../screens/store-screen-four-in-a-row-store-v1.js?v=6&four_store=static-v4&export=profile-preview-v1&copy=human-v1';

const GROUP_TITLES = Object.freeze({ theme:'Поля', elements:'Фишки', effect:'Эффекты' });
const ITEM_ORDER = Object.freeze([
  'game-four-field-blue',
  'game-four-field-dark',
  'game-four-field-metal',
  'game-four-field-neon',
  'game-four-discs-classic',
  'game-four-discs-3d',
  'game-four-discs-metal',
  'game-four-discs-neon',
  'game-four-effect-drop',
  'game-four-effect-four',
  'game-four-effect-victory-wave',
]);
const PROFILE_API_REPAIR_HOOK = Symbol.for('mgw.profile.four-in-a-row.store-parity.v1');
let initialized = false;

ensureFourProfileStyles();
installProfileApiRepairHook();

export function initProfileFourInARowParity(){
  upgradeProfileFourPresentation();
  if (initialized) return;
  initialized = true;

  const screen = document.getElementById('screen-profile');
  screen?.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const card = target.closest('[data-profile-game-cosmetic]');
    if (card instanceof HTMLElement) {
      const itemId = String(card.dataset.profileGameCosmetic || '');
      if (fourItemById(itemId)) {
        queueMicrotask(() => upgradeFourSheet(itemId));
        scheduleProfileFourRepair();
      }
      return;
    }

    const gameTab = target.closest('[data-profile-game-tab]');
    if (gameTab instanceof HTMLElement) {
      const selectedGame = String(gameTab.dataset.profileGameTab || '');
      const panel = screen.querySelector('.profile-v2-game-panel');
      if (selectedGame === 'four_in_a_row') {
        activateFourTab(screen, gameTab);
      } else if (panel instanceof HTMLElement) {
        panel.removeAttribute('data-mgw-four-profile-signature');
      }
      scheduleProfileFourRepair();
    }
  });

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (target?.closest('#mgwGameCosmeticEquip')) scheduleProfileFourRepair();
  });
  document.addEventListener('mgw:cosmetic-inventory-changed', scheduleProfileFourRepair);
  document.addEventListener('mgw:open-profile', scheduleProfileFourRepair);
  document.addEventListener('mgw:screen-changed', event => {
    if (event?.detail?.to === 'profile') scheduleProfileFourRepair();
  });
}

function ensureFourProfileStyles(){
  ensureStyle('data-mgw-four-store-profile', '../../css/games/four-in-a-row/store-cosmetics-v1.css?v=4&four_store=static-v4');
  ensureStyle('data-mgw-profile-four-parity', '../../css/screens/profile-four-in-a-row-store-parity-v1.css?v=2&four_profile=spacing-v2');
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
      scheduleProfileFourRepair();
    }
  };
  Object.defineProperty(wrapped, PROFILE_API_REPAIR_HOOK, { value:true });
  api.profileV2 = wrapped;
}

function scheduleProfileFourRepair(){
  const repair = () => upgradeProfileFourPresentation();
  queueMicrotask(repair);
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(repair);
  globalThis.setTimeout(repair, 0);
  globalThis.setTimeout(repair, 80);
  globalThis.setTimeout(repair, 260);
}

function ensureFourTab(screen){
  const tabs = screen.querySelector('.profile-v2-game-tabs');
  if (!(tabs instanceof HTMLElement)) return null;

  const items = ownedFourItems();
  if (!items.length) return null;

  let tab = tabs.querySelector('[data-profile-game-tab="four_in_a_row"]');
  if (!(tab instanceof HTMLButtonElement)) {
    tab = document.createElement('button');
    tab.className = 'profile-v2-game-tab';
    tab.type = 'button';
    tab.setAttribute('role', 'tab');
    tab.dataset.profileGameTab = 'four_in_a_row';
    tab.setAttribute('aria-selected', 'false');
    tab.innerHTML = '<span class="profile-v2-game-tab-mark mgw-four-profile-tab-mark" aria-hidden="true"></span><span>4 в ряд</span>';

    const tttTab = tabs.querySelector('[data-profile-game-tab="tictactoe"]');
    if (tttTab instanceof HTMLElement && tttTab.nextSibling) tabs.insertBefore(tab, tttTab.nextSibling);
    else tabs.prepend(tab);
  }

  tab.querySelector('.profile-v2-game-tab-mark')?.classList.add('mgw-four-profile-tab-mark');
  const label = tab.querySelector('span:last-child');
  if (label instanceof HTMLElement) label.textContent = '4 в ряд';
  return tab;
}

function activateFourTab(screen, tab){
  screen.querySelectorAll('[data-profile-game-tab]').forEach(button => {
    const active = button === tab;
    button.classList.toggle('active', active);
    button.setAttribute('aria-selected', active ? 'true' : 'false');
  });

  const panel = screen.querySelector('.profile-v2-game-panel');
  if (!(panel instanceof HTMLElement)) return;
  panel.dataset.profileGamePanel = 'four_in_a_row';
  panel.removeAttribute('data-mgw-checkers-profile-signature');
  panel.removeAttribute('data-mgw-reversi-profile-signature');
  panel.removeAttribute('data-mgw-go-profile-signature');
  panel.removeAttribute('data-mgw-domino-profile-signature');
}

function upgradeProfileFourPresentation(){
  ensureFourProfileStyles();
  const screen = document.getElementById('screen-profile');
  if (!(screen instanceof HTMLElement)) return;

  const tab = ensureFourTab(screen);
  const active = tab instanceof HTMLElement
    && (tab.classList.contains('active') || tab.getAttribute('aria-selected') === 'true');
  if (!active) return;

  const panel = screen.querySelector('.profile-v2-game-panel');
  if (!(panel instanceof HTMLElement)) return;
  const items = ownedFourItems();
  const signature = fourPanelSignature(items);
  const canonical = panel.querySelector('[data-mgw-four-profile-group], [data-mgw-four-profile-empty]') instanceof HTMLElement;
  if (panel.dataset.mgwFourProfileSignature !== signature || !canonical) {
    panel.innerHTML = renderFourGroups(items);
    panel.dataset.mgwFourProfileSignature = signature;
    panel.dataset.profileGamePanel = 'four_in_a_row';
  }
}

function renderFourGroups(items){
  const groups = ['theme','elements','effect']
    .map(layer => ({ layer, items:items.filter(item => fourLayer(item) === layer) }))
    .filter(group => group.items.length > 0);

  if (!groups.length) {
    return '<div class="profile-v2-game-empty" data-mgw-four-profile-empty="1">Купленные предметы для 4 в ряд появятся здесь.</div>';
  }

  return groups.map(group => `
    <div class="profile-v2-game-group" data-mgw-four-profile-group="${group.layer}">
      <div class="profile-v2-game-group-title">${GROUP_TITLES[group.layer]}</div>
      <div class="profile-v2-game-grid">${group.items.map(fourCardMarkup).join('')}</div>
    </div>
  `).join('');
}

function fourCardMarkup(item){
  const itemId = String(item.item_id || '');
  const active = isFourItemEquipped(item);
  const available = String(item.catalog_status || '') === 'active';
  const name = fourDisplayName(item);
  return `<button class="profile-v2-game-card${active ? ' active' : ''}${available ? '' : ' unavailable'}" type="button" data-profile-game-cosmetic="${escapeHtml(itemId)}" aria-label="${escapeHtml(name)}" aria-pressed="${active ? 'true' : 'false'}">${fourPreviewMarkup(item)}<span class="profile-v2-game-card-name">${escapeHtml(name)}</span>${active ? '<i class="profile-v2-selected-check" aria-hidden="true">✓</i>' : ''}</button>`;
}

function upgradeFourSheet(itemId){
  const item = fourItemById(itemId);
  if (!item) return;
  const sheet = document.getElementById('sheet');
  if (!(sheet instanceof HTMLElement)) return;

  const previewWrap = sheet.querySelector('.profile-v2-game-preview-wrap');
  if (previewWrap instanceof HTMLElement) previewWrap.innerHTML = fourPreviewMarkup(item);

  const title = sheet.querySelector('.sheet-head h2');
  if (title instanceof HTMLElement) title.textContent = fourDisplayName(item);

  const strong = sheet.querySelector('.profile-v2-game-preview-meta strong');
  if (strong instanceof HTMLElement) strong.textContent = '4 в ряд';

  const group = sheet.querySelector('.profile-v2-game-preview-meta small');
  if (group instanceof HTMLElement) group.textContent = GROUP_TITLES[fourLayer(item)] || 'Оформление';
}

function ownedFourItems(){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const catalog = Array.isArray(inventory?.catalog) ? inventory.catalog : [];
  return catalog
    .filter(item => item && item.owned === true && item.item_type === 'game' && fourGameType(item) === 'four_in_a_row')
    .map(item => ({ ...item, item_id:String(item.item_id || ''), equip_slot:String(item.equip_slot || '') }))
    .filter(item => GROUP_TITLES[fourLayer(item)] && ITEM_ORDER.includes(item.item_id))
    .sort((left, right) => ITEM_ORDER.indexOf(left.item_id) - ITEM_ORDER.indexOf(right.item_id));
}

function fourItemById(itemId){
  return ownedFourItems().find(item => item.item_id === String(itemId || '')) || null;
}

function fourGameType(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  const explicit = String(metadata.game_type || '').trim();
  if (explicit) return explicit;
  const family = String(item?.item_family || '').trim();
  return family.startsWith('game_') ? family.slice(5) : '';
}

function fourLayer(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  const explicit = String(metadata.layer || '').trim();
  if (GROUP_TITLES[explicit]) return explicit;
  const slot = String(item?.equip_slot || '');
  if (slot.endsWith('_theme')) return 'theme';
  if (slot.endsWith('_elements')) return 'elements';
  if (slot.endsWith('_effect')) return 'effect';
  return '';
}

function fourVariant(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  const fallback = fourLayer(item) === 'effect' ? 'drop' : (fourLayer(item) === 'elements' ? 'classic' : 'blue');
  return String(metadata.variant || fallback).trim().replace(/[^a-z0-9-]/gi, '').toLowerCase() || fallback;
}

function fourDisplayName(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  return String(metadata.display_name || item?.item_id || 'Предмет 4 в ряд').trim();
}

function isFourItemEquipped(item){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const equipped = inventory?.equipped && typeof inventory.equipped === 'object' ? inventory.equipped : {};
  const slot = String(item?.equip_slot || '');
  return slot !== '' && String(equipped[slot] || '') === String(item?.item_id || '');
}

function fourPanelSignature(items){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const equipped = inventory?.equipped && typeof inventory.equipped === 'object' ? inventory.equipped : {};
  return items.map(item => [
    String(item.item_id || ''),
    fourLayer(item),
    fourVariant(item),
    fourDisplayName(item),
    String(equipped[String(item.equip_slot || '')] || ''),
  ].join(':')).join('|');
}

function fourPreviewMarkup(item){
  const layer = fourLayer(item);
  const variant = fourVariant(item);
  const name = fourDisplayName(item);
  return `<div class="store-v2-game-preview" data-game-type="four_in_a_row" data-cosmetic-layer="${layer}" data-cosmetic-variant="${variant}" role="img" aria-label="${escapeHtml(name)}">${fourInARowPreviewMarkup(layer, variant)}</div>`;
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}
