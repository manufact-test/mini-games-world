import { api } from '../api/client.js?v=47';
import { state } from '../state.js?v=27';
import { battleshipPreviewMarkup } from '../screens/store-screen-battleship-store-v1.js?v=13&mvp19_12=store-preview-parity-v13&header=steel-ship&neon_frame=outer-safe&neon_fleet=tube-v4&fleet_preview=svg-models-v3&neon_map_ships=white-v1&preview_geometry=svg-circles-v6&hydration=observer-v1&inline_owner=svg-v5&effects=live-parity-shot-v2';

const GROUP_TITLES = Object.freeze({ theme:'Карты', elements:'Флот', effect:'Эффекты' });
const ITEM_ORDER = Object.freeze([
  'game-battleship-map-sea',
  'game-battleship-map-dark-military',
  'game-battleship-map-storm',
  'game-battleship-map-neon',
  'game-battleship-fleet-classic',
  'game-battleship-fleet-modern',
  'game-battleship-fleet-armored',
  'game-battleship-fleet-neon',
  'game-battleship-effect-shot',
  'game-battleship-effect-hit',
  'game-battleship-effect-destroy',
]);
const PROFILE_API_REPAIR_HOOK = Symbol.for('mgw.profile.battleship.four-parity.v3');
let initialized = false;

ensureBattleshipProfileStyles();
installProfileApiRepairHook();

export function initProfileBattleshipParity(){
  upgradeProfileBattleshipPresentation();
  if (initialized) return;
  initialized = true;

  const screen = document.getElementById('screen-profile');
  screen?.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const card = target.closest('[data-profile-game-cosmetic]');
    if (card instanceof HTMLElement) {
      const itemId = String(card.dataset.profileGameCosmetic || '');
      if (battleshipItemById(itemId)) {
        // The base Profile listener opens the shared sheet first. Repair it
        // immediately on the same click, then once more after the event turn.
        upgradeBattleshipSheet(itemId);
        queueMicrotask(() => upgradeBattleshipSheet(itemId));
        scheduleProfileBattleshipRepair();
      }
      return;
    }

    const gameTab = target.closest('[data-profile-game-tab]');
    if (gameTab instanceof HTMLElement) {
      const selectedGame = String(gameTab.dataset.profileGameTab || '');
      const panel = screen.querySelector('.profile-v2-game-panel');
      if (selectedGame === 'battleship') {
        activateBattleshipTab(screen, gameTab);
      } else if (panel instanceof HTMLElement) {
        panel.removeAttribute('data-mgw-battleship-profile-signature');
      }
      scheduleProfileBattleshipRepair();
    }
  });

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (target?.closest('#mgwGameCosmeticEquip')) scheduleProfileBattleshipRepair();
  });
  document.addEventListener('mgw:cosmetic-inventory-changed', scheduleProfileBattleshipRepair);
  document.addEventListener('mgw:open-profile', scheduleProfileBattleshipRepair);
  document.addEventListener('mgw:screen-changed', event => {
    if (event?.detail?.to === 'profile') scheduleProfileBattleshipRepair();
  });
}

function ensureBattleshipProfileStyles(){
  ensureStyle(
    'data-mgw-battleship-store-profile',
    '../../css/games/battleship/store-cosmetics-v1.css?v=13&mvp19_12=store-preview-parity-v13&header=steel-ship&neon_frame=outer-safe&neon_fleet=tube-v4&fleet_preview=svg-models-v3&neon_map_ships=white-v1&preview_geometry=svg-circles-v6&hydration=observer-v1&inline_owner=svg-v5&effects=live-parity-shot-v2'
  );
  ensureStyle(
    'data-mgw-profile-battleship-parity',
    '../../css/screens/profile-battleship-store-parity-v1.css?v=2&mvp19_12=profile-four-parity-v3&copy=four-pattern'
  );
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
      scheduleProfileBattleshipRepair();
    }
  };

  Object.defineProperty(wrapped, PROFILE_API_REPAIR_HOOK, { value:true });
  api.profileV2 = wrapped;
}

function scheduleProfileBattleshipRepair(){
  const repair = () => upgradeProfileBattleshipPresentation();
  queueMicrotask(repair);
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(repair);
  globalThis.setTimeout(repair, 0);
  globalThis.setTimeout(repair, 80);
  globalThis.setTimeout(repair, 260);
}

function ensureBattleshipTab(screen){
  const tabs = screen.querySelector('.profile-v2-game-tabs');
  if (!(tabs instanceof HTMLElement)) return null;

  const items = ownedBattleshipItems();
  if (!items.length) return null;

  let tab = tabs.querySelector('[data-profile-game-tab="battleship"]');
  if (!(tab instanceof HTMLButtonElement)) {
    tab = document.createElement('button');
    tab.className = 'profile-v2-game-tab';
    tab.type = 'button';
    tab.setAttribute('role', 'tab');
    tab.dataset.profileGameTab = 'battleship';
    tab.setAttribute('aria-selected', 'false');

    const fourTab = tabs.querySelector('[data-profile-game-tab="four_in_a_row"]');
    if (fourTab instanceof HTMLElement && fourTab.nextSibling) tabs.insertBefore(tab, fourTab.nextSibling);
    else if (fourTab instanceof HTMLElement) tabs.appendChild(tab);
    else tabs.prepend(tab);
  }

  tab.innerHTML = `
    <span class="profile-v2-game-tab-mark mgw-battleship-profile-tab-mark" aria-hidden="true">
      <svg viewBox="0 0 30 20" focusable="false">
        <path class="mgw-bs-tab-hull" d="M3 11.5h24l-4 5H8l-5-5Z"></path>
        <rect class="mgw-bs-tab-cabin" x="10" y="6.5" width="10" height="5" rx="1"></rect>
        <rect class="mgw-bs-tab-bridge" x="13" y="3.5" width="4" height="3" rx=".7"></rect>
      </svg>
    </span>
    <span class="profile-v2-game-tab-label">Морской бой</span>
  `;
  return tab;
}

function activateBattleshipTab(screen, tab){
  screen.querySelectorAll('[data-profile-game-tab]').forEach(button => {
    const active = button === tab;
    button.classList.toggle('active', active);
    button.setAttribute('aria-selected', active ? 'true' : 'false');
  });

  const panel = screen.querySelector('.profile-v2-game-panel');
  if (!(panel instanceof HTMLElement)) return;
  panel.dataset.profileGamePanel = 'battleship';
  panel.removeAttribute('data-mgw-checkers-profile-signature');
  panel.removeAttribute('data-mgw-reversi-profile-signature');
  panel.removeAttribute('data-mgw-go-profile-signature');
  panel.removeAttribute('data-mgw-domino-profile-signature');
  panel.removeAttribute('data-mgw-four-profile-signature');
}

function upgradeProfileBattleshipPresentation(){
  ensureBattleshipProfileStyles();
  const screen = document.getElementById('screen-profile');
  if (!(screen instanceof HTMLElement)) return;

  const tab = ensureBattleshipTab(screen);
  const active = tab instanceof HTMLElement
    && (tab.classList.contains('active') || tab.getAttribute('aria-selected') === 'true');
  if (!active) return;

  const panel = screen.querySelector('.profile-v2-game-panel');
  if (!(panel instanceof HTMLElement)) return;

  const items = ownedBattleshipItems();
  const signature = battleshipPanelSignature(items);
  const canonical = panel.querySelector('[data-mgw-battleship-profile-group], [data-mgw-battleship-profile-empty]') instanceof HTMLElement;

  if (panel.dataset.mgwBattleshipProfileSignature !== signature || !canonical) {
    panel.innerHTML = renderBattleshipGroups(items);
    panel.dataset.mgwBattleshipProfileSignature = signature;
    panel.dataset.profileGamePanel = 'battleship';
  }
}

function renderBattleshipGroups(items){
  const groups = ['theme','elements','effect']
    .map(layer => ({ layer, items:items.filter(item => battleshipLayer(item) === layer) }))
    .filter(group => group.items.length > 0);

  if (!groups.length) {
    return '<div class="profile-v2-game-empty" data-mgw-battleship-profile-empty="1">Купленные предметы для Морского боя появятся здесь.</div>';
  }

  return groups.map(group => `
    <div class="profile-v2-game-group" data-mgw-battleship-profile-group="${group.layer}">
      <div class="profile-v2-game-group-title">${GROUP_TITLES[group.layer]}</div>
      <div class="profile-v2-game-grid">${group.items.map(battleshipCardMarkup).join('')}</div>
    </div>
  `).join('');
}

function battleshipCardMarkup(item){
  const itemId = String(item.item_id || '');
  const active = isBattleshipItemEquipped(item);
  const available = String(item.catalog_status || '') === 'active';
  const name = battleshipDisplayName(item);

  return `<button class="profile-v2-game-card${active ? ' active' : ''}${available ? '' : ' unavailable'}" type="button" data-profile-game-cosmetic="${escapeHtml(itemId)}" aria-label="${escapeHtml(name)}" aria-pressed="${active ? 'true' : 'false'}">${battleshipPreview(item)}<span class="profile-v2-game-card-name">${escapeHtml(name)}</span>${active ? '<i class="profile-v2-selected-check" aria-hidden="true">✓</i>' : ''}</button>`;
}

function upgradeBattleshipSheet(itemId){
  const item = battleshipItemById(itemId);
  if (!item) return;

  const sheet = document.getElementById('sheet');
  if (!(sheet instanceof HTMLElement)) return;

  const previewWrap = sheet.querySelector('.profile-v2-game-preview-wrap');
  if (previewWrap instanceof HTMLElement) previewWrap.innerHTML = battleshipPreview(item);

  const title = sheet.querySelector('.sheet-head h2');
  if (title instanceof HTMLElement) {
    title.textContent = battleshipDisplayName(item);
    title.style.removeProperty('display');
  }

  const strong = sheet.querySelector('.profile-v2-game-preview-meta strong');
  if (strong instanceof HTMLElement) strong.textContent = 'Морской бой';

  const group = sheet.querySelector('.profile-v2-game-preview-meta small');
  if (group instanceof HTMLElement) group.textContent = GROUP_TITLES[battleshipLayer(item)] || 'Оформление';
}

function ownedBattleshipItems(){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const catalog = Array.isArray(inventory?.catalog) ? inventory.catalog : [];

  return catalog
    .filter(item => item && item.owned === true && item.item_type === 'game' && battleshipGameType(item) === 'battleship')
    .map(item => ({ ...item, item_id:String(item.item_id || ''), equip_slot:String(item.equip_slot || '') }))
    .filter(item => GROUP_TITLES[battleshipLayer(item)] && ITEM_ORDER.includes(item.item_id))
    .sort((left, right) => ITEM_ORDER.indexOf(left.item_id) - ITEM_ORDER.indexOf(right.item_id));
}

function battleshipItemById(itemId){
  return ownedBattleshipItems().find(item => item.item_id === String(itemId || '')) || null;
}

function battleshipGameType(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  const explicit = String(metadata.game_type || '').trim();
  if (explicit) return explicit;
  const family = String(item?.item_family || '').trim();
  return family.startsWith('game_') ? family.slice(5) : '';
}

function battleshipLayer(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  const explicit = String(metadata.layer || '').trim();
  if (GROUP_TITLES[explicit]) return explicit;

  const slot = String(item?.equip_slot || '');
  if (slot.endsWith('_theme')) return 'theme';
  if (slot.endsWith('_elements')) return 'elements';
  if (slot.endsWith('_effect')) return 'effect';
  return '';
}

function battleshipVariant(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  const fallback = battleshipLayer(item) === 'effect'
    ? 'shot'
    : (battleshipLayer(item) === 'elements' ? 'classic' : 'sea');
  return String(metadata.variant || fallback).trim().replace(/[^a-z0-9-]/gi, '').toLowerCase() || fallback;
}

function battleshipDisplayName(item){
  const metadata = item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
  return String(metadata.display_name || item?.item_id || 'Предмет Морского боя').trim();
}

function isBattleshipItemEquipped(item){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const equipped = inventory?.equipped && typeof inventory.equipped === 'object' ? inventory.equipped : {};
  const slot = String(item?.equip_slot || '');
  return slot !== '' && String(equipped[slot] || '') === String(item?.item_id || '');
}

function battleshipPanelSignature(items){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const equipped = inventory?.equipped && typeof inventory.equipped === 'object' ? inventory.equipped : {};

  return items.map(item => [
    String(item.item_id || ''),
    battleshipLayer(item),
    battleshipVariant(item),
    battleshipDisplayName(item),
    String(equipped[String(item.equip_slot || '')] || ''),
  ].join(':')).join('|');
}

function battleshipPreview(item){
  const layer = battleshipLayer(item);
  const variant = battleshipVariant(item);
  const name = battleshipDisplayName(item);
  return `<div class="store-v2-game-preview" data-game-type="battleship" data-cosmetic-layer="${layer}" data-cosmetic-variant="${variant}" role="img" aria-label="${escapeHtml(name)}">${battleshipPreviewMarkup(layer, variant)}</div>`;
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#039;');
}
