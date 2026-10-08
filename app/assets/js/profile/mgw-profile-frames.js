import { api } from '../api/client.js?v=34';
import { state } from '../state.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=27';
import { renderBalances } from '../ui.js?v=89';
import { t, getI18n, formatNumber as formatLocalizedNumber } from '@mgw/i18n';

const frameText = (key, params = {}) => t(`profile.frames.${key}`, params);

const FRAME_SLOT = 'profile_frame';
const FRAME_PREVIEW_AVATAR_ITEM_ID = 'starter-default-01';
const FRAME_ITEM_IDS = Object.freeze([
  'profile-frame-01',
  'profile-frame-02',
  'profile-frame-03',
  'profile-frame-animated',
]);
const FRAME_NAME_KEYS = Object.freeze({
  'profile-frame-01':'names.sky',
  'profile-frame-02':'names.gold',
  'profile-frame-03':'names.aurora',
  'profile-frame-animated':'names.spectrum',
});

let initialized = false;
let observer = null;
let scheduled = false;
let refreshPromise = null;
let initialSnapshotAttempted = false;
let frameBusy = false;

export function initMgwProfileFrames(){
  if (initialized) return;
  initialized = true;
  const start = () => {
    observer?.disconnect();
    observer = new MutationObserver(scheduleDecorate);
    observer.observe(document.body, { childList:true, subtree:true });
    document.addEventListener('mgw:locale-changed', scheduleDecorate);
    document.addEventListener('mgw:cosmetic-inventory-changed', scheduleDecorate);
    scheduleDecorate();
    void ensureFrameSnapshot();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once:true });
  else start();
}

function scheduleDecorate(){
  if (scheduled) return;
  scheduled = true;
  queueMicrotask(() => {
    scheduled = false;
    decorateAll();
  });
}

function decorateAll(){
  const catalog = frameCatalog();
  if (!catalog.length) return;
  decorateChrome();
  decoratePlayersRow();
  renderStoreFrameSection(catalog);
  renderProfileFrameCollection(catalog);
}

function ensureFrameSnapshot(){
  if (frameCatalog().length || initialSnapshotAttempted) return Promise.resolve(state.profileInventory);
  initialSnapshotAttempted = true;
  return refreshFrameSnapshot();
}

function refreshFrameSnapshot(){
  if (refreshPromise) return refreshPromise;
  initialSnapshotAttempted = true;
  refreshPromise = api.profileV2()
    .then(result => {
      if (result?.inventory && typeof result.inventory === 'object') state.profileInventory = result.inventory;
      if (result?.profile && typeof result.profile === 'object') state.mgwProfile = result.profile;
      if (result?.user && state.user && typeof state.user === 'object') {
        const balance = Number(result.user.balance ?? state.user.balance ?? 0);
        state.user = { ...state.user, balance };
        renderBalances(state.user);
      }
      scheduleDecorate();
      return state.profileInventory;
    })
    .catch(() => state.profileInventory)
    .finally(() => { refreshPromise = null; });
  return refreshPromise;
}

function frameCatalog(){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const catalog = Array.isArray(inventory?.catalog) ? inventory.catalog : [];
  return catalog
    .filter(item => item
      && item.item_type === 'profile'
      && item.item_family === 'frame'
      && item.equip_slot === FRAME_SLOT
      && String(item.catalog_status || '') === 'active')
    .map(item => ({ ...item, item_id:String(item.item_id || '') }))
    .filter(item => FRAME_ITEM_IDS.includes(item.item_id))
    .sort((left, right) => FRAME_ITEM_IDS.indexOf(left.item_id) - FRAME_ITEM_IDS.indexOf(right.item_id));
}

function currentFrameItemId(){
  const equipped = state.profileInventory && typeof state.profileInventory === 'object' && state.profileInventory.equipped && typeof state.profileInventory.equipped === 'object'
    ? state.profileInventory.equipped
    : {};
  const itemId = String(equipped[FRAME_SLOT] || '').trim();
  return FRAME_ITEM_IDS.includes(itemId) ? itemId : '';
}

function frameMeta(item){ return item?.metadata && typeof item.metadata === 'object' ? item.metadata : {}; }
function frameName(item){
  const itemId = String(item?.item_id || '');
  const localeKey = FRAME_NAME_KEYS[itemId];
  return String((localeKey ? frameText(localeKey) : '') || frameMeta(item).display_name || itemId || frameText('fallback_name'));
}
function framePrice(item){ return Math.max(0, Number(frameMeta(item).price_coins || 0)); }
function frameOfferId(item){ return String(frameMeta(item).offer_id || String(item?.item_id || '').replace(/^profile-/, '')); }
function frameTierLabel(item){
  const tier = String(frameMeta(item).tier || 'normal');
  const key = ({ normal:'tiers.normal', rare:'tiers.rare', epic:'tiers.epic', animated:'tiers.animated' })[tier] || 'tiers.fallback';
  return frameText(key);
}

function decorateChrome(){
  const itemId = currentFrameItemId();
  applyAvatarFrame(document.getElementById('topAvatar'), itemId);
  applyAvatarFrame(document.getElementById('profileAvatar'), itemId);
  applyAvatarFrame(document.getElementById('profileV2Avatar'), itemId);
  applyAvatarFrame(document.getElementById('searchMeAvatar'), itemId);
}

function decoratePlayersRow(){
  const row = document.getElementById('playersRow');
  const players = Array.isArray(state.activeGame?.players) ? state.activeGame.players : [];
  if (!row || !players.length) return;
  [...row.children].forEach((card, index) => {
    if (!(card instanceof HTMLElement)) return;
    const avatar = card.querySelector(':scope > .game-player-avatar');
    if (!(avatar instanceof HTMLElement)) return;
    const itemId = String(players[index]?.frame_item_id || '').trim().toLowerCase();
    applyAvatarFrame(avatar, FRAME_ITEM_IDS.includes(itemId) ? itemId : '');
  });
}

function applyAvatarFrame(element, itemId){
  if (!(element instanceof HTMLElement)) return;
  const normalized = FRAME_ITEM_IDS.includes(String(itemId || '').trim().toLowerCase())
    ? String(itemId || '').trim().toLowerCase()
    : '';
  if (normalized) {
    if (element.dataset.profileFrameAvatarItemId !== normalized) element.dataset.profileFrameAvatarItemId = normalized;
  } else if (element.dataset.profileFrameAvatarItemId) {
    delete element.dataset.profileFrameAvatarItemId;
  }
}

function framePreviewMarkup(itemId, surfaceClass, selected = false){
  return `<span class="${surfaceClass}" data-avatar-item-id="${FRAME_PREVIEW_AVATAR_ITEM_ID}" data-profile-frame-avatar-item-id="${escapeAttr(itemId)}" aria-hidden="true">${selected ? '<i class="store-v2-selected-check" aria-hidden="true">✓</i>' : ''}</span>`;
}

function renderStoreFrameSection(catalog){
  const panel = document.querySelector('.store-v2-content[data-store-v2-panel="profile"]');
  if (!(panel instanceof HTMLElement)) return;
  const active = currentFrameItemId();
  const signature = catalog.map(item => `${item.item_id}:${item.owned === true ? 1 : 0}`).join('|') + `|${active}` + `|${getI18n().locale}`;
  let section = panel.querySelector('[data-profile-frame-store-section]');
  const badgeSection = panel.querySelector('[data-profile-badge-store-section]');
  if (section instanceof HTMLElement && badgeSection instanceof HTMLElement && section.previousElementSibling !== badgeSection) {
    badgeSection.insertAdjacentElement('afterend', section);
  }
  if (section instanceof HTMLElement && section.dataset.profileFrameSignature === signature) return;

  const markup = `
    <section class="store-v2-profile-frame-section" data-profile-frame-store-section data-profile-frame-signature="${escapeAttr(signature)}">
      <div class="store-v2-title-row"><h2>${escapeHtml(frameText('title'))}</h2></div>
      <div class="store-v2-product-grid" data-profile-frame-grid>
        ${catalog.map(item => storeFrameCard(item, active)).join('')}
      </div>
    </section>
  `;
  if (section instanceof HTMLElement) section.outerHTML = markup;
  else if (badgeSection instanceof HTMLElement) badgeSection.insertAdjacentHTML('afterend', markup);
  else panel.insertAdjacentHTML('beforeend', markup);
  section = panel.querySelector('[data-profile-frame-store-section]');
  bindStoreFrameActions(section);
}

function storeFrameCard(item, activeItemId){
  const itemId = String(item.item_id || '');
  const owned = item.owned === true;
  const active = owned && itemId === activeItemId;
  return `
    <article class="store-v2-product ${owned ? 'owned' : ''} ${active ? 'equipped' : ''}">
      ${framePreviewMarkup(itemId, 'store-v2-avatar-preview', active)}
      <strong class="store-v2-product-name">${escapeHtml(frameName(item))}</strong>
      <div class="store-v2-product-foot store-v2-profile-frame-foot">
        ${owned
          ? (active
            ? `<button class="store-v2-equip active" data-profile-frame-unequip type="button">${escapeHtml(frameText('actions.remove'))}</button>`
            : `<button class="store-v2-equip" data-profile-frame-equip="${escapeAttr(itemId)}" type="button">${escapeHtml(frameText('actions.select'))}</button>`)
          : `<b>${formatNumber(framePrice(item))}</b><button class="store-v2-buy" data-profile-frame-buy="${escapeAttr(itemId)}" type="button">${escapeHtml(frameText('actions.buy'))}</button>`}
      </div>
    </article>
  `;
}

function bindStoreFrameActions(section){
  if (!(section instanceof HTMLElement)) return;
  section.querySelectorAll('[data-profile-frame-buy]').forEach(button => {
    button.addEventListener('click', () => openFramePurchase(String(button.dataset.profileFrameBuy || '')));
  });
  section.querySelectorAll('[data-profile-frame-equip]').forEach(button => {
    button.addEventListener('click', () => void saveFrame(String(button.dataset.profileFrameEquip || ''), false));
  });
  section.querySelectorAll('[data-profile-frame-unequip]').forEach(button => {
    button.addEventListener('click', () => void saveFrame(currentFrameItemId(), true));
  });
}

function renderProfileFrameCollection(catalog){
  const collection = document.querySelector('#screen-profile .profile-v2-collection-section');
  if (!(collection instanceof HTMLElement)) return;
  const owned = catalog.filter(item => item.owned === true);
  let section = collection.querySelector('[data-profile-frame-collection]');
  if (!owned.length) {
    section?.remove();
    return;
  }

  const badgeCollection = collection.querySelector('[data-profile-badge-collection]');
  if (section instanceof HTMLElement && badgeCollection instanceof HTMLElement && section.previousElementSibling !== badgeCollection) {
    badgeCollection.insertAdjacentElement('afterend', section);
  }
  const active = currentFrameItemId();
  const signature = owned.map(item => item.item_id).join('|') + `|${active}` + `|${getI18n().locale}`;
  if (section instanceof HTMLElement && section.dataset.profileFrameSignature === signature) return;
  const markup = `
    <div class="profile-v2-frame-collection" data-profile-frame-collection data-profile-frame-signature="${escapeAttr(signature)}" aria-label="${escapeAttr(frameText('title'))}">
      <div class="profile-v2-collection-title">${escapeHtml(frameText('title'))}</div>
      <div class="profile-v2-collection-grid" data-profile-frame-grid>
        ${owned.map(item => profileFrameCard(item, active)).join('')}
      </div>
    </div>
  `;
  if (section instanceof HTMLElement) {
    section.outerHTML = markup;
  } else if (badgeCollection instanceof HTMLElement) {
    badgeCollection.insertAdjacentHTML('afterend', markup);
  } else {
    const gameCollection = collection.querySelector('.profile-v2-game-collection');
    if (gameCollection) gameCollection.insertAdjacentHTML('beforebegin', markup);
    else collection.insertAdjacentHTML('beforeend', markup);
  }
  section = collection.querySelector('[data-profile-frame-collection]');
  section?.querySelectorAll('[data-profile-frame-preview]').forEach(button => {
    button.addEventListener('click', () => openFramePreview(String(button.dataset.profileFramePreview || '')));
  });
}

function profileFrameCard(item, activeItemId){
  const itemId = String(item.item_id || '');
  const active = itemId === activeItemId;
  return `<button class="profile-v2-collection-card${active ? ' active' : ''}" type="button" data-profile-frame-preview="${escapeAttr(itemId)}" aria-label="${escapeAttr(frameName(item))}" aria-pressed="${active ? 'true' : 'false'}">${framePreviewMarkup(itemId, 'profile-v2-collection-avatar')}${active ? '<i class="profile-v2-selected-check" aria-hidden="true">✓</i>' : ''}</button>`;
}

function openFramePurchase(itemId){
  const item = frameCatalog().find(candidate => candidate.item_id === itemId && candidate.owned !== true);
  if (!item || frameBusy) return;
  const price = framePrice(item);
  const balance = Number(state.user?.balance || 0);
  const missing = Math.max(0, price - balance);
  const disabled = missing > 0 ? ' disabled' : '';
  const label = missing > 0
    ? frameText('purchase.missing', { count:formatNumber(missing) })
    : frameText('purchase.buy_for', { count:formatNumber(price) });
  openSheet(
    '<div class="sheet-head"><div><h2>' + escapeHtml(frameText('purchase.title')) + '</h2></div><button class="close" data-close-sheet type="button">×</button></div>' +
    '<div class="store-v2-confirm">' +
      '<div class="profile-v2-frame-preview-wrap">' + framePreviewMarkup(itemId, 'profile-v2-avatar-preview') + '</div>' +
      '<div class="store-v2-confirm-copy"><strong>' + escapeHtml(frameName(item)) + '</strong></div>' +
      '<div class="store-v2-confirm-price"><span>' + escapeHtml(frameText('purchase.to_pay')) + '</span><strong>' + escapeHtml(frameText('purchase.coins_value', { count:formatNumber(price) })) + '</strong></div>' +
      '<div class="store-v2-confirm-balance"><span>' + escapeHtml(frameText('purchase.remaining')) + '</span><b>' + formatNumber(Math.max(0, balance - price)) + '</b></div>' +
      '<button class="btn primary full" id="mgwProfileFrameConfirmBuy" type="button"' + disabled + '>' + escapeHtml(label) + '</button>' +
    '</div>'
  );
  document.getElementById('mgwProfileFrameConfirmBuy')?.addEventListener('click', event => {
    void purchaseFrame(item, event.currentTarget);
  });
}

async function purchaseFrame(item, button){
  if (frameBusy || !(button instanceof HTMLButtonElement) || button.disabled) return;
  frameBusy = true;
  button.disabled = true;
  try {
    const result = await api.cosmeticStorePurchase(frameOfferId(item), purchaseToken());
    const nextBalance = Number(result?.store?.balance);
    if (Number.isFinite(nextBalance) && state.user && typeof state.user === 'object') {
      state.user = { ...state.user, balance:nextBalance };
      renderBalances(state.user);
    }
    closeSheet();
    await refreshFrameSnapshot();
    toast(frameText('toast.purchased'));
  } catch (error) {
    toast(error?.message || frameText('errors.purchase'));
  } finally {
    frameBusy = false;
  }
}

function openFramePreview(itemId){
  const item = frameCatalog().find(candidate => candidate.item_id === itemId && candidate.owned === true);
  if (!item) return;
  const active = itemId === currentFrameItemId();
  openSheet(`
    <div class="sheet-head"><div><h2>${escapeHtml(frameName(item))}</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="profile-v2-frame-preview-wrap">${framePreviewMarkup(itemId, 'profile-v2-avatar-preview')}</div>
    <div class="profile-v2-frame-preview-meta"><strong>${escapeHtml(frameText('type'))}</strong><small>${escapeHtml(frameTierLabel(item))}</small></div>
    <button class="btn ${active ? 'ghost' : 'primary'} full" id="mgwProfileFrameEquip" type="button">${escapeHtml(frameText(active ? 'actions.remove' : 'actions.select'))}</button>
  `);
  document.getElementById('mgwProfileFrameEquip')?.addEventListener('click', () => void saveFrame(itemId, active));
}

async function saveFrame(itemId, remove){
  if (frameBusy) return;
  const item = frameCatalog().find(candidate => candidate.item_id === itemId && candidate.owned === true);
  const active = itemId === currentFrameItemId();
  if (!item || (remove && !active) || (!remove && active)) return;

  const previousInventory = cloneObject(state.profileInventory);
  frameBusy = true;
  applyOptimisticFrame(itemId, !remove);
  closeSheet();
  scheduleDecorate();
  try {
    if (remove) await api.cosmeticStoreUnequip(FRAME_SLOT);
    else await api.cosmeticStoreEquip(itemId);
    await refreshFrameSnapshot();
    toast(frameText(remove ? 'toast.removed' : 'toast.selected'));
  } catch (error) {
    state.profileInventory = previousInventory;
    scheduleDecorate();
    toast(error?.message || frameText(remove ? 'errors.remove' : 'errors.select'));
  } finally {
    frameBusy = false;
  }
}

function applyOptimisticFrame(itemId, equipped){
  const inventory = cloneObject(state.profileInventory) || { catalog:[], owned:[], equipped:{} };
  if (!inventory.equipped || typeof inventory.equipped !== 'object') inventory.equipped = {};
  if (equipped) inventory.equipped[FRAME_SLOT] = itemId;
  else delete inventory.equipped[FRAME_SLOT];
  if (Array.isArray(inventory.catalog)) {
    inventory.catalog = inventory.catalog.map(item => {
      if (!item || String(item.equip_slot || '') !== FRAME_SLOT) return item;
      return { ...item, equipped:equipped && String(item.item_id || '') === itemId };
    });
  }
  state.profileInventory = inventory;
  document.dispatchEvent(new CustomEvent('mgw:cosmetic-inventory-changed', { detail:{ slot:FRAME_SLOT } }));
}

function purchaseToken(){
  if (globalThis.crypto?.randomUUID) return `store:${globalThis.crypto.randomUUID()}`;
  return `store:${Date.now().toString(36)}:${Math.random().toString(36).slice(2,14)}`;
}

function cloneObject(value){ return value && typeof value === 'object' ? JSON.parse(JSON.stringify(value)) : value; }
function formatNumber(value){ return formatLocalizedNumber(Number(value || 0)); }
function escapeHtml(value){ return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }
function escapeAttr(value){ return escapeHtml(value); }
