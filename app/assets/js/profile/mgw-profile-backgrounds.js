import { api } from '../api/client.js?v=34';
import { state } from '../state.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=27';
import { renderBalances } from '../ui.js?v=89';

const BACKGROUND_SLOT = 'profile_background';
const BACKGROUND_ITEM_IDS = Object.freeze([
  'profile-background-01',
  'profile-background-02',
  'profile-background-03',
  'profile-background-04',
]);

let initialized = false;
let observer = null;
let scheduled = false;
let refreshPromise = null;
let initialSnapshotAttempted = false;
let backgroundBusy = false;

export function initMgwProfileBackgrounds(){
  if (initialized) return;
  initialized = true;
  const start = () => {
    observer?.disconnect();
    observer = new MutationObserver(scheduleDecorate);
    observer.observe(document.body, { childList:true, subtree:true });
    document.addEventListener('mgw:cosmetic-inventory-changed', scheduleDecorate);
    scheduleDecorate();

    const hydrateAfterReady = () => {
      scheduleDecorate();
      void ensureBackgroundSnapshot();
    };
    if (window.__MGW_APP_BOOTSTRAP_V2__?.ready === true) window.setTimeout(hydrateAfterReady, 0);
    else document.addEventListener('mgw:app-ready', hydrateAfterReady, { once:true });
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
  const catalog = backgroundCatalog();
  decorateProfileSurface();
  if (!catalog.length) return;
  renderStoreBackgroundSection(catalog);
  renderProfileBackgroundCollection(catalog);
}

function ensureBackgroundSnapshot(){
  if (backgroundCatalog().length || initialSnapshotAttempted) return Promise.resolve(state.profileInventory);
  initialSnapshotAttempted = true;
  return refreshBackgroundSnapshot();
}

function refreshBackgroundSnapshot(){
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

function backgroundCatalog(){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const catalog = Array.isArray(inventory?.catalog) ? inventory.catalog : [];
  return catalog
    .filter(item => item
      && item.item_type === 'profile'
      && item.item_family === 'background'
      && item.equip_slot === BACKGROUND_SLOT
      && String(item.catalog_status || '') === 'active')
    .map(item => ({ ...item, item_id:String(item.item_id || '') }))
    .filter(item => BACKGROUND_ITEM_IDS.includes(item.item_id))
    .sort((left, right) => BACKGROUND_ITEM_IDS.indexOf(left.item_id) - BACKGROUND_ITEM_IDS.indexOf(right.item_id));
}

function currentBackgroundItemId(){
  const equipped = state.profileInventory && typeof state.profileInventory === 'object' && state.profileInventory.equipped && typeof state.profileInventory.equipped === 'object'
    ? state.profileInventory.equipped
    : {};
  const itemId = String(equipped[BACKGROUND_SLOT] || '').trim();
  return BACKGROUND_ITEM_IDS.includes(itemId) ? itemId : '';
}

function backgroundMeta(item){ return item?.metadata && typeof item.metadata === 'object' ? item.metadata : {}; }
function backgroundName(item){ return String(backgroundMeta(item).display_name || item?.item_id || 'Фон'); }
function backgroundPrice(item){ return Math.max(0, Number(backgroundMeta(item).price_coins || 0)); }
function backgroundOfferId(item){ return String(backgroundMeta(item).offer_id || String(item?.item_id || '').replace(/^profile-/, '')); }
function backgroundTierLabel(item){
  const tier = String(backgroundMeta(item).tier || 'normal');
  return ({ normal:'Обычный', rare:'Редкий', epic:'Эпический', legendary:'Легендарный' })[tier] || 'Фон';
}

function decorateProfileSurface(){
  const screen = document.getElementById('screen-profile');
  if (!(screen instanceof HTMLElement)) return;
  const itemId = currentBackgroundItemId();
  if (itemId) screen.dataset.profileBackgroundItemId = itemId;
  else delete screen.dataset.profileBackgroundItemId;
}

function backgroundPreviewMarkup(itemId, surfaceClass, selected = false){
  return `<span class="${surfaceClass}" data-profile-background-item-id="${escapeAttr(itemId)}" aria-hidden="true">${selected ? '<i class="store-v2-selected-check" aria-hidden="true">✓</i>' : ''}</span>`;
}

function renderStoreBackgroundSection(catalog){
  const panel = document.querySelector('.store-v2-content[data-store-v2-panel="profile"]');
  if (!(panel instanceof HTMLElement)) return;
  const active = currentBackgroundItemId();
  const signature = catalog.map(item => `${item.item_id}:${item.owned === true ? 1 : 0}`).join('|') + `|${active}`;
  let section = panel.querySelector('[data-profile-background-store-section]');
  const frameSection = panel.querySelector('[data-profile-frame-store-section]');
  const badgeSection = panel.querySelector('[data-profile-badge-store-section]');
  const anchor = frameSection || badgeSection;
  if (section instanceof HTMLElement && anchor instanceof HTMLElement && section.previousElementSibling !== anchor) {
    anchor.insertAdjacentElement('afterend', section);
  }
  if (section instanceof HTMLElement && section.dataset.profileBackgroundSignature === signature) return;

  const markup = `
    <section class="store-v2-profile-background-section" data-profile-background-store-section data-profile-background-signature="${escapeAttr(signature)}">
      <div class="store-v2-title-row"><h2>Фоны профиля</h2></div>
      <div class="store-v2-product-grid" data-profile-background-grid>
        ${catalog.map(item => storeBackgroundCard(item, active)).join('')}
      </div>
    </section>
  `;
  if (section instanceof HTMLElement) section.outerHTML = markup;
  else if (anchor instanceof HTMLElement) anchor.insertAdjacentHTML('afterend', markup);
  else panel.insertAdjacentHTML('beforeend', markup);
  section = panel.querySelector('[data-profile-background-store-section]');
  bindStoreBackgroundActions(section);
}

function storeBackgroundCard(item, activeItemId){
  const itemId = String(item.item_id || '');
  const owned = item.owned === true;
  const active = owned && itemId === activeItemId;
  return `
    <article class="store-v2-product ${owned ? 'owned' : ''} ${active ? 'equipped' : ''}">
      ${backgroundPreviewMarkup(itemId, 'store-v2-profile-background-preview', active)}
      <strong class="store-v2-product-name">${escapeHtml(backgroundName(item))}</strong>
      <div class="store-v2-product-foot store-v2-profile-background-foot">
        ${owned
          ? (active
            ? `<button class="store-v2-equip active" data-profile-background-unequip type="button">Снять</button>`
            : `<button class="store-v2-equip" data-profile-background-equip="${escapeAttr(itemId)}" type="button">Выбрать</button>`)
          : `<b>${formatNumber(backgroundPrice(item))}</b><button class="store-v2-buy" data-profile-background-buy="${escapeAttr(itemId)}" type="button">Купить</button>`}
      </div>
    </article>
  `;
}

function bindStoreBackgroundActions(section){
  if (!(section instanceof HTMLElement)) return;
  section.querySelectorAll('[data-profile-background-buy]').forEach(button => {
    button.addEventListener('click', () => openBackgroundPurchase(String(button.dataset.profileBackgroundBuy || '')));
  });
  section.querySelectorAll('[data-profile-background-equip]').forEach(button => {
    button.addEventListener('click', () => void saveBackground(String(button.dataset.profileBackgroundEquip || ''), false));
  });
  section.querySelectorAll('[data-profile-background-unequip]').forEach(button => {
    button.addEventListener('click', () => void saveBackground(currentBackgroundItemId(), true));
  });
}

function renderProfileBackgroundCollection(catalog){
  const collection = document.querySelector('#screen-profile .profile-v2-collection-section');
  if (!(collection instanceof HTMLElement)) return;
  const owned = catalog.filter(item => item.owned === true);
  let section = collection.querySelector('[data-profile-background-collection]');
  if (!owned.length) {
    section?.remove();
    return;
  }

  const frameCollection = collection.querySelector('[data-profile-frame-collection]');
  const badgeCollection = collection.querySelector('[data-profile-badge-collection]');
  const anchor = frameCollection || badgeCollection;
  if (section instanceof HTMLElement && anchor instanceof HTMLElement && section.previousElementSibling !== anchor) {
    anchor.insertAdjacentElement('afterend', section);
  }
  const active = currentBackgroundItemId();
  const signature = owned.map(item => item.item_id).join('|') + `|${active}`;
  if (section instanceof HTMLElement && section.dataset.profileBackgroundSignature === signature) return;
  const markup = `
    <div class="profile-v2-background-collection" data-profile-background-collection data-profile-background-signature="${escapeAttr(signature)}" aria-label="Фоны профиля">
      <div class="profile-v2-collection-title">Фоны профиля</div>
      <div class="profile-v2-collection-grid" data-profile-background-grid>
        ${owned.map(item => profileBackgroundCard(item, active)).join('')}
      </div>
    </div>
  `;
  if (section instanceof HTMLElement) section.outerHTML = markup;
  else if (anchor instanceof HTMLElement) anchor.insertAdjacentHTML('afterend', markup);
  else {
    const gameCollection = collection.querySelector('.profile-v2-game-collection');
    if (gameCollection) gameCollection.insertAdjacentHTML('beforebegin', markup);
    else collection.insertAdjacentHTML('beforeend', markup);
  }
  section = collection.querySelector('[data-profile-background-collection]');
  section?.querySelectorAll('[data-profile-background-preview]').forEach(button => {
    button.addEventListener('click', () => openBackgroundPreview(String(button.dataset.profileBackgroundPreview || '')));
  });
}

function profileBackgroundCard(item, activeItemId){
  const itemId = String(item.item_id || '');
  const active = itemId === activeItemId;
  return `<button class="profile-v2-collection-card${active ? ' active' : ''}" type="button" data-profile-background-preview="${escapeAttr(itemId)}" aria-label="${escapeAttr(backgroundName(item))}" aria-pressed="${active ? 'true' : 'false'}">${backgroundPreviewMarkup(itemId, 'profile-v2-collection-avatar profile-v2-background-preview')}${active ? '<i class="profile-v2-selected-check" aria-hidden="true">✓</i>' : ''}</button>`;
}

function openBackgroundPurchase(itemId){
  const item = backgroundCatalog().find(candidate => candidate.item_id === itemId && candidate.owned !== true);
  if (!item || backgroundBusy) return;
  const price = backgroundPrice(item);
  const balance = Number(state.user?.balance || 0);
  const missing = Math.max(0, price - balance);
  const disabled = missing > 0 ? ' disabled' : '';
  const label = missing > 0 ? 'Не хватает ' + formatNumber(missing) : 'Купить за ' + formatNumber(price);
  openSheet(
    '<div class="sheet-head"><div><h2>Подтвердить покупку</h2></div><button class="close" data-close-sheet type="button">×</button></div>' +
    '<div class="store-v2-confirm">' +
      '<div class="profile-v2-background-preview-wrap">' + backgroundPreviewMarkup(itemId, 'profile-v2-background-preview') + '</div>' +
      '<div class="store-v2-confirm-copy"><strong>' + escapeHtml(backgroundName(item)) + '</strong></div>' +
      '<div class="store-v2-confirm-price"><span>К оплате</span><strong>' + formatNumber(price) + ' коинов</strong></div>' +
      '<div class="store-v2-confirm-balance"><span>Останется</span><b>' + formatNumber(Math.max(0, balance - price)) + '</b></div>' +
      '<button class="btn primary full" id="mgwProfileBackgroundConfirmBuy" type="button"' + disabled + '>' + escapeHtml(label) + '</button>' +
    '</div>'
  );
  document.getElementById('mgwProfileBackgroundConfirmBuy')?.addEventListener('click', event => {
    void purchaseBackground(item, event.currentTarget);
  });
}

async function purchaseBackground(item, button){
  if (backgroundBusy || !(button instanceof HTMLButtonElement) || button.disabled) return;
  backgroundBusy = true;
  button.disabled = true;
  try {
    const result = await api.cosmeticStorePurchase(backgroundOfferId(item), purchaseToken());
    const nextBalance = Number(result?.store?.balance);
    if (Number.isFinite(nextBalance) && state.user && typeof state.user === 'object') {
      state.user = { ...state.user, balance:nextBalance };
      renderBalances(state.user);
    }
    closeSheet();
    await refreshBackgroundSnapshot();
    toast('Фон добавлен в коллекцию.');
  } catch (error) {
    toast(error?.message || 'Не удалось купить фон.');
  } finally {
    backgroundBusy = false;
  }
}

function openBackgroundPreview(itemId){
  const item = backgroundCatalog().find(candidate => candidate.item_id === itemId && candidate.owned === true);
  if (!item) return;
  const active = itemId === currentBackgroundItemId();
  openSheet(`
    <div class="sheet-head"><div><h2>${escapeHtml(backgroundName(item))}</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="profile-v2-background-preview-wrap">${backgroundPreviewMarkup(itemId, 'profile-v2-background-preview')}</div>
    <div class="profile-v2-background-preview-meta"><strong>Фон профиля</strong><small>${escapeHtml(backgroundTierLabel(item))}</small></div>
    <button class="btn ${active ? 'ghost' : 'primary'} full" id="mgwProfileBackgroundEquip" type="button">${active ? 'Снять' : 'Выбрать'}</button>
  `);
  document.getElementById('mgwProfileBackgroundEquip')?.addEventListener('click', () => void saveBackground(itemId, active));
}

async function saveBackground(itemId, remove){
  if (backgroundBusy) return;
  const item = backgroundCatalog().find(candidate => candidate.item_id === itemId && candidate.owned === true);
  const active = itemId === currentBackgroundItemId();
  if (!item || (remove && !active) || (!remove && active)) return;

  const previousInventory = cloneObject(state.profileInventory);
  backgroundBusy = true;
  applyOptimisticBackground(itemId, !remove);
  closeSheet();
  scheduleDecorate();
  try {
    if (remove) await api.cosmeticStoreUnequip(BACKGROUND_SLOT);
    else await api.cosmeticStoreEquip(itemId);
    await refreshBackgroundSnapshot();
    toast(remove ? 'Фон снят.' : 'Фон выбран.');
  } catch (error) {
    state.profileInventory = previousInventory;
    scheduleDecorate();
    toast(error?.message || (remove ? 'Не удалось снять фон.' : 'Не удалось выбрать фон.'));
  } finally {
    backgroundBusy = false;
  }
}

function applyOptimisticBackground(itemId, equipped){
  const inventory = cloneObject(state.profileInventory) || { catalog:[], owned:[], equipped:{} };
  if (!inventory.equipped || typeof inventory.equipped !== 'object') inventory.equipped = {};
  if (equipped) inventory.equipped[BACKGROUND_SLOT] = itemId;
  else delete inventory.equipped[BACKGROUND_SLOT];
  if (Array.isArray(inventory.catalog)) {
    inventory.catalog = inventory.catalog.map(item => {
      if (!item || String(item.equip_slot || '') !== BACKGROUND_SLOT) return item;
      return { ...item, equipped:equipped && String(item.item_id || '') === itemId };
    });
  }
  state.profileInventory = inventory;
  document.dispatchEvent(new CustomEvent('mgw:cosmetic-inventory-changed', { detail:{ slot:BACKGROUND_SLOT } }));
}

function purchaseToken(){
  if (globalThis.crypto?.randomUUID) return `store:${globalThis.crypto.randomUUID()}`;
  return `store:${Date.now().toString(36)}:${Math.random().toString(36).slice(2,14)}`;
}

function cloneObject(value){ return value && typeof value === 'object' ? JSON.parse(JSON.stringify(value)) : value; }
function formatNumber(value){ return Number(value || 0).toLocaleString('ru-RU'); }
function escapeHtml(value){ return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }
function escapeAttr(value){ return escapeHtml(value); }
