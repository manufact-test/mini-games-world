import { api } from '../api/client.js?v=47';
import { state } from '../state.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=27';
import { renderBalances } from '../ui.js?v=89';
import { haptic } from '../telegram/telegram-app.js?v=27';

const REACTION_SLOT = 'profile_reaction_set';
const REACTION_CODES = Object.freeze({
  wave:{ glyph:'👋', label:'Привет' },
  clap:{ glyph:'👏', label:'Браво' },
  heart:{ glyph:'💜', label:'Сердце' },
  fire:{ glyph:'🔥', label:'Огонь' },
  target:{ glyph:'🎯', label:'Точно' },
  spark:{ glyph:'✨', label:'Вау' },
  crown:{ glyph:'👑', label:'Корона' },
  handshake:{ glyph:'🤝', label:'Хорошая игра' },
});

let initialized = false;
let observer = null;
let scheduled = false;
let refreshPromise = null;
let initialSnapshotAttempted = false;
let busy = false;
let paletteOpen = false;
let lastReactionSeq = 0;
let reactionHideTimer = null;

export function initMgwProfileReactions(){
  if (initialized) return;
  initialized = true;

  const start = () => {
    observer?.disconnect();
    observer = new MutationObserver(scheduleDecorate);
    observer.observe(document.body, { childList:true, subtree:true });
    document.addEventListener('mgw:cosmetic-inventory-changed', scheduleDecorate);
    document.addEventListener('mgw:screen-changed', () => { paletteOpen = false; scheduleDecorate(); });
    document.addEventListener('mgw:game-reaction', event => showReaction(event.detail?.reaction || null));
    document.addEventListener('click', handleOutsideClick, true);
    scheduleDecorate();
    void ensureSnapshot();
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
  const catalog = reactionCatalog();
  if (catalog.length) {
    renderStoreSection(catalog);
    renderProfileCollection(catalog);
  }
  renderGameComposer();
}

function ensureSnapshot(){
  if (reactionCatalog().length || initialSnapshotAttempted) return Promise.resolve(state.profileInventory);
  initialSnapshotAttempted = true;
  return refreshSnapshot();
}

function refreshSnapshot(){
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

function reactionCatalog(){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const catalog = Array.isArray(inventory?.catalog) ? inventory.catalog : [];
  return catalog
    .filter(item => item
      && item.item_type === 'profile'
      && item.item_family === 'reaction'
      && item.equip_slot === REACTION_SLOT
      && String(item.catalog_status || '') === 'active')
    .map(item => ({ ...item, item_id:String(item.item_id || '') }))
    .sort((left, right) => reactionSort(left) - reactionSort(right));
}

function reactionSort(item){
  const id = String(item?.item_id || '');
  return ['profile-reaction-wave','profile-reaction-clap','profile-reaction-heart','profile-reaction-fire','profile-reaction-pack-4','profile-reaction-pack-large'].indexOf(id);
}

function currentItemId(){
  return String(state.profileInventory?.equipped?.[REACTION_SLOT] || '').trim();
}

function meta(item){ return item?.metadata && typeof item.metadata === 'object' ? item.metadata : {}; }
function itemName(item){ return String(meta(item).display_name || 'Реакции'); }
function itemSubtitle(item){ return String(meta(item).subtitle || 'Набор реакций'); }
function itemPrice(item){ return Math.max(0, Number(meta(item).price_coins || 0)); }
function itemOfferId(item){ return String(meta(item).offer_id || String(item?.item_id || '').replace(/^profile-/, '')); }
function itemCodes(item){
  const codes = Array.isArray(meta(item).reactions) ? meta(item).reactions : [];
  return codes.map(code => String(code || '').trim().toLowerCase()).filter(code => REACTION_CODES[code]);
}

function previewMarkup(item, compact = false){
  const codes = itemCodes(item);
  return `<span class="mgw-reaction-preview${compact ? ' compact' : ''}">${codes.map(code => `<i title="${escapeAttr(REACTION_CODES[code].label)}">${REACTION_CODES[code].glyph}</i>`).join('')}</span>`;
}

function renderStoreSection(catalog){
  const panel = document.querySelector('.store-v2-content[data-store-v2-panel="profile"]');
  if (!(panel instanceof HTMLElement)) return;
  const active = currentItemId();
  const signature = catalog.map(item => `${item.item_id}:${item.owned === true ? 1 : 0}`).join('|') + `|${active}`;
  let section = panel.querySelector('[data-profile-reaction-store-section]');
  if (section instanceof HTMLElement && section.dataset.profileReactionSignature === signature) return;

  const markup = `
    <section class="store-v2-reaction-section" data-profile-reaction-store-section data-profile-reaction-signature="${escapeAttr(signature)}">
      <div class="store-v2-title-row"><h2>Реакции</h2></div>
      <div class="store-v2-reaction-grid">
        ${catalog.map(item => storeCard(item, active)).join('')}
      </div>
    </section>
  `;
  if (section instanceof HTMLElement) section.outerHTML = markup;
  else panel.insertAdjacentHTML('beforeend', markup);
  section = panel.querySelector('[data-profile-reaction-store-section]');
  bindStoreActions(section);
}

function storeCard(item, activeItemId){
  const itemId = String(item.item_id || '');
  const owned = item.owned === true;
  const active = owned && itemId === activeItemId;
  return `
    <article class="store-v2-reaction-card mgw-profile-cosmetic-card ${owned ? 'owned' : ''} ${active ? 'equipped' : ''}" data-mgw-profile-cosmetic-state="${owned ? (active ? 'selected' : 'owned') : 'available'}">
      <div class="store-v2-reaction-preview">${previewMarkup(item)}${active ? '<i class="store-v2-selected-check" aria-label="Выбран">✓</i>' : ''}</div>
      <div class="store-v2-reaction-copy"><strong>${escapeHtml(itemName(item))}</strong><small>${escapeHtml(itemSubtitle(item))}</small></div>
      <div class="store-v2-reaction-foot mgw-profile-cosmetic-foot">
        ${owned ? `<b>${active ? 'Выбрано' : 'В коллекции'}</b>` : `<b>${formatNumber(itemPrice(item))}</b>`}
        ${owned
          ? (active
            ? '<button class="store-v2-equip active mgw-profile-cosmetic-action" data-reaction-unequip type="button">Снять</button>'
            : `<button class="store-v2-equip mgw-profile-cosmetic-action" data-reaction-equip="${escapeAttr(itemId)}" type="button">Выбрать</button>`)
          : `<button class="store-v2-buy mgw-profile-cosmetic-action" data-reaction-buy="${escapeAttr(itemId)}" type="button">Купить</button>`}
      </div>
    </article>`;
}

function bindStoreActions(section){
  if (!(section instanceof HTMLElement)) return;
  section.querySelectorAll('[data-reaction-buy]').forEach(button => button.addEventListener('click', () => openPurchase(String(button.dataset.reactionBuy || ''))));
  section.querySelectorAll('[data-reaction-equip]').forEach(button => button.addEventListener('click', () => void saveSelection(String(button.dataset.reactionEquip || ''), false)));
  section.querySelectorAll('[data-reaction-unequip]').forEach(button => button.addEventListener('click', () => void saveSelection(currentItemId(), true)));
}

function renderProfileCollection(catalog){
  const collection = document.querySelector('#screen-profile .profile-v2-collection-section');
  if (!(collection instanceof HTMLElement)) return;
  const owned = catalog.filter(item => item.owned === true);
  let section = collection.querySelector('[data-profile-reaction-collection]');
  if (!owned.length) { section?.remove(); return; }

  const active = currentItemId();
  const signature = owned.map(item => item.item_id).join('|') + `|${active}`;
  if (section instanceof HTMLElement && section.dataset.profileReactionSignature === signature) return;
  const markup = `
    <div class="profile-v2-reaction-collection" data-profile-reaction-collection data-profile-reaction-signature="${escapeAttr(signature)}" aria-label="Реакции">
      <div class="profile-v2-collection-title">Реакции</div>
      <div class="profile-v2-reaction-grid">
        ${owned.map(item => profileCard(item, active)).join('')}
      </div>
    </div>`;
  if (section instanceof HTMLElement) section.outerHTML = markup;
  else {
    const gameCollection = collection.querySelector('.profile-v2-game-collection');
    if (gameCollection) gameCollection.insertAdjacentHTML('beforebegin', markup);
    else collection.insertAdjacentHTML('beforeend', markup);
  }
  section = collection.querySelector('[data-profile-reaction-collection]');
  section?.querySelectorAll('[data-reaction-preview]').forEach(button => button.addEventListener('click', () => openPreview(String(button.dataset.reactionPreview || ''))));
}

function profileCard(item, activeItemId){
  const itemId = String(item.item_id || '');
  const active = itemId === activeItemId;
  return `<button class="profile-v2-reaction-card${active ? ' active' : ''}" type="button" data-reaction-preview="${escapeAttr(itemId)}" aria-pressed="${active ? 'true' : 'false'}">
    <span class="profile-v2-reaction-card-preview">${previewMarkup(item, true)}</span>
    <strong>${escapeHtml(itemName(item))}</strong><small>${escapeHtml(itemSubtitle(item))}</small>
    ${active ? '<i class="profile-v2-selected-check" aria-hidden="true">✓</i>' : ''}
  </button>`;
}

function openPurchase(itemId){
  const item = reactionCatalog().find(candidate => candidate.item_id === itemId && candidate.owned !== true);
  if (!item || busy) return;
  const price = itemPrice(item);
  const balance = Number(state.user?.balance || 0);
  const missing = Math.max(0, price - balance);
  openSheet(`
    <div class="sheet-head"><div><h2>Подтвердить покупку</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="store-v2-confirm">
      <div class="mgw-reaction-sheet-preview">${previewMarkup(item)}</div>
      <div class="store-v2-confirm-copy"><strong>${escapeHtml(itemName(item))}</strong><small>${escapeHtml(itemSubtitle(item))}</small></div>
      <div class="store-v2-confirm-price"><span>К оплате</span><strong>${formatNumber(price)} коинов</strong></div>
      <div class="store-v2-confirm-balance"><span>Останется</span><b>${formatNumber(Math.max(0, balance - price))}</b></div>
      <button class="btn primary full" id="mgwReactionConfirmBuy" type="button"${missing > 0 ? ' disabled' : ''}>${missing > 0 ? `Не хватает ${formatNumber(missing)}` : `Купить за ${formatNumber(price)}`}</button>
    </div>`);
  document.getElementById('mgwReactionConfirmBuy')?.addEventListener('click', event => void purchase(item, event.currentTarget));
}

async function purchase(item, button){
  if (busy || !(button instanceof HTMLButtonElement) || button.disabled) return;
  busy = true;
  button.disabled = true;
  try {
    const result = await api.cosmeticStorePurchase(itemOfferId(item), purchaseToken());
    const balance = Number(result?.store?.balance);
    if (Number.isFinite(balance) && state.user && typeof state.user === 'object') {
      state.user = { ...state.user, balance };
      renderBalances(state.user);
    }
    closeSheet();
    await refreshSnapshot();
    toast('Реакции добавлены в коллекцию.');
  } catch (error) {
    toast(error?.message || 'Не удалось купить реакции.');
  } finally {
    busy = false;
  }
}

function openPreview(itemId){
  const item = reactionCatalog().find(candidate => candidate.item_id === itemId && candidate.owned === true);
  if (!item) return;
  const active = itemId === currentItemId();
  openSheet(`
    <div class="sheet-head"><div><h2>${escapeHtml(itemName(item))}</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="mgw-reaction-sheet-preview">${previewMarkup(item)}</div>
    <div class="profile-v2-reaction-preview-meta"><strong>Реакции</strong><small>${escapeHtml(itemSubtitle(item))}</small></div>
    <button class="btn ${active ? 'ghost' : 'primary'} full" id="mgwReactionEquip" type="button">${active ? 'Снять' : 'Выбрать'}</button>`);
  document.getElementById('mgwReactionEquip')?.addEventListener('click', () => void saveSelection(itemId, active));
}

async function saveSelection(itemId, remove){
  if (busy) return;
  const item = reactionCatalog().find(candidate => candidate.item_id === itemId && candidate.owned === true);
  if (!item) return;
  const previous = cloneObject(state.profileInventory);
  busy = true;
  applyOptimistic(itemId, !remove);
  closeSheet();
  scheduleDecorate();
  try {
    haptic('light');
    const result = remove ? await api.profileReactionUnequip() : await api.profileReactionEquip(itemId);
    if (result?.inventory && typeof result.inventory === 'object') state.profileInventory = result.inventory;
    document.dispatchEvent(new CustomEvent('mgw:cosmetic-inventory-changed', { detail:{ equipped:{ ...(state.profileInventory?.equipped || {}) } } }));
    scheduleDecorate();
  } catch (error) {
    state.profileInventory = previous;
    scheduleDecorate();
    toast(error?.message || 'Не удалось изменить набор реакций.');
  } finally {
    busy = false;
  }
}

function applyOptimistic(itemId, selected){
  const inventory = cloneObject(state.profileInventory) || { catalog:[], owned:[], equipped:{} };
  inventory.equipped = { ...(inventory.equipped || {}) };
  if (selected) inventory.equipped[REACTION_SLOT] = itemId;
  else delete inventory.equipped[REACTION_SLOT];
  if (Array.isArray(inventory.catalog)) {
    inventory.catalog = inventory.catalog.map(item => item && item.item_family === 'reaction'
      ? { ...item, equipped:selected && String(item.item_id || '') === itemId }
      : item);
  }
  state.profileInventory = inventory;
}

function equippedItem(){
  const id = currentItemId();
  return id ? reactionCatalog().find(item => item.item_id === id && item.owned === true) || null : null;
}

function renderGameComposer(){
  const row = document.getElementById('playersRow');
  const screen = document.getElementById('screen-game');
  let toolbar = document.getElementById('mgwReactionToolbar');
  const game = state.activeGame;
  const item = equippedItem();
  const codes = item ? itemCodes(item) : [];
  const eligible = row instanceof HTMLElement
    && screen?.classList.contains('active')
    && String(game?.status || '') === 'active'
    && codes.length > 0;
  if (!eligible) { toolbar?.remove(); paletteOpen = false; return; }

  const signature = `${String(game.id || '')}|${String(item.item_id || '')}|${codes.join(',')}|${paletteOpen ? 1 : 0}`;
  if (toolbar instanceof HTMLElement && toolbar.dataset.signature === signature) return;
  const markup = `<div class="mgw-reaction-toolbar" id="mgwReactionToolbar" data-signature="${escapeAttr(signature)}">
    <button class="mgw-reaction-trigger" id="mgwReactionTrigger" type="button" aria-label="Реакции" title="Реакции" aria-expanded="${paletteOpen ? 'true' : 'false'}"><span aria-hidden="true">🙂</span></button>
    ${paletteOpen ? `<div class="mgw-reaction-palette" role="menu" aria-label="Выбрать реакцию">${codes.map(code => `<button type="button" role="menuitem" data-send-reaction="${escapeAttr(code)}" title="${escapeAttr(REACTION_CODES[code].label)}" aria-label="${escapeAttr(REACTION_CODES[code].label)}"><span aria-hidden="true">${REACTION_CODES[code].glyph}</span></button>`).join('')}</div>` : ''}
  </div>`;
  if (toolbar instanceof HTMLElement) toolbar.outerHTML = markup;
  else row.insertAdjacentHTML('afterend', markup);
  toolbar = document.getElementById('mgwReactionToolbar');
  toolbar?.querySelector('#mgwReactionTrigger')?.addEventListener('click', event => {
    event.stopPropagation();
    paletteOpen = !paletteOpen;
    scheduleDecorate();
  });
  toolbar?.querySelectorAll('[data-send-reaction]').forEach(button => button.addEventListener('click', event => {
    event.stopPropagation();
    void sendReaction(String(button.dataset.sendReaction || ''));
  }));
}

async function sendReaction(code){
  const gameId = String(state.activeGame?.id || '');
  if (!gameId || busy || !REACTION_CODES[code]) return;
  busy = true;
  paletteOpen = false;
  scheduleDecorate();
  try {
    haptic('light');
    const result = await api.gameReaction(gameId, code);
    showReaction(result?.reaction || null);
  } catch (error) {
    toast(error?.message || 'Не удалось отправить реакцию.');
  } finally {
    window.setTimeout(() => { busy = false; }, 850);
  }
}

function showReaction(reaction){
  if (!reaction || String(reaction.game_id || '') !== String(state.activeGame?.id || '')) return;
  const seq = Number(reaction.seq || 0);
  if (!Number.isFinite(seq) || seq <= lastReactionSeq) return;
  lastReactionSeq = seq;
  const row = document.getElementById('playersRow');
  const players = Array.isArray(state.activeGame?.players) ? state.activeGame.players : [];
  if (!(row instanceof HTMLElement) || !players.length) return;
  const index = players.findIndex(player => String(player?.id || '') === String(reaction.sender_id || ''));
  const card = index >= 0 ? row.children[index] : null;
  if (!(card instanceof HTMLElement)) return;
  row.querySelectorAll('.mgw-live-reaction').forEach(node => node.remove());

  const bubble = document.createElement('div');
  bubble.className = 'mgw-live-reaction';
  bubble.setAttribute('aria-hidden', 'true');
  bubble.innerHTML = `<span>${escapeHtml(reaction.glyph || REACTION_CODES[String(reaction.code || '')]?.glyph || '✨')}</span>`;

  const avatar = card.querySelector(':scope > .game-player-avatar');
  if (avatar instanceof HTMLElement) {
    const cardRect = card.getBoundingClientRect();
    const avatarRect = avatar.getBoundingClientRect();
    bubble.classList.add('from-avatar');
    bubble.style.setProperty('--mgw-reaction-origin-x', `${Math.round(avatarRect.left - cardRect.left + avatarRect.width / 2)}px`);
    bubble.style.setProperty('--mgw-reaction-origin-y', `${Math.round(avatarRect.top - cardRect.top + avatarRect.height / 2)}px`);
  } else {
    bubble.classList.add('from-card');
  }

  card.append(bubble);
  window.clearTimeout(reactionHideTimer);
  reactionHideTimer = window.setTimeout(() => bubble.remove(), 1800);
}

function handleOutsideClick(event){
  if (!paletteOpen) return;
  const target = event.target instanceof Element ? event.target : null;
  if (target?.closest('#mgwReactionToolbar')) return;
  paletteOpen = false;
  scheduleDecorate();
}

function purchaseToken(){
  return `reaction-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}
function formatNumber(value){ return Math.max(0, Number(value || 0)).toLocaleString('ru-RU'); }
function cloneObject(value){
  if (!value || typeof value !== 'object') return value;
  try { return structuredClone(value); } catch (_) { return JSON.parse(JSON.stringify(value)); }
}
function escapeHtml(value){
  return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
}
function escapeAttr(value){ return escapeHtml(value); }
