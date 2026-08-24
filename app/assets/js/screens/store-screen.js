import { api } from '../api/client.js?v=34';
import { state } from '../state.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=27';
import { renderBalances } from '../ui.js?v=89';
import { haptic } from '../telegram/telegram-app.js?v=27';

const STORE_TABS = Object.freeze([
  { id:'coins', label:'Коины' },
  { id:'profile', label:'Профиль' },
  { id:'games', label:'Игры' },
  { id:'bundles', label:'Наборы' },
]);

let storeState = null;
let storeSurface = 'tab';
let activeTab = 'profile';
let storeLoadPromise = null;
let purchaseBusy = false;

export function initStoreScreen(){
  document.addEventListener('click', event => {
    const trigger = event.target.closest('#storeOpen');
    if (!trigger) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    openStoreTab();
  }, true);

  const warm = () => void warmStore().catch(() => {});
  if (typeof globalThis.requestIdleCallback === 'function') {
    globalThis.requestIdleCallback(warm, { timeout:900 });
  } else {
    globalThis.setTimeout(warm, 250);
  }
}

export async function openStoreTab(){
  storeSurface = 'tab';
  haptic('light');
  if (storeState) {
    renderStore();
    void refreshStoreSilently();
    return;
  }
  renderStorePending();
  await loadStore();
}

export async function openStoreSheet(){
  storeSurface = 'sheet';
  haptic('light');
  if (storeState) {
    renderStore();
    void refreshStoreSilently();
    return;
  }
  renderStorePending();
  await loadStore();
}

function warmStore(){
  if (storeState) return Promise.resolve(storeState);
  return fetchStore();
}

function fetchStore(){
  if (storeLoadPromise) return storeLoadPromise;
  storeLoadPromise = api.cosmeticStoreStatus()
    .then(result => {
      if (!purchaseBusy) applyStoreResponse(result);
      return storeState;
    })
    .finally(() => {
      storeLoadPromise = null;
    });
  return storeLoadPromise;
}

async function loadStore(){
  try {
    await fetchStore();
    renderStore();
  } catch (error) {
    renderStoreError(error);
  }
}

async function refreshStoreSilently(){
  try {
    await fetchStore();
    updateVisibleBalance();
  } catch (_) {
    // Keep the already rendered Store snapshot if a background refresh fails.
  }
}

function applyStoreResponse(result){
  storeState = result?.store && typeof result.store === 'object' ? result.store : null;
  if (!storeState) throw new Error('Магазин вернул неполный ответ.');
  if (state.user && typeof state.user === 'object') {
    state.user = { ...state.user, balance:Number(storeState.balance || 0) };
    renderBalances(state.user);
  }
  if (!STORE_TABS.some(tab => tab.id === activeTab)) activeTab = 'profile';
}

function renderStorePending(){
  renderStoreSurface(`
    ${renderStoreHead()}
    <div class="store-v2-shell is-pending">
      ${renderBalanceHero()}
      ${renderTabs()}
      <div class="store-v2-content" data-store-v2-panel="${escapeAttr(activeTab)}">
        <div class="store-v2-skeleton-grid" aria-hidden="true">
          ${Array.from({ length:4 }, () => '<span></span>').join('')}
        </div>
      </div>
    </div>
  `);
  bindStoreEvents();
}

function renderStore(){
  if (!storeState) return;
  renderStoreSurface(`
    ${renderStoreHead()}
    <div class="store-v2-shell">
      ${renderBalanceHero()}
      ${renderTabs()}
      <div class="store-v2-content" data-store-v2-panel="${escapeAttr(activeTab)}">
        ${renderActiveTab()}
      </div>
    </div>
  `);
  bindStoreEvents();
}

function renderBalanceHero(){
  const balance = storeState?.balance ?? state.user?.balance ?? 0;
  return `
    <section class="store-v2-balance">
      <span>Баланс</span>
      <strong data-store-v2-balance>${formatNumber(balance)} <small>коинов</small></strong>
    </section>
  `;
}

function updateVisibleBalance(){
  const root = currentRoot();
  const target = root?.querySelector('[data-store-v2-balance]');
  if (!target) return;
  target.innerHTML = `${formatNumber(storeState?.balance ?? state.user?.balance ?? 0)} <small>коинов</small>`;
}

function storeTabs(){
  const serverTabs = Array.isArray(storeState?.tabs) ? storeState.tabs : [];
  const serverById = new Map(serverTabs.map(tab => [String(tab?.id || ''), tab]));
  return STORE_TABS.map(tab => ({ ...tab, ...(serverById.get(tab.id) || {}) }));
}

function renderTabs(){
  return `
    <div class="store-v2-tabs" role="tablist" aria-label="Разделы магазина">
      ${storeTabs().map(tab => `
        <button class="store-v2-tab ${activeTab === String(tab.id) ? 'active' : ''}" data-store-v2-tab="${escapeAttr(tab.id)}" type="button" role="tab" aria-selected="${activeTab === String(tab.id) ? 'true' : 'false'}">
          ${escapeHtml(tab.label || tab.id)}
        </button>
      `).join('')}
    </div>
  `;
}

function renderActiveTab(){
  switch (activeTab) {
    case 'coins': return renderCoinsTab();
    case 'profile': return renderProfileTab();
    case 'games': return renderDevPlaceholder();
    case 'bundles': return renderBundlesTab();
    default: return renderProfileTab();
  }
}

function renderCoinsTab(){
  const packages = Array.isArray(storeState?.coins?.packages) ? storeState.coins.packages : [];
  return `
    <div class="store-v2-coin-grid">
      ${packages.map(pkg => `
        <article class="store-v2-coin-card">
          <div class="store-v2-coin-mark" aria-hidden="true"><span>MG</span></div>
          <div class="store-v2-coin-copy">
            <strong>${formatNumber(pkg.coins)}</strong>
            <span>коинов</span>
            <b>${formatEuro(pkg.price_eur_cents)}</b>
          </div>
          <em>Скоро</em>
        </article>
      `).join('') || emptyState('Пакеты пока недоступны')}
    </div>
  `;
}

function renderProfileTab(){
  const avatars = Array.isArray(storeState?.profile?.avatars) ? storeState.profile.avatars : [];
  return `
    <div class="store-v2-title-row"><h2>Аватарки</h2></div>
    <div class="store-v2-product-grid">
      ${avatars.map(renderAvatarOffer).join('') || emptyState('Аватарки пока недоступны')}
    </div>
  `;
}

function renderAvatarOffer(offer){
  const owned = Boolean(offer?.already_owned);
  const number = Number(offer?.preview_number || 0);
  const itemId = Array.isArray(offer?.item_ids) ? String(offer.item_ids[0] || '') : '';
  const equippedItemId = String(state.selectedAvatarId || storeState?.inventory?.equipped?.profile_avatar || '');
  const equipped = owned && itemId !== '' && itemId === equippedItemId;
  return `
    <article class="store-v2-product ${owned ? 'owned' : ''} ${equipped ? 'equipped' : ''}">
      <div class="store-v2-avatar-preview" data-avatar-item-id="${escapeAttr(itemId)}" data-avatar-preview="${number}">
        <span>${String(number).padStart(2, '0')}</span>
        ${equipped ? '<i class="store-v2-selected-check" aria-label="Выбрана">✓</i>' : ''}
      </div>
      <strong class="store-v2-product-name">Аватарка ${number || ''}</strong>
      <div class="store-v2-product-foot">
        ${owned
          ? `<b>${equipped ? '' : 'Куплено'}</b>`
          : `<b>${formatNumber(offer?.price_coins || 0)}</b><button class="store-v2-buy" data-store-v2-buy="${escapeAttr(offer?.offer_id || '')}" type="button">Купить</button>`}
      </div>
    </article>
  `;
}

function renderBundlesTab(){
  const bundle = storeState?.bundles?.avatar_bundle;
  if (!bundle) return emptyState('Наборы пока недоступны');
  const missing = Number(bundle.missing_count || 0);
  const owned = Number(bundle.owned_count || 0);
  const allOwned = Boolean(bundle.already_owned);
  const regularMissingPrice = regularBundlePrice(bundle);
  const saving = Math.max(0, regularMissingPrice - Number(bundle.price_coins || 0));
  return `
    <article class="store-v2-bundle ${allOwned ? 'owned' : ''}">
      <div class="store-v2-bundle-visual" aria-hidden="true">
        ${[4,5,6,7,8].map(number => `<span>${String(number).padStart(2, '0')}</span>`).join('')}
      </div>
      <div class="store-v2-bundle-copy">
        <h2>Комплект аватарок</h2>
        ${allOwned
          ? '<p>Комплект уже собран.</p>'
          : (owned ? `<p>Осталось ${missing} из 5.</p>` : '')}
        ${!allOwned ? `<div class="store-v2-bundle-price"><strong>${formatNumber(bundle.price_coins || 0)} коинов</strong>${saving > 0 ? `<span>−${formatNumber(saving)}</span>` : ''}</div>` : ''}
      </div>
      <button class="btn primary full" data-store-v2-buy="${escapeAttr(bundle.offer_id || '')}" type="button" ${allOwned ? 'disabled' : ''}>
        ${allOwned ? 'Комплект собран' : 'Купить комплект'}
      </button>
    </article>
  `;
}

function regularBundlePrice(bundle){
  const missingIds = Array.isArray(bundle?.missing_item_ids) ? bundle.missing_item_ids.map(String) : [];
  const avatars = Array.isArray(storeState?.profile?.avatars) ? storeState.profile.avatars : [];
  return missingIds.reduce((total, itemId) => {
    const offer = avatars.find(candidate => Array.isArray(candidate?.item_ids) && candidate.item_ids.map(String).includes(itemId));
    return total + Number(offer?.price_coins || 0);
  }, 0);
}

function renderDevPlaceholder(){
  return `<div class="store-v2-dev-placeholder"><strong>ПОКА НЕ ГОТОВО</strong></div>`;
}

function emptyState(title){
  return `<div class="store-v2-empty"><strong>${escapeHtml(title)}</strong></div>`;
}

function bindStoreEvents(){
  const root = currentRoot();
  if (!root) return;
  root.querySelectorAll('[data-store-v2-tab]').forEach(button => {
    button.addEventListener('click', () => activateStoreTab(String(button.dataset.storeV2Tab || 'profile')));
  });
  bindPanelEvents(root);
}

function bindPanelEvents(root){
  root.querySelectorAll('[data-store-v2-buy]').forEach(button => {
    button.addEventListener('click', () => {
      const offer = findOffer(String(button.dataset.storeV2Buy || ''));
      if (!offer || offer.already_owned) return;
      haptic('light');
      openPurchaseConfirm(offer);
    });
  });
}

function activateStoreTab(nextTab){
  if (!STORE_TABS.some(tab => tab.id === nextTab) || nextTab === activeTab) return;
  activeTab = nextTab;
  haptic('light');
  const root = currentRoot();
  if (!root) return;
  root.querySelectorAll('[data-store-v2-tab]').forEach(button => {
    const active = String(button.dataset.storeV2Tab || '') === activeTab;
    button.classList.toggle('active', active);
    button.setAttribute('aria-selected', active ? 'true' : 'false');
  });
  const panel = root.querySelector('[data-store-v2-panel]');
  if (!panel) return;
  panel.dataset.storeV2Panel = activeTab;
  panel.innerHTML = storeState ? renderActiveTab() : '<div class="store-v2-skeleton-grid" aria-hidden="true"><span></span><span></span><span></span><span></span></div>';
  bindPanelEvents(panel);
}

function findOffer(offerId){
  const avatars = Array.isArray(storeState?.profile?.avatars) ? storeState.profile.avatars : [];
  const avatar = avatars.find(item => String(item.offer_id || '') === offerId);
  if (avatar) return avatar;
  const bundle = storeState?.bundles?.avatar_bundle;
  return String(bundle?.offer_id || '') === offerId ? bundle : null;
}

function purchasedItemIds(offer){
  const missing = Array.isArray(offer?.missing_item_ids) ? offer.missing_item_ids.map(String).filter(Boolean) : [];
  if (missing.length) return missing;
  return Array.isArray(offer?.item_ids) ? offer.item_ids.map(String).filter(Boolean) : [];
}

function applyOptimisticPurchase(offer){
  if (!storeState) return;
  const purchasedIds = purchasedItemIds(offer);
  const purchasedSet = new Set(purchasedIds);
  const next = cloneObject(storeState);
  const price = Math.max(0, Number(offer?.price_coins || 0));
  next.balance = Math.max(0, Number(next.balance || 0) - price);

  const avatars = Array.isArray(next?.profile?.avatars) ? next.profile.avatars : [];
  avatars.forEach(candidate => {
    const ids = Array.isArray(candidate?.item_ids) ? candidate.item_ids.map(String) : [];
    if (!ids.some(itemId => purchasedSet.has(itemId))) return;
    candidate.already_owned = true;
    candidate.purchasable = false;
    candidate.missing_item_ids = [];
    candidate.missing_count = 0;
    candidate.owned_count = ids.length;
  });

  const bundle = next?.bundles?.avatar_bundle;
  if (bundle && typeof bundle === 'object') {
    const members = Array.isArray(bundle.item_ids) ? bundle.item_ids.map(String) : [];
    const previousMissing = Array.isArray(bundle.missing_item_ids) ? bundle.missing_item_ids.map(String) : members;
    const remaining = previousMissing.filter(itemId => !purchasedSet.has(itemId));
    bundle.missing_item_ids = remaining;
    bundle.missing_count = remaining.length;
    bundle.owned_count = Math.max(0, members.length - remaining.length);
    bundle.already_owned = remaining.length === 0;
    bundle.purchasable = remaining.length > 0;
    if (remaining.length > 0 && remaining.length < members.length) {
      const unit = Number(bundle.partial_unit_price_coins || 0);
      if (unit > 0) bundle.price_coins = unit * remaining.length;
    }
  }

  const inventoryItems = Array.isArray(next?.inventory?.items) ? next.inventory.items : [];
  purchasedIds.forEach(itemId => {
    if (inventoryItems.some(item => String(item?.item_id || '') === itemId)) return;
    inventoryItems.push({ item_id:itemId, item_family:'avatar', store_product:true, equipped:false });
  });

  storeState = next;
  if (state.user && typeof state.user === 'object') {
    state.user = { ...state.user, balance:Number(next.balance || 0) };
    renderBalances(state.user);
  }
  if (state.profileInventory && typeof state.profileInventory === 'object' && Array.isArray(state.profileInventory.catalog)) {
    const profileInventory = cloneObject(state.profileInventory);
    profileInventory.catalog = profileInventory.catalog.map(item => (
      purchasedSet.has(String(item?.item_id || '')) ? { ...item, owned:true } : item
    ));
    state.profileInventory = profileInventory;
  }
}

function openPurchaseConfirm(offer){
  const token = purchaseToken();
  const isBundle = String(offer.offer_type || '') === 'bundle';
  const number = Number(offer.preview_number || 0);
  const itemId = Array.isArray(offer.item_ids) ? String(offer.item_ids[0] || '') : '';
  const balance = Number(storeState?.balance || 0);
  const price = Number(offer.price_coins || 0);
  const missing = Math.max(0, price - balance);
  const title = isBundle ? 'Комплект аватарок' : `Аватарка ${number}`;
  const visual = isBundle
    ? `<div class="store-v2-confirm-bundle">${[4,5,6,7,8].map(value => `<span>${String(value).padStart(2,'0')}</span>`).join('')}</div>`
    : `<div class="store-v2-confirm-avatar store-v2-avatar-preview" data-avatar-item-id="${escapeAttr(itemId)}" data-avatar-preview="${number}" role="img" aria-label="${escapeAttr(`Аватарка ${number}`)}"><span>${String(number).padStart(2,'0')}</span></div>`;

  openSheet(`
    <div class="sheet-head"><div><h2>Подтвердить покупку</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="store-v2-confirm">
      ${visual}
      <div class="store-v2-confirm-copy"><strong>${escapeHtml(title)}</strong></div>
      <div class="store-v2-confirm-price"><span>К оплате</span><strong>${formatNumber(price)} коинов</strong></div>
      <div class="store-v2-confirm-balance"><span>Останется</span><b>${formatNumber(Math.max(0, balance - price))}</b></div>
      <button class="btn primary full" id="storeV2ConfirmBuy" type="button" ${missing > 0 ? 'disabled' : ''}>${missing > 0 ? `Не хватает ${formatNumber(missing)}` : `Купить за ${formatNumber(price)}`}</button>
    </div>
  `);

  document.getElementById('storeV2ConfirmBuy')?.addEventListener('click', event => {
    void purchaseOffer(offer, token, event.currentTarget);
  });
}

async function purchaseOffer(offer, token, button){
  if (purchaseBusy || !button || button.disabled) return;
  purchaseBusy = true;
  button.disabled = true;

  const previousStoreState = cloneObject(storeState);
  const previousUser = cloneObject(state.user);
  const previousProfileInventory = cloneObject(state.profileInventory);
  applyOptimisticPurchase(offer);
  closeSheet();
  renderStore();

  try {
    const result = await api.cosmeticStorePurchase(String(offer.offer_id || ''), token);
    applyStoreResponse(result);
    renderStore();
    haptic('success');
    toast(String(offer.offer_type || '') === 'bundle' ? 'Комплект добавлен в коллекцию.' : 'Аватарка добавлена в коллекцию.');
  } catch (error) {
    storeState = previousStoreState;
    state.user = previousUser;
    state.profileInventory = previousProfileInventory;
    if (state.user) renderBalances(state.user);
    renderStore();
    haptic('error');
    toast(error?.message || 'Не удалось выполнить покупку.');
  } finally {
    purchaseBusy = false;
  }
}

function renderStoreHead(){
  if (storeSurface === 'tab') {
    return '<div class="page-head app-shell-page-head store-tab-head"><div><h1 class="page-title">Магазин</h1></div></div>';
  }
  return '<div class="sheet-head"><div><h2>Магазин</h2></div><button class="close" data-close-sheet type="button">×</button></div>';
}

function renderStoreSurface(markup){
  if (storeSurface === 'tab') {
    const host = document.getElementById('storeTabSurface');
    if (host) host.innerHTML = markup;
    return;
  }
  openSheet(markup);
}

function renderStoreError(error){
  renderStoreSurface(`
    ${renderStoreHead()}
    <div class="store-v2-empty error"><strong>Магазин временно недоступен</strong><span>${escapeHtml(error?.message || 'Попробуйте ещё раз.')}</span></div>
    <button class="btn ghost full" id="storeV2Retry" type="button">Повторить</button>
  `);
  currentRoot()?.querySelector('#storeV2Retry')?.addEventListener('click', retryStore);
}

function retryStore(){
  return storeSurface === 'tab' ? openStoreTab() : openStoreSheet();
}

function currentRoot(){
  return storeSurface === 'tab' ? document.getElementById('storeTabSurface') : document.getElementById('sheet');
}

function purchaseToken(){
  if (globalThis.crypto?.randomUUID) return `store:${globalThis.crypto.randomUUID()}`;
  return `store:${Date.now().toString(36)}:${Math.random().toString(36).slice(2, 14)}`;
}

function cloneObject(value){ return value && typeof value === 'object' ? JSON.parse(JSON.stringify(value)) : value; }
function formatNumber(value){ return Number(value || 0).toLocaleString('ru-RU'); }
function formatEuro(cents){ return new Intl.NumberFormat('ru-RU', { style:'currency', currency:'EUR' }).format(Number(cents || 0) / 100); }
function escapeHtml(value){
  return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
}
function escapeAttr(value){ return escapeHtml(value); }
