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
let equipBusy = false;

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
    case 'games': return renderGamesTab();
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

function renderGamesTab(){
  const catalog = storeState?.games?.catalogs?.tictactoe;
  if (!catalog) return emptyState('Игровая косметика пока недоступна');
  return `
    <div class="store-v2-game-head">
      <div>
        <span>Оформление игры</span>
        <h2>${escapeHtml(catalog.title || 'Крестики-нолики')}</h2>
      </div>
      <div class="store-v2-game-head-marks" aria-hidden="true"><b>✕</b><b>○</b></div>
    </div>
    ${renderGameCosmeticGroup('Поля', 'Фон и сетка игрового поля', catalog.themes)}
    ${renderGameCosmeticGroup('Знаки', 'Внешний вид крестиков и ноликов', catalog.elements)}
    ${renderGameCosmeticGroup('Эффекты', 'Анимации хода и завершения матча', catalog.effects)}
  `;
}

function renderGameCosmeticGroup(title, subtitle, offers){
  const items = Array.isArray(offers) ? offers : [];
  return `
    <section class="store-v2-game-group">
      <div class="store-v2-title-row store-v2-game-title-row">
        <div><h2>${escapeHtml(title)}</h2><p>${escapeHtml(subtitle)}</p></div>
      </div>
      <div class="store-v2-game-grid">${items.map(renderGameOffer).join('')}</div>
    </section>
  `;
}

function renderGameOffer(offer){
  const owned = Boolean(offer?.already_owned);
  const equipped = owned && Boolean(offer?.equipped);
  const itemId = String(offer?.item_ids?.[0] || '');
  const layer = String(offer?.metadata?.layer || 'theme');
  const variant = String(offer?.metadata?.variant || 'base');
  const price = formatNumber(offer?.price_coins || 0);
  const kind = layer === 'theme' ? 'Игровое поле' : (layer === 'elements' ? 'Комплект знаков' : 'Эффект матча');
  const description = gameCosmeticDescription(layer, variant);
  return `
    <article class="store-v2-game-product ${owned ? 'owned' : ''} ${equipped ? 'equipped' : ''}">
      ${gameCosmeticPreview(layer, variant, offer?.display_name || '')}
      <div class="store-v2-game-product-copy">
        <span>${escapeHtml(kind)}</span>
        <strong>${escapeHtml(offer?.display_name || itemId)}</strong>
        <p>${escapeHtml(description)}</p>
      </div>
      <div class="store-v2-game-product-foot">
        ${owned
          ? `<button class="store-v2-equip ${equipped ? 'active' : ''}" data-store-v2-equip="${escapeAttr(itemId)}" type="button" ${equipped ? 'disabled' : ''}>${equipped ? 'Выбрано' : 'Выбрать'}</button>`
          : `<button class="store-v2-buy store-v2-game-buy" data-store-v2-buy="${escapeAttr(offer?.offer_id || '')}" type="button"><span>Купить</span><b>${price} коинов</b></button>`}
      </div>
    </article>
  `;
}

function gameCosmeticDescription(layer, variant){
  if (layer === 'theme') {
    return ({ classic:'Тёплая классическая доска', dark:'Строгое тёмное оформление', glass:'Объёмное стеклянное поле', neon:'Неоновая сетка и свечение' })[variant] || 'Меняет фон и сетку поля';
  }
  if (layer === 'elements') {
    return ({ classic:'Чистые классические X и O', '3d':'Объёмные светлые знаки', metal:'Золотой X и стальной O', neon:'Светящиеся неоновые знаки' })[variant] || 'Меняет крестики и нолики';
  }
  return ({ sign:'Вспышка при установке знака', 'winning-line':'Светящаяся линия победы', 'strike-through':'Перечёркивает проигравшие знаки' })[variant] || 'Добавляет эффект в матч';
}

function gameCosmeticPreview(layer, variant, label = ''){
  const safeLayer = ['theme','elements','effect'].includes(String(layer)) ? String(layer) : 'theme';
  const safeVariant = String(variant || 'base').replace(/[^a-z0-9-]/g, '');
  let content = '';
  if (safeLayer === 'theme') {
    const marks = ['✕','','○','','○','','✕','','✕'];
    content = `<i class="store-v2-mini-board">${marks.map(mark => `<span>${mark ? `<b>${mark}</b>` : ''}</span>`).join('')}</i>`;
  }
  else if (safeLayer === 'elements') content = '<i class="store-v2-mini-marks"><span>✕</span><span>○</span></i>';
  else {
    const badge = safeVariant === 'sign' ? '<b aria-hidden="true">+</b>' : '';
    content = `<i class="store-v2-mini-effect"><span class="store-v2-effect-x" aria-hidden="true"></span>${badge}</i>`;
  }
  return `<div class="store-v2-game-preview" data-cosmetic-layer="${safeLayer}" data-cosmetic-variant="${safeVariant}" role="img" aria-label="${escapeAttr(label)}">${content}</div>`;
}

function renderBundlesTab(){
  const bundle = storeState?.bundles?.tictactoe_bundle;
  if (!bundle) return emptyState('Наборы пока недоступны');
  const missing = Number(bundle.missing_count || 0);
  const owned = Number(bundle.owned_count || 0);
  const allOwned = Boolean(bundle.already_owned);
  const regularMissingPrice = regularBundlePrice(bundle);
  const saving = Math.max(0, regularMissingPrice - Number(bundle.price_coins || 0));
  return `
    <article class="store-v2-bundle ${allOwned ? 'owned' : ''}">
      <div class="store-v2-bundle-visual" aria-hidden="true">
        <span>✕</span><span>○</span><span>＋</span><span>／</span><span>×</span>
      </div>
      <div class="store-v2-bundle-copy">
        <h2>Неоновый комплект</h2>
        <p>Поле, знаки и три эффекта для крестиков-ноликов.</p>
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
  return Number(bundle?.regular_missing_price_coins || bundle?.regular_price_coins || 0);
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
  root.querySelectorAll('[data-store-v2-equip]').forEach(button => {
    button.addEventListener('click', () => {
      const itemId = String(button.dataset.storeV2Equip || '');
      if (!itemId || button.disabled) return;
      void equipGameItem(itemId);
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
  return offersFromSnapshot(storeState).find(item => String(item?.offer_id || '') === offerId) || null;
}

function offersFromSnapshot(snapshot){
  const catalog = snapshot?.games?.catalogs?.tictactoe || {};
  const groups = [catalog.themes, catalog.elements, catalog.effects];
  const offers = [
    ...(Array.isArray(snapshot?.profile?.avatars) ? snapshot.profile.avatars : []),
    ...groups.flatMap(group => Array.isArray(group) ? group : []),
  ];
  const avatarBundle = snapshot?.bundles?.avatar_bundle;
  const tictactoeBundle = snapshot?.bundles?.tictactoe_bundle;
  if (avatarBundle) offers.push(avatarBundle);
  if (tictactoeBundle) offers.push(tictactoeBundle);
  return offers;
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

  const offers = offersFromSnapshot(next);
  offers.forEach(candidate => {
    const ids = Array.isArray(candidate?.item_ids) ? candidate.item_ids.map(String) : [];
    if (!ids.some(itemId => purchasedSet.has(itemId))) return;
    const previousMissing = Array.isArray(candidate.missing_item_ids) ? candidate.missing_item_ids.map(String) : ids;
    const remaining = previousMissing.filter(itemId => !purchasedSet.has(itemId));
    candidate.missing_item_ids = remaining;
    candidate.missing_count = remaining.length;
    candidate.owned_count = Math.max(0, ids.length - remaining.length);
    candidate.already_owned = remaining.length === 0;
    candidate.purchasable = remaining.length > 0;
  });

  const bundles = [next?.bundles?.avatar_bundle, next?.bundles?.tictactoe_bundle].filter(Boolean);
  const individualByItem = new Map();
  offers.filter(candidate => String(candidate?.offer_type || '') === 'item').forEach(candidate => {
    const itemId = String(candidate?.item_ids?.[0] || '');
    if (itemId) individualByItem.set(itemId, Number(candidate?.full_price_coins || candidate?.price_coins || 0));
  });
  bundles.forEach(bundle => {
    const members = Array.isArray(bundle.item_ids) ? bundle.item_ids.map(String) : [];
    const remaining = Array.isArray(bundle.missing_item_ids) ? bundle.missing_item_ids.map(String) : members;
    const regularMissing = remaining.reduce((total, itemId) => total + Number(individualByItem.get(itemId) || 0), 0);
    bundle.missing_item_ids = remaining;
    bundle.missing_count = remaining.length;
    bundle.owned_count = Math.max(0, members.length - remaining.length);
    bundle.already_owned = remaining.length === 0;
    bundle.purchasable = remaining.length > 0;
    bundle.regular_missing_price_coins = regularMissing;
    bundle.price_coins = remaining.length ? Math.min(Number(bundle.full_price_coins || 0), regularMissing) : 0;
  });

  const inventoryItems = Array.isArray(next?.inventory?.items) ? next.inventory.items : [];
  purchasedIds.forEach(itemId => {
    if (inventoryItems.some(item => String(item?.item_id || '') === itemId)) return;
    const sourceOffer = offers.find(candidate => String(candidate?.item_ids?.[0] || '') === itemId) || {};
    inventoryItems.push({
      item_id:itemId,
      item_type:String(sourceOffer?.item_type || ''),
      item_family:String(sourceOffer?.item_family || ''),
      equip_slot:String(sourceOffer?.equip_slot || ''),
      metadata:cloneObject(sourceOffer?.metadata || {}),
      store_product:true,
      equipped:false,
    });
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
  const isAvatar = String(offer.item_family || '') === 'avatar';
  const number = Number(offer.preview_number || 0);
  const itemId = Array.isArray(offer.item_ids) ? String(offer.item_ids[0] || '') : '';
  const balance = Number(storeState?.balance || 0);
  const price = Number(offer.price_coins || 0);
  const missing = Math.max(0, price - balance);
  const title = isBundle
    ? String(offer.display_name || 'Неоновый комплект')
    : (isAvatar ? `Аватарка ${number}` : String(offer.display_name || 'Игровой предмет'));
  let visual;
  if (isBundle) {
    visual = '<div class="store-v2-confirm-game-bundle"><span>✕</span><span>○</span><b>＋3</b></div>';
  } else if (isAvatar) {
    visual = `<div class="store-v2-confirm-avatar store-v2-avatar-preview" data-avatar-item-id="${escapeAttr(itemId)}" data-avatar-preview="${number}" role="img" aria-label="${escapeAttr(`Аватарка ${number}`)}"><span>${String(number).padStart(2,'0')}</span></div>`;
  } else {
    visual = `<div class="store-v2-confirm-game">${gameCosmeticPreview(offer?.metadata?.layer, offer?.metadata?.variant, title)}</div>`;
  }

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
    const isAvatar = String(offer?.item_family || '') === 'avatar';
    toast(String(offer.offer_type || '') === 'bundle'
      ? 'Комплект добавлен в коллекцию.'
      : (isAvatar ? 'Аватарка добавлена в коллекцию.' : 'Предмет добавлен в коллекцию.'));
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

async function equipGameItem(itemId){
  if (equipBusy || !storeState) return;
  const previousStoreState = cloneObject(storeState);
  const next = cloneObject(storeState);
  const target = offersFromSnapshot(next).find(candidate => String(candidate?.item_ids?.[0] || '') === itemId);
  const slot = String(target?.equip_slot || '');
  if (!target || !target.already_owned || !slot) return;

  equipBusy = true;
  offersFromSnapshot(next).forEach(candidate => {
    if (String(candidate?.equip_slot || '') === slot) candidate.equipped = String(candidate?.item_ids?.[0] || '') === itemId;
  });
  next.inventory ||= { items:[], equipped:{} };
  next.inventory.equipped ||= {};
  next.inventory.equipped[slot] = itemId;
  (next.inventory.items || []).forEach(item => {
    if (String(item?.equip_slot || '') === slot) item.equipped = String(item?.item_id || '') === itemId;
  });
  storeState = next;
  renderStore();

  try {
    const result = await api.cosmeticStoreEquip(itemId);
    applyStoreResponse(result);
    renderStore();
    haptic('success');
    toast('Предмет выбран.');
  } catch (error) {
    storeState = previousStoreState;
    renderStore();
    haptic('error');
    toast(error?.message || 'Не удалось выбрать предмет.');
  } finally {
    equipBusy = false;
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
