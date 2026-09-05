import { api } from '../api/client.js?v=47';
import { state } from '../state.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=27';
import { renderBalances } from '../ui.js?v=89';

const ENTRY_EFFECT_SLOT = 'profile_entry_effect';
const ENTRY_EFFECT_IDS = Object.freeze([
  'profile-entry-effect-01',
  'profile-entry-effect-02',
  'profile-entry-effect-03',
]);
const ENTRY_EFFECT_PRESENTATION = Object.freeze({
  'profile-entry-effect-01':Object.freeze({ variant:'entry-01', duration:2400, fallbackName:'Эффект входа I' }),
  'profile-entry-effect-02':Object.freeze({ variant:'entry-02', duration:3000, fallbackName:'Эффект входа II' }),
  'profile-entry-effect-03':Object.freeze({ variant:'entry-03', duration:3600, fallbackName:'Эффект входа III' }),
});

let initialized = false;
let observer = null;
let scheduled = false;
let refreshPromise = null;
let snapshotAttempted = false;
let equipBusy = false;
const purchasePending = new Set();
const playedGames = new Set();
let liveHideTimer = 0;
let liveProbeTimer = 0;

export function initMgwProfileEntryEffects(){
  if (initialized) return;
  initialized = true;

  const start = () => {
    observer?.disconnect();
    observer = new MutationObserver(scheduleDecorate);
    observeRoots();

    document.addEventListener('mgw:cosmetic-inventory-changed', event => {
      scheduleDecorate();
      if (String(event?.detail?.slot || '').trim() !== ENTRY_EFFECT_SLOT) return;
      applyLocalEntryEffectProjection(state.activeGame);
      const active = String(document.querySelector('.screen.active')?.dataset.screen || '').trim();
      if (active === 'game') armLiveEntryEffectProbe();
    });
    document.addEventListener('mgw:screen-changed', event => {
      const next = String(event?.detail?.to || '').trim();
      if (next === 'profile' || next === 'store') {
        scheduleDecorate();
        void ensureSnapshot();
      }
      if (next === 'game') {
        applyLocalEntryEffectProjection(state.activeGame);
        armLiveEntryEffectProbe();
      }
      if (String(event?.detail?.from || '') === 'game' && next !== 'game') removeLiveEntryEffects();
    });
    document.addEventListener('mgw:phase-b-game-entering', event => {
      applyLocalEntryEffectProjection(event?.detail?.game);
      queueMicrotask(() => {
        applyLocalEntryEffectProjection(state.activeGame);
        armLiveEntryEffectProbe();
      });
    });

    const active = String(document.querySelector('.screen.active')?.dataset.screen || '').trim();
    if (active === 'profile' || active === 'store') void ensureSnapshot();
    if (active === 'game') {
      applyLocalEntryEffectProjection(state.activeGame);
      armLiveEntryEffectProbe();
    }
    scheduleDecorate();
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once:true });
  else start();
}

function observeRoots(){
  if (!observer) return;
  observer.disconnect();
  [document.getElementById('screen-profile'), document.getElementById('storeTabSurface'), document.getElementById('sheet')].forEach(root => {
    if (root) observer.observe(root, { childList:true, subtree:true });
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
  const catalog = entryEffectCatalog();
  if (!catalog.length) return;
  renderStoreSection(catalog);
  renderProfileCollection(catalog);
}

function ensureSnapshot(){
  if (entryEffectCatalog().length || snapshotAttempted) return Promise.resolve(state.profileInventory);
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

function entryEffectCatalog(){
  const catalog = Array.isArray(state.profileInventory?.catalog) ? state.profileInventory.catalog : [];
  return catalog
    .filter(item => item
      && item.item_type === 'profile'
      && item.item_family === 'entry_effect'
      && item.equip_slot === ENTRY_EFFECT_SLOT
      && String(item.catalog_status || '') === 'active')
    .map(item => ({ ...item, item_id:String(item.item_id || '') }))
    .filter(item => ENTRY_EFFECT_IDS.includes(item.item_id))
    .sort((left, right) => ENTRY_EFFECT_IDS.indexOf(left.item_id) - ENTRY_EFFECT_IDS.indexOf(right.item_id));
}

function currentEntryEffectId(){
  const itemId = String(state.profileInventory?.equipped?.[ENTRY_EFFECT_SLOT] || '').trim();
  return ENTRY_EFFECT_IDS.includes(itemId) ? itemId : '';
}

function meta(item){ return item?.metadata && typeof item.metadata === 'object' ? item.metadata : {}; }
function itemName(item){ return String(meta(item).display_name || ENTRY_EFFECT_PRESENTATION[item?.item_id]?.fallbackName || 'Эффект входа'); }
function itemPrice(item){ return Math.max(0, Number(meta(item).price_coins || 0)); }
function itemOfferId(item){ return String(meta(item).offer_id || String(item?.item_id || '').replace(/^profile-/, '')); }
function itemTier(item){
  const tier = String(meta(item).tier || '');
  return ({ 'tier-1':'Уровень I', 'tier-2':'Уровень II', 'tier-3':'Уровень III' })[tier] || 'Эффект входа';
}
function presentationFor(itemId){ return ENTRY_EFFECT_PRESENTATION[String(itemId || '')] || null; }

function previewMarkup(itemId, selected = false, extraClass = ''){
  const spec = presentationFor(itemId);
  if (!spec) return '';
  return `<span class="mgw-entry-effect-preview ${escapeAttr(extraClass)}" data-entry-effect-variant="${escapeAttr(spec.variant)}" aria-hidden="true">
    <span class="mgw-entry-effect-preview-core"><i></i><b>MG</b><i></i></span>
    ${selected ? '<em class="store-v2-selected-check">✓</em>' : ''}
  </span>`;
}

function renderStoreSection(catalog){
  const panel = document.querySelector('.store-v2-content[data-store-v2-panel="profile"]');
  if (!(panel instanceof HTMLElement)) return;
  const active = currentEntryEffectId();
  const signature = catalog.map(item => `${item.item_id}:${item.owned === true ? 1 : 0}`).join('|') + `|${active}`;
  let section = panel.querySelector('[data-profile-entry-effect-store-section]');
  const anchor = panel.querySelector('[data-profile-reaction-store-section]')
    || panel.querySelector('[data-profile-background-store-section]')
    || panel.querySelector('[data-profile-frame-store-section]');

  if (section instanceof HTMLElement && anchor instanceof HTMLElement && section.previousElementSibling !== anchor) {
    anchor.insertAdjacentElement('afterend', section);
  }
  if (section instanceof HTMLElement && section.dataset.profileEntryEffectSignature === signature) return;

  const markup = `<section class="store-v2-entry-effect-section" data-profile-entry-effect-store-section data-profile-entry-effect-signature="${escapeAttr(signature)}">
    <div class="store-v2-title-row"><h2>Эффекты входа</h2></div>
    <div class="store-v2-entry-effect-grid">${catalog.map(item => storeCard(item, active)).join('')}</div>
  </section>`;

  if (section instanceof HTMLElement) section.outerHTML = markup;
  else if (anchor instanceof HTMLElement) anchor.insertAdjacentHTML('afterend', markup);
  else panel.insertAdjacentHTML('beforeend', markup);
  section = panel.querySelector('[data-profile-entry-effect-store-section]');
  bindStoreActions(section);
}

function storeCard(item, activeId){
  const itemId = String(item.item_id || '');
  const owned = item.owned === true;
  const active = owned && itemId === activeId;
  const stateName = active ? 'selected' : (owned ? 'owned' : 'available');
  return `<article class="store-v2-product store-v2-entry-effect-card mgw-profile-cosmetic-card${owned ? ' owned' : ''}${active ? ' equipped' : ''}" data-mgw-profile-cosmetic-state="${stateName}">
    ${previewMarkup(itemId, active, 'store-v2-entry-effect-preview')}
    <div class="store-v2-entry-effect-copy"><strong>${escapeHtml(itemName(item))}</strong><small>${escapeHtml(itemTier(item))}</small></div>
    <div class="store-v2-product-foot store-v2-entry-effect-foot mgw-profile-cosmetic-foot">
      ${owned
        ? (active
          ? '<b data-mgw-profile-cosmetic-status>Выбрано</b><button class="store-v2-equip active mgw-profile-cosmetic-action" data-entry-effect-unequip type="button">Снять</button>'
          : `<b data-mgw-profile-cosmetic-status>В коллекции</b><button class="store-v2-equip mgw-profile-cosmetic-action" data-entry-effect-equip="${escapeAttr(itemId)}" type="button">Выбрать</button>`)
        : `<b>${formatNumber(itemPrice(item))}</b><button class="store-v2-buy mgw-profile-cosmetic-action" data-entry-effect-buy="${escapeAttr(itemId)}" type="button">Купить</button>`}
    </div>
  </article>`;
}

function bindStoreActions(section){
  if (!(section instanceof HTMLElement)) return;
  section.querySelectorAll('[data-entry-effect-buy]').forEach(button => button.addEventListener('click', () => openPurchase(String(button.dataset.entryEffectBuy || ''))));
  section.querySelectorAll('[data-entry-effect-equip]').forEach(button => button.addEventListener('click', () => void saveSelection(String(button.dataset.entryEffectEquip || ''), false)));
  section.querySelectorAll('[data-entry-effect-unequip]').forEach(button => button.addEventListener('click', () => void saveSelection(currentEntryEffectId(), true)));
}

function renderProfileCollection(catalog){
  const collection = document.querySelector('#screen-profile .profile-v2-collection-section');
  if (!(collection instanceof HTMLElement)) return;
  const owned = catalog.filter(item => item.owned === true);
  let section = collection.querySelector('[data-profile-entry-effect-collection]');
  if (!owned.length) { section?.remove(); return; }

  const anchor = collection.querySelector('[data-profile-reaction-collection]')
    || collection.querySelector('[data-profile-background-collection]')
    || collection.querySelector('[data-profile-frame-collection]');
  if (section instanceof HTMLElement && anchor instanceof HTMLElement && section.previousElementSibling !== anchor) {
    anchor.insertAdjacentElement('afterend', section);
  }

  const active = currentEntryEffectId();
  const signature = owned.map(item => item.item_id).join('|') + `|${active}`;
  if (section instanceof HTMLElement && section.dataset.profileEntryEffectSignature === signature) return;
  const markup = `<div class="profile-v2-entry-effect-collection" data-profile-entry-effect-collection data-profile-entry-effect-signature="${escapeAttr(signature)}" aria-label="Эффекты входа">
    <div class="profile-v2-collection-title">Эффекты входа</div>
    <div class="profile-v2-entry-effect-grid">${owned.map(item => profileCard(item, active)).join('')}</div>
  </div>`;

  if (section instanceof HTMLElement) section.outerHTML = markup;
  else if (anchor instanceof HTMLElement) anchor.insertAdjacentHTML('afterend', markup);
  else collection.insertAdjacentHTML('beforeend', markup);
  section = collection.querySelector('[data-profile-entry-effect-collection]');
  section?.querySelectorAll('[data-entry-effect-preview]').forEach(button => button.addEventListener('click', () => openPreview(String(button.dataset.entryEffectPreview || ''))));
}

function profileCard(item, activeId){
  const itemId = String(item.item_id || '');
  const active = itemId === activeId;
  return `<button class="profile-v2-entry-effect-card${active ? ' active' : ''}" type="button" data-entry-effect-preview="${escapeAttr(itemId)}" data-mgw-profile-cosmetic-state="${active ? 'selected' : 'owned'}" aria-pressed="${active ? 'true' : 'false'}">
    ${previewMarkup(itemId, false, 'profile-v2-entry-effect-preview')}
    <span class="profile-v2-entry-effect-copy"><b>${escapeHtml(itemName(item))}</b><small>${escapeHtml(itemTier(item))}</small></span>
    <span class="profile-v2-entry-effect-status">${active ? 'Выбрано' : 'В коллекции'}</span>
    ${active ? '<i class="profile-v2-selected-check" aria-hidden="true">✓</i>' : ''}
  </button>`;
}

function openPurchase(itemId){
  const item = entryEffectCatalog().find(candidate => candidate.item_id === itemId && candidate.owned !== true);
  if (!item || purchasePending.has(itemId)) return;
  const price = itemPrice(item);
  const balance = Number(state.user?.balance || 0);
  const missing = Math.max(0, price - balance);
  openSheet(`<div class="sheet-head"><div><h2>Подтвердить покупку</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="store-v2-confirm">
      <div class="mgw-entry-effect-sheet-preview">${previewMarkup(itemId, false, 'profile-v2-entry-effect-preview')}</div>
      <div class="store-v2-confirm-copy"><strong>${escapeHtml(itemName(item))}</strong><small>Эффект входа · ${escapeHtml(itemTier(item))}</small></div>
      <div class="store-v2-confirm-price"><span>К оплате</span><strong>${formatNumber(price)} коинов</strong></div>
      <div class="store-v2-confirm-balance"><span>Останется</span><b>${formatNumber(Math.max(0, balance - price))}</b></div>
      <button class="btn primary full" id="mgwEntryEffectConfirmBuy" type="button"${missing > 0 ? ' disabled' : ''}>${missing > 0 ? `Не хватает ${formatNumber(missing)}` : `Купить за ${formatNumber(price)}`}</button>
    </div>`);
  document.getElementById('mgwEntryEffectConfirmBuy')?.addEventListener('click', () => void purchase(item));
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
    toast('Эффект входа добавлен в коллекцию.');
  } catch (error) {
    state.profileInventory = previous;
    scheduleDecorate();
    toast(error?.message || 'Не удалось купить эффект входа.');
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
  document.dispatchEvent(new CustomEvent('mgw:cosmetic-inventory-changed', { detail:{ family:'entry_effect', item_id:itemId, reason:'purchase-optimistic' } }));
}

function openPreview(itemId){
  const item = entryEffectCatalog().find(candidate => candidate.item_id === itemId && candidate.owned === true);
  if (!item) return;
  const active = itemId === currentEntryEffectId();
  openSheet(`<div class="sheet-head"><div><h2>${escapeHtml(itemName(item))}</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="mgw-entry-effect-sheet-preview">${previewMarkup(itemId, false, 'profile-v2-entry-effect-preview')}</div>
    <div class="profile-v2-entry-effect-preview-meta"><strong>Эффект входа</strong><small>${escapeHtml(itemTier(item))}</small></div>
    <div class="mgw-profile-cosmetic-sheet-status" data-mgw-profile-cosmetic-sheet-status>${active ? 'Выбрано' : 'В коллекции'}</div>
    <button class="btn ${active ? 'ghost' : 'primary'} full mgw-profile-cosmetic-sheet-action" id="mgwEntryEffectEquip" type="button">${active ? 'Снять' : 'Выбрать'}</button>`);
  document.getElementById('mgwEntryEffectEquip')?.addEventListener('click', () => void saveSelection(itemId, active));
}

async function saveSelection(itemId, remove){
  if (equipBusy) return;
  const item = entryEffectCatalog().find(candidate => candidate.item_id === itemId && candidate.owned === true);
  const active = itemId === currentEntryEffectId();
  if (!item || (remove && !active) || (!remove && active)) return;

  const previous = cloneObject(state.profileInventory);
  equipBusy = true;
  applyOptimisticSelection(itemId, !remove);
  closeSheet();
  scheduleDecorate();
  try {
    if (remove) await api.cosmeticStoreUnequip(ENTRY_EFFECT_SLOT);
    else await api.cosmeticStoreEquip(itemId);
    await refreshSnapshot();
  } catch (error) {
    state.profileInventory = previous;
    scheduleDecorate();
    toast(error?.message || (remove ? 'Не удалось снять эффект входа.' : 'Не удалось выбрать эффект входа.'));
  } finally {
    equipBusy = false;
  }
}

function applyOptimisticSelection(itemId, equipped){
  const inventory = cloneObject(state.profileInventory) || { catalog:[], owned:[], equipped:{} };
  inventory.equipped = { ...(inventory.equipped || {}) };
  if (equipped) inventory.equipped[ENTRY_EFFECT_SLOT] = itemId;
  else delete inventory.equipped[ENTRY_EFFECT_SLOT];
  if (Array.isArray(inventory.catalog)) {
    inventory.catalog = inventory.catalog.map(item => String(item?.equip_slot || '') === ENTRY_EFFECT_SLOT
      ? { ...item, equipped:equipped && String(item.item_id || '') === itemId }
      : item);
  }
  state.profileInventory = inventory;
  document.dispatchEvent(new CustomEvent('mgw:cosmetic-inventory-changed', { detail:{ slot:ENTRY_EFFECT_SLOT } }));
}

function localEntryEffectPlayerIds(){
  const telegramId = globalThis.Telegram?.WebApp?.initDataUnsafe?.user?.id;
  return new Set([
    state.user?.id,
    state.user?.provider_subject,
    state.user?.telegram_id,
    state.session?.user_id,
    state.session?.provider_subject,
    telegramId,
  ].map(value => String(value ?? '').trim()).filter(Boolean));
}

function isLocalEntryEffectPlayer(player, ids){
  if (!player || typeof player !== 'object') return false;
  if (player.is_me === true || player.me === true || player.viewer === true) return true;
  const playerId = String(player.id ?? '').trim();
  return playerId !== '' && ids.has(playerId);
}

function applyLocalEntryEffectProjection(game){
  if (!game || typeof game !== 'object') return false;
  const selected = currentEntryEffectId();
  if (!presentationFor(selected)) return false;
  const players = Array.isArray(game.players) ? game.players : [];
  if (!players.length) return false;
  const localIds = localEntryEffectPlayerIds();

  for (const player of players) {
    if (!isLocalEntryEffectPlayer(player, localIds)) continue;
    const projected = String(player?.entry_effect_item_id || '').trim();
    if (presentationFor(projected)) return false;
    player.entry_effect_item_id = selected;
    return true;
  }
  return false;
}

function entryEffectIdForPlayer(player){
  const projected = String(player?.entry_effect_item_id || '').trim();
  if (presentationFor(projected)) return projected;
  const localIds = localEntryEffectPlayerIds();
  if (!isLocalEntryEffectPlayer(player, localIds)) return '';
  const selected = currentEntryEffectId();
  return presentationFor(selected) ? selected : '';
}

function armLiveEntryEffectProbe(){
  window.clearTimeout(liveProbeTimer);
  const deadline = Date.now() + 5000;
  const probe = () => {
    liveProbeTimer = 0;
    playLiveEntryEffectsIfNeeded();
    const screen = document.getElementById('screen-game');
    const gameId = String(state.activeGame?.id || '').trim();
    if (!(screen instanceof HTMLElement) || !screen.classList.contains('active') || !gameId || playedGames.has(gameId)) return;
    if (Date.now() >= deadline) return;
    liveProbeTimer = window.setTimeout(probe, 120);
  };
  queueMicrotask(probe);
}

function playLiveEntryEffectsIfNeeded(){
  const screen = document.getElementById('screen-game');
  const game = state.activeGame;
  const gameId = String(game?.id || '').trim();
  if (!(screen instanceof HTMLElement) || !screen.classList.contains('active') || !gameId || playedGames.has(gameId)) return;
  if (String(game?.status || '') !== 'active') return;

  const players = Array.isArray(game?.players) ? game.players : [];
  const entries = players.map((player, index) => {
    const itemId = entryEffectIdForPlayer(player);
    const spec = presentationFor(itemId);
    if (!spec) return null;
    return {
      itemId,
      spec,
      index,
      name:String(player?.name || `Игрок ${index + 1}`),
    };
  }).filter(Boolean);

  if (!entries.length) return;
  playedGames.add(gameId);
  removeLiveEntryEffects();
  const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true;
  const duration = reduced ? 2000 : Math.max(...entries.map(entry => entry.spec.duration));
  const layer = document.createElement('div');
  layer.className = `mgw-entry-effect-layer${reduced ? ' reduced-motion' : ''}`;
  layer.dataset.entryEffectGameId = gameId;
  layer.innerHTML = `<button class="mgw-entry-effect-skip" type="button">Пропустить</button><div class="mgw-entry-effect-live-grid">
    ${entries.map(entry => `<div class="mgw-entry-effect-live-card" data-entry-effect-variant="${escapeAttr(entry.spec.variant)}" data-player-index="${entry.index}">
      <div class="mgw-entry-effect-live-emblem"><i></i><b>MG</b><i></i></div>
      <strong>${escapeHtml(entry.name)}</strong><small>вступает в игру</small>
    </div>`).join('')}
  </div>`;
  screen.append(layer);
  layer.querySelector('.mgw-entry-effect-skip')?.addEventListener('click', removeLiveEntryEffects, { once:true });
  window.clearTimeout(liveHideTimer);
  liveHideTimer = window.setTimeout(removeLiveEntryEffects, Math.min(4000, Math.max(2000, duration)));
}

function removeLiveEntryEffects(){
  window.clearTimeout(liveProbeTimer);
  liveProbeTimer = 0;
  window.clearTimeout(liveHideTimer);
  liveHideTimer = 0;
  document.querySelectorAll('.mgw-entry-effect-layer').forEach(node => node.remove());
}

function purchaseToken(){
  if (globalThis.crypto?.randomUUID) return `store:${globalThis.crypto.randomUUID()}`;
  return `store:${Date.now().toString(36)}:${Math.random().toString(36).slice(2,14)}`;
}
function cloneObject(value){ return value && typeof value === 'object' ? JSON.parse(JSON.stringify(value)) : value; }
function formatNumber(value){ return Number(value || 0).toLocaleString('ru-RU'); }
function escapeHtml(value){ return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }
function escapeAttr(value){ return escapeHtml(value); }
