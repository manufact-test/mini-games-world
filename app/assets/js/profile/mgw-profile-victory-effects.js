import { api } from '../api/client.js?v=47';
import { state } from '../state.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=27';
import { renderBalances } from '../ui.js?v=89';
import { selectWinnerVictoryEffect } from './mgw-victory-effect-selector.js?v=1';

const VICTORY_EFFECT_SLOT = 'profile_victory_effect';
const SPARK_BURST_ID = 'profile-victory-effect-01';
const VICTORY_PRESENTATION = Object.freeze({
  [SPARK_BURST_ID]:Object.freeze({
    variant:'spark-burst',
    duration:2200,
    fallbackName:'Искровой залп',
    tier:'Уровень I',
  }),
});

let initialized = false;
let observer = null;
let scheduled = false;
let refreshPromise = null;
let snapshotAttempted = false;
let equipBusy = false;
let liveHideTimer = 0;
const purchasePending = new Set();
const playedGames = new Set();

export function initMgwProfileVictoryEffects(){
  if (initialized) return;
  initialized = true;
  ensureStylesheet();

  const start = () => {
    observer?.disconnect();
    observer = new MutationObserver(() => {
      scheduleDecorate();
      scheduleResultProbe();
    });
    observeRoots();

    document.addEventListener('mgw:cosmetic-inventory-changed', event => {
      scheduleDecorate();
      if (String(event?.detail?.slot || '').trim() === VICTORY_EFFECT_SLOT) scheduleResultProbe();
    });

    document.addEventListener('mgw:screen-changed', event => {
      const next = String(event?.detail?.to || '').trim();
      if (next === 'profile' || next === 'store') {
        scheduleDecorate();
        void ensureSnapshot();
      }
      if (next !== 'game') removeLiveVictoryEffect();
      else scheduleResultProbe();
    });

    document.addEventListener('mgw:game-finished', scheduleResultProbe);
    document.addEventListener('mgw:game-dismissed', removeLiveVictoryEffect);

    const active = String(document.querySelector('.screen.active')?.dataset.screen || '').trim();
    if (active === 'profile' || active === 'store') void ensureSnapshot();
    scheduleDecorate();
    scheduleResultProbe();
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once:true });
  else start();
}

function ensureStylesheet(){
  if (document.querySelector('link[data-mgw-victory-effects-css]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwVictoryEffectsCss = 'spark-burst-v1';
  link.href = new URL('../../css/production-v109-victory-effects-spark-burst.css?v=1&mvp19_3=spark-burst', import.meta.url).href;
  document.head.append(link);
}

function observeRoots(){
  if (!observer) return;
  observer.disconnect();
  const profile = document.getElementById('screen-profile');
  const store = document.getElementById('storeTabSurface');
  const sheet = document.getElementById('sheet');
  if (profile) observer.observe(profile, { childList:true, subtree:true });
  if (store) observer.observe(store, { childList:true, subtree:true });
  if (sheet) observer.observe(sheet, {
    childList:true,
    subtree:true,
    attributes:true,
    attributeFilter:['disabled','aria-busy','class'],
  });
}

function scheduleDecorate(){
  if (scheduled) return;
  scheduled = true;
  queueMicrotask(() => {
    scheduled = false;
    observer?.disconnect();
    try { decorateCollectionSurfaces(); }
    finally { observeRoots(); }
  });
}

function decorateCollectionSurfaces(){
  const catalog = victoryEffectCatalog();
  if (!catalog.length) return;
  renderStoreSection(catalog);
  renderProfileCollection(catalog);
}

function ensureSnapshot(){
  if (victoryEffectCatalog().length || snapshotAttempted) return Promise.resolve(state.profileInventory);
  snapshotAttempted = true;
  return refreshSnapshot();
}

function refreshSnapshot(){
  if (refreshPromise) return refreshPromise;
  snapshotAttempted = true;
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

function victoryEffectCatalog(){
  const catalog = Array.isArray(state.profileInventory?.catalog) ? state.profileInventory.catalog : [];
  return catalog
    .filter(item => item
      && item.item_type === 'profile'
      && item.item_family === 'victory_effect'
      && item.equip_slot === VICTORY_EFFECT_SLOT
      && String(item.catalog_status || '') === 'active')
    .map(item => ({ ...item, item_id:String(item.item_id || '') }))
    .filter(item => Boolean(VICTORY_PRESENTATION[item.item_id]));
}

function currentVictoryEffectId(){
  const itemId = String(state.profileInventory?.equipped?.[VICTORY_EFFECT_SLOT] || '').trim();
  return VICTORY_PRESENTATION[itemId] ? itemId : '';
}

function meta(item){ return item?.metadata && typeof item.metadata === 'object' ? item.metadata : {}; }
function itemName(item){ return String(meta(item).display_name || VICTORY_PRESENTATION[item?.item_id]?.fallbackName || 'Эффект победы'); }
function itemPrice(item){ return Math.max(0, Number(meta(item).price_coins || 0)); }
function itemOfferId(item){ return String(meta(item).offer_id || String(item?.item_id || '').replace(/^profile-/, '')); }
function itemTier(item){ return String(VICTORY_PRESENTATION[item?.item_id]?.tier || 'Эффект победы'); }
function presentationFor(itemId){ return VICTORY_PRESENTATION[String(itemId || '')] || null; }

function particleMarkup(){
  return `<span class="mgw-victory-spark-particles">${'<i></i>'.repeat(24)}</span><span class="mgw-victory-spark-confetti">${'<b></b>'.repeat(12)}</span>`;
}

function previewMarkup(itemId, selected = false, extraClass = ''){
  const spec = presentationFor(itemId);
  if (!spec) return '';
  return `<span class="mgw-victory-effect-preview ${escapeAttr(extraClass)}" data-victory-effect-variant="${escapeAttr(spec.variant)}" aria-hidden="true">
    <span class="mgw-victory-effect-preview-stage"><i class="mgw-victory-spark-flash"></i><i class="mgw-victory-spark-ring ring-a"></i><i class="mgw-victory-spark-ring ring-b"></i>${particleMarkup()}</span>
    ${selected ? '<em class="store-v2-selected-check">✓</em>' : ''}
  </span>`;
}

function renderStoreSection(catalog){
  const panel = document.querySelector('.store-v2-content[data-store-v2-panel="profile"]');
  if (!(panel instanceof HTMLElement)) return;
  const active = currentVictoryEffectId();
  const signature = catalog.map(item => `${item.item_id}:${item.owned === true ? 1 : 0}`).join('|') + `|${active}`;
  let section = panel.querySelector('[data-profile-victory-effect-store-section]');
  const anchor = panel.querySelector('[data-profile-entry-effect-store-section]')
    || panel.querySelector('[data-profile-reaction-store-section]')
    || panel.querySelector('[data-profile-background-store-section]');

  if (section instanceof HTMLElement && anchor instanceof HTMLElement && section.previousElementSibling !== anchor) {
    anchor.insertAdjacentElement('afterend', section);
  }
  if (section instanceof HTMLElement && section.dataset.profileVictoryEffectSignature === signature) return;

  const markup = `<section class="store-v2-victory-effect-section" data-profile-victory-effect-store-section data-profile-victory-effect-signature="${escapeAttr(signature)}">
    <div class="store-v2-title-row"><h2>Эффекты победы</h2></div>
    <div class="store-v2-victory-effect-grid">${catalog.map(item => storeCard(item, active)).join('')}</div>
  </section>`;

  if (section instanceof HTMLElement) section.outerHTML = markup;
  else if (anchor instanceof HTMLElement) anchor.insertAdjacentHTML('afterend', markup);
  else panel.insertAdjacentHTML('beforeend', markup);
  section = panel.querySelector('[data-profile-victory-effect-store-section]');
  bindStoreActions(section);
}

function storeCard(item, activeId){
  const itemId = String(item.item_id || '');
  const owned = item.owned === true;
  const active = owned && itemId === activeId;
  const stateName = active ? 'selected' : (owned ? 'owned' : 'available');
  return `<article class="store-v2-product store-v2-victory-effect-card mgw-profile-cosmetic-card${owned ? ' owned' : ''}${active ? ' equipped' : ''}" data-mgw-profile-cosmetic-state="${stateName}">
    ${previewMarkup(itemId, active, 'store-v2-victory-effect-preview')}
    <div class="store-v2-victory-effect-copy"><strong>${escapeHtml(itemName(item))}</strong><small>Эффект победы · ${escapeHtml(itemTier(item))}</small></div>
    <div class="store-v2-product-foot store-v2-victory-effect-foot mgw-profile-cosmetic-foot">
      ${owned
        ? (active
          ? '<b data-mgw-profile-cosmetic-status>Выбрано</b><button class="store-v2-equip active mgw-profile-cosmetic-action" data-victory-effect-unequip type="button">Снять</button>'
          : `<b data-mgw-profile-cosmetic-status>В коллекции</b><button class="store-v2-equip mgw-profile-cosmetic-action" data-victory-effect-equip="${escapeAttr(itemId)}" type="button">Выбрать</button>`)
        : `<b>${formatNumber(itemPrice(item))}</b><button class="store-v2-buy mgw-profile-cosmetic-action" data-victory-effect-buy="${escapeAttr(itemId)}" type="button">Купить</button>`}
    </div>
  </article>`;
}

function bindStoreActions(section){
  if (!(section instanceof HTMLElement)) return;
  section.querySelectorAll('[data-victory-effect-buy]').forEach(button => button.addEventListener('click', () => openPurchase(String(button.dataset.victoryEffectBuy || ''))));
  section.querySelectorAll('[data-victory-effect-equip]').forEach(button => button.addEventListener('click', () => void saveSelection(String(button.dataset.victoryEffectEquip || ''), false)));
  section.querySelectorAll('[data-victory-effect-unequip]').forEach(button => button.addEventListener('click', () => void saveSelection(currentVictoryEffectId(), true)));
}

function renderProfileCollection(catalog){
  const collection = document.querySelector('#screen-profile .profile-v2-collection-section');
  if (!(collection instanceof HTMLElement)) return;
  const owned = catalog.filter(item => item.owned === true);
  let section = collection.querySelector('[data-profile-victory-effect-collection]');
  if (!owned.length) { section?.remove(); return; }

  const anchor = collection.querySelector('[data-profile-entry-effect-collection]')
    || collection.querySelector('[data-profile-reaction-collection]')
    || collection.querySelector('[data-profile-background-collection]');
  if (section instanceof HTMLElement && anchor instanceof HTMLElement && section.previousElementSibling !== anchor) {
    anchor.insertAdjacentElement('afterend', section);
  }

  const active = currentVictoryEffectId();
  const signature = owned.map(item => item.item_id).join('|') + `|${active}`;
  if (section instanceof HTMLElement && section.dataset.profileVictoryEffectSignature === signature) return;
  const markup = `<div class="profile-v2-victory-effect-collection" data-profile-victory-effect-collection data-profile-victory-effect-signature="${escapeAttr(signature)}" aria-label="Эффекты победы">
    <div class="profile-v2-collection-title">Эффекты победы</div>
    <div class="profile-v2-victory-effect-grid">${owned.map(item => profileCard(item, active)).join('')}</div>
  </div>`;

  if (section instanceof HTMLElement) section.outerHTML = markup;
  else if (anchor instanceof HTMLElement) anchor.insertAdjacentHTML('afterend', markup);
  else collection.insertAdjacentHTML('beforeend', markup);
  section = collection.querySelector('[data-profile-victory-effect-collection]');
  section?.querySelectorAll('[data-victory-effect-preview]').forEach(button => button.addEventListener('click', () => openPreview(String(button.dataset.victoryEffectPreview || ''))));
}

function profileCard(item, activeId){
  const itemId = String(item.item_id || '');
  const active = itemId === activeId;
  return `<button class="profile-v2-victory-effect-card${active ? ' active' : ''}" type="button" data-victory-effect-preview="${escapeAttr(itemId)}" data-mgw-profile-cosmetic-state="${active ? 'selected' : 'owned'}" aria-pressed="${active ? 'true' : 'false'}">
    ${previewMarkup(itemId, false, 'profile-v2-victory-effect-preview')}
    <span class="profile-v2-victory-effect-copy"><b>${escapeHtml(itemName(item))}</b><small>${escapeHtml(itemTier(item))}</small></span>
    ${active ? '<i class="profile-v2-selected-check" aria-hidden="true">✓</i>' : ''}
  </button>`;
}

function openPurchase(itemId){
  const item = victoryEffectCatalog().find(candidate => candidate.item_id === itemId && candidate.owned !== true);
  if (!item || purchasePending.has(itemId)) return;
  const price = itemPrice(item);
  const balance = Number(state.user?.balance || 0);
  const missing = Math.max(0, price - balance);
  openSheet(`<div class="sheet-head"><div><h2>Подтвердить покупку</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="store-v2-confirm">
      <div class="mgw-victory-effect-sheet-preview">${previewMarkup(itemId, false, 'profile-v2-victory-effect-preview')}</div>
      <div class="store-v2-confirm-copy"><strong>${escapeHtml(itemName(item))}</strong><small>Эффект победы · ${escapeHtml(itemTier(item))}</small></div>
      <div class="store-v2-confirm-price"><span>К оплате</span><strong>${formatNumber(price)} коинов</strong></div>
      <div class="store-v2-confirm-balance"><span>Останется</span><b>${formatNumber(Math.max(0, balance - price))}</b></div>
      <button class="btn primary full" id="mgwVictoryEffectConfirmBuy" type="button"${missing > 0 ? ' disabled' : ''}>${missing > 0 ? `Не хватает ${formatNumber(missing)}` : `Купить за ${formatNumber(price)}`}</button>
    </div>`);
  document.getElementById('mgwVictoryEffectConfirmBuy')?.addEventListener('click', () => void purchase(item));
}

async function purchase(item){
  const itemId = String(item?.item_id || '');
  if (!itemId || purchasePending.has(itemId)) return;
  const previous = cloneObject(state.profileInventory);
  purchasePending.add(itemId);
  applyOptimisticPurchase(itemId);
  closeSheet();
  scheduleDecorate();
  try {
    const result = await api.cosmeticStorePurchase(itemOfferId(item), purchaseToken());
    const balance = Number(result?.store?.balance);
    if (Number.isFinite(balance) && state.user && typeof state.user === 'object') {
      state.user = { ...state.user, balance };
      renderBalances(state.user);
    }
    await refreshSnapshot();
    toast('Эффект победы добавлен в коллекцию.');
  } catch (error) {
    state.profileInventory = previous;
    scheduleDecorate();
    toast(error?.message || 'Не удалось купить эффект победы.');
  } finally {
    purchasePending.delete(itemId);
  }
}

function applyOptimisticPurchase(itemId){
  const inventory = cloneObject(state.profileInventory) || { catalog:[], owned:[], equipped:{} };
  if (Array.isArray(inventory.catalog)) {
    inventory.catalog = inventory.catalog.map(item => String(item?.item_id || '') === itemId ? { ...item, owned:true } : item);
  }
  state.profileInventory = inventory;
  document.dispatchEvent(new CustomEvent('mgw:cosmetic-inventory-changed', { detail:{ family:'victory_effect', item_id:itemId, reason:'purchase-optimistic' } }));
}

function openPreview(itemId){
  const item = victoryEffectCatalog().find(candidate => candidate.item_id === itemId && candidate.owned === true);
  if (!item) return;
  const active = itemId === currentVictoryEffectId();
  openSheet(`<div class="sheet-head"><div><h2>${escapeHtml(itemName(item))}</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="mgw-victory-effect-sheet-preview">${previewMarkup(itemId, false, 'profile-v2-victory-effect-preview')}</div>
    <div class="profile-v2-entry-effect-preview-meta"><strong>Эффект победы</strong><small>${escapeHtml(itemTier(item))}</small></div>
    <div class="mgw-profile-cosmetic-sheet-status" data-mgw-profile-cosmetic-sheet-status>${active ? 'Выбрано' : 'В коллекции'}</div>
    <button class="btn ${active ? 'ghost' : 'primary'} full mgw-profile-cosmetic-sheet-action" id="mgwVictoryEffectEquip" type="button">${active ? 'Снять' : 'Выбрать'}</button>`);
  document.getElementById('mgwVictoryEffectEquip')?.addEventListener('click', () => void saveSelection(itemId, active));
}

async function saveSelection(itemId, remove){
  if (equipBusy) return;
  const item = victoryEffectCatalog().find(candidate => candidate.item_id === itemId && candidate.owned === true);
  const active = itemId === currentVictoryEffectId();
  if (!item || (remove && !active) || (!remove && active)) return;

  const previous = cloneObject(state.profileInventory);
  equipBusy = true;
  applyOptimisticSelection(itemId, !remove);
  closeSheet();
  scheduleDecorate();
  try {
    if (remove) await api.cosmeticStoreUnequip(VICTORY_EFFECT_SLOT);
    else await api.cosmeticStoreEquip(itemId);
    await refreshSnapshot();
  } catch (error) {
    state.profileInventory = previous;
    scheduleDecorate();
    toast(error?.message || (remove ? 'Не удалось снять эффект победы.' : 'Не удалось выбрать эффект победы.'));
  } finally {
    equipBusy = false;
  }
}

function applyOptimisticSelection(itemId, equipped){
  const inventory = cloneObject(state.profileInventory) || { catalog:[], owned:[], equipped:{} };
  inventory.equipped = { ...(inventory.equipped || {}) };
  if (equipped) inventory.equipped[VICTORY_EFFECT_SLOT] = itemId;
  else delete inventory.equipped[VICTORY_EFFECT_SLOT];
  if (Array.isArray(inventory.catalog)) {
    inventory.catalog = inventory.catalog.map(item => String(item?.equip_slot || '') === VICTORY_EFFECT_SLOT
      ? { ...item, equipped:equipped && String(item.item_id || '') === itemId }
      : item);
  }
  state.profileInventory = inventory;
  document.dispatchEvent(new CustomEvent('mgw:cosmetic-inventory-changed', { detail:{ slot:VICTORY_EFFECT_SLOT } }));
}

function scheduleResultProbe(){
  queueMicrotask(playVictoryEffectIfReady);
}

function playVictoryEffectIfReady(){
  const summary = document.querySelector('#resultSummary[data-result-game-id]');
  if (!(summary instanceof HTMLElement)) {
    removeLiveVictoryEffect();
    return;
  }

  const game = state.activeGame;
  const gameId = String(game?.id || '').trim();
  if (!gameId || String(summary.dataset.resultGameId || '') !== gameId) return;
  if (String(game?.status || '') !== 'finished' || playedGames.has(gameId)) return;
  if (document.querySelector('#sheet [aria-busy="true"],#sheet button:disabled')) return;

  const selection = selectWinnerVictoryEffect(game);
  const spec = presentationFor(selection?.itemId || '');
  if (!selection || !spec) return;

  playedGames.add(gameId);
  while (playedGames.size > 80) playedGames.delete(playedGames.values().next().value);
  removeLiveVictoryEffect();

  const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true;
  const duration = reduced ? 2000 : Math.min(4000, Math.max(2000, Number(spec.duration || 2200)));
  const layer = document.createElement('div');
  layer.className = `mgw-victory-effect-layer${reduced ? ' reduced-motion' : ''}`;
  layer.dataset.victoryEffectGameId = gameId;
  layer.dataset.victoryEffectItemId = selection.itemId;
  layer.innerHTML = `<button class="mgw-victory-effect-skip" type="button" aria-label="Пропустить эффект победы">Пропустить</button><div class="mgw-victory-effect-live-stage" data-victory-effect-variant="${escapeAttr(spec.variant)}" aria-hidden="true"><i class="mgw-victory-spark-flash"></i><i class="mgw-victory-spark-ring ring-a"></i><i class="mgw-victory-spark-ring ring-b"></i>${particleMarkup()}</div>`;
  document.body.append(layer);

  layer.querySelector('.mgw-victory-effect-skip')?.addEventListener('click', removeLiveVictoryEffect, { once:true });
  window.clearTimeout(liveHideTimer);
  liveHideTimer = window.setTimeout(removeLiveVictoryEffect, duration);
}

function removeLiveVictoryEffect(){
  window.clearTimeout(liveHideTimer);
  liveHideTimer = 0;
  document.querySelectorAll('.mgw-victory-effect-layer').forEach(node => node.remove());
}

function purchaseToken(){
  if (globalThis.crypto?.randomUUID) return `store:${globalThis.crypto.randomUUID()}`;
  return `store:${Date.now().toString(36)}:${Math.random().toString(36).slice(2,14)}`;
}
function cloneObject(value){ return value && typeof value === 'object' ? JSON.parse(JSON.stringify(value)) : value; }
function formatNumber(value){ return Number(value || 0).toLocaleString('ru-RU'); }
function escapeHtml(value){ return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }
function escapeAttr(value){ return escapeHtml(value); }
