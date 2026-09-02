import { api } from '../api/client.js?v=34';
import { state } from '../state.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=27';
import { renderBalances } from '../ui.js?v=89';

const BADGE_SLOT = 'profile_badge';
const BADGE_ITEM_IDS = Object.freeze([
  'profile-badge-spark',
  'profile-badge-crest',
  'profile-badge-pulse',
]);

let initialized = false;
let observer = null;
let scheduled = false;
let refreshPromise = null;
let initialSnapshotAttempted = false;
let badgeBusy = false;

export function initMgwProfileBadges(){
  if (initialized) return;
  initialized = true;

  const start = () => {
    observer?.disconnect();
    observer = new MutationObserver(scheduleDecorate);
    observer.observe(document.body, { childList:true, subtree:true });
    document.addEventListener('mgw:cosmetic-inventory-changed', scheduleDecorate);
    scheduleDecorate();
    void ensureBadgeSnapshot();
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
  const catalog = badgeCatalog();
  if (!catalog.length) return;
  decorateChrome();
  decoratePlayersRow();
  renderStoreBadgeSection(catalog);
  renderProfileBadgeCollection(catalog);
}

function ensureBadgeSnapshot(){
  if (badgeCatalog().length || initialSnapshotAttempted) return Promise.resolve(state.profileInventory);
  initialSnapshotAttempted = true;
  return refreshBadgeSnapshot();
}

function refreshBadgeSnapshot(){
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

function badgeCatalog(){
  const inventory = state.profileInventory && typeof state.profileInventory === 'object' ? state.profileInventory : null;
  const catalog = Array.isArray(inventory?.catalog) ? inventory.catalog : [];
  return catalog
    .filter(item => item
      && item.item_type === 'profile'
      && item.item_family === 'badge'
      && item.equip_slot === BADGE_SLOT
      && String(item.catalog_status || '') === 'active')
    .map(item => ({ ...item, item_id:String(item.item_id || '') }))
    .filter(item => BADGE_ITEM_IDS.includes(item.item_id))
    .sort((left, right) => BADGE_ITEM_IDS.indexOf(left.item_id) - BADGE_ITEM_IDS.indexOf(right.item_id));
}

function currentBadgeItemId(){
  const equipped = state.profileInventory && typeof state.profileInventory === 'object' && state.profileInventory.equipped && typeof state.profileInventory.equipped === 'object'
    ? state.profileInventory.equipped
    : {};
  const itemId = String(equipped[BADGE_SLOT] || '').trim();
  return BADGE_ITEM_IDS.includes(itemId) ? itemId : '';
}

function badgeMeta(item){
  return item?.metadata && typeof item.metadata === 'object' ? item.metadata : {};
}

function badgeName(item){
  return String(badgeMeta(item).display_name || item?.item_id || 'Бейдж');
}

function badgeTierLabel(item){
  const tier = String(badgeMeta(item).tier || 'normal');
  return ({ normal:'Обычный', rare:'Редкий', animated:'Анимированный' })[tier] || 'Бейдж';
}

function badgePrice(item){
  return Math.max(0, Number(badgeMeta(item).price_coins || 0));
}
function badgeOfferId(item){
  return String(badgeMeta(item).offer_id || String(item?.item_id || '').replace(/^profile-/, ''));
}

function decorateChrome(){
  const itemId = currentBadgeItemId();
  clearLegacyNameBadge(document.getElementById('topName'));
  clearLegacyNameBadge(document.getElementById('profileName'));
  clearLegacyNameBadge(document.getElementById('searchMeName'));
  clearLegacyNameBadge(document.querySelector('#screen-profile .profile-v2-person > strong'));

  applyAvatarBadge(document.getElementById('topAvatar'), itemId);
  applyAvatarBadge(document.getElementById('profileAvatar'), itemId);
  applyAvatarBadge(document.getElementById('profileV2Avatar'), itemId);
  applyAvatarBadge(document.getElementById('searchMeAvatar'), itemId);
}

function decoratePlayersRow(){
  const row = document.getElementById('playersRow');
  const players = Array.isArray(state.activeGame?.players) ? state.activeGame.players : [];
  if (!row || !players.length) return;
  [...row.children].forEach((card, index) => {
    if (!(card instanceof HTMLElement)) return;
    clearLegacyNameBadge(card.querySelector(':scope > .name'));
    const avatar = card.querySelector(':scope > .game-player-avatar');
    if (!(avatar instanceof HTMLElement)) return;
    const itemId = String(players[index]?.badge_item_id || '').trim().toLowerCase();
    applyAvatarBadge(avatar, BADGE_ITEM_IDS.includes(itemId) ? itemId : '');
  });
}

function clearLegacyNameBadge(element){
  if (!(element instanceof HTMLElement)) return;
  delete element.dataset.profileBadgeItemId;
}

function applyAvatarBadge(element, itemId){
  if (!(element instanceof HTMLElement)) return;
  const normalized = BADGE_ITEM_IDS.includes(String(itemId || '').trim().toLowerCase())
    ? String(itemId || '').trim().toLowerCase()
    : '';
  if (normalized) {
    if (element.dataset.profileBadgeAvatarItemId !== normalized) element.dataset.profileBadgeAvatarItemId = normalized;
  } else if (element.dataset.profileBadgeAvatarItemId) {
    delete element.dataset.profileBadgeAvatarItemId;
  }
}

function renderStoreBadgeSection(catalog){
  const panel = document.querySelector('.store-v2-content[data-store-v2-panel="profile"]');
  if (!(panel instanceof HTMLElement)) return;
  const active = currentBadgeItemId();
  const signature = catalog.map(item => `${item.item_id}:${item.owned === true ? 1 : 0}`).join('|') + `|${active}`;
  let section = panel.querySelector('[data-profile-badge-store-section]');
  if (section instanceof HTMLElement && section.dataset.profileBadgeSignature === signature) return;

  const markup = `
    <section class="store-v2-profile-badge-section" data-profile-badge-store-section data-profile-badge-signature="${escapeAttr(signature)}">
      <div class="store-v2-title-row"><h2>Бейджи</h2></div>
      <div class="store-v2-profile-badge-grid">
        ${catalog.map(item => storeBadgeCard(item, active)).join('')}
      </div>
    </section>
  `;

  if (section instanceof HTMLElement) section.outerHTML = markup;
  else panel.insertAdjacentHTML('beforeend', markup);
  section = panel.querySelector('[data-profile-badge-store-section]');
  bindStoreBadgeActions(section);
}

function storeBadgeCard(item, activeItemId){
  const itemId = String(item.item_id || '');
  const owned = item.owned === true;
  const active = owned && itemId === activeItemId;
  return `
    <article class="store-v2-profile-badge-card ${owned ? 'owned' : ''} ${active ? 'equipped' : ''}">
      <div class="store-v2-profile-badge-preview"><strong data-profile-badge-item-id="${escapeAttr(itemId)}">Mini Games</strong>${active ? '<i class="store-v2-selected-check" aria-label="Выбран">✓</i>' : ''}</div>
      <div class="store-v2-profile-badge-copy"><strong>${escapeHtml(badgeName(item))}</strong><small>${escapeHtml(badgeTierLabel(item))}</small></div>
      <div class="store-v2-profile-badge-foot">
        ${owned
          ? (active
            ? `<button class="store-v2-equip active" data-profile-badge-unequip type="button">Снять</button>`
            : `<button class="store-v2-equip" data-profile-badge-equip="${escapeAttr(itemId)}" type="button">Выбрать</button>`)
          : `<b>${formatNumber(badgePrice(item))}</b><button class="store-v2-buy" data-profile-badge-buy="${escapeAttr(itemId)}" type="button">Купить</button>`}
      </div>
    </article>
  `;
}

function bindStoreBadgeActions(section){
  if (!(section instanceof HTMLElement)) return;
  section.querySelectorAll('[data-profile-badge-buy]').forEach(button => {
    button.addEventListener('click', () => openBadgePurchase(String(button.dataset.profileBadgeBuy || '')));
  });
  section.querySelectorAll('[data-profile-badge-equip]').forEach(button => {
    button.addEventListener('click', () => void saveBadge(String(button.dataset.profileBadgeEquip || ''), false));
  });
  section.querySelectorAll('[data-profile-badge-unequip]').forEach(button => {
    button.addEventListener('click', () => void saveBadge(currentBadgeItemId(), true));
  });
}

function renderProfileBadgeCollection(catalog){
  const collection = document.querySelector('#screen-profile .profile-v2-collection-section');
  if (!(collection instanceof HTMLElement)) return;
  const owned = catalog.filter(item => item.owned === true);
  let section = collection.querySelector('[data-profile-badge-collection]');
  if (!owned.length) {
    section?.remove();
    return;
  }

  const active = currentBadgeItemId();
  const signature = owned.map(item => item.item_id).join('|') + `|${active}`;
  if (section instanceof HTMLElement && section.dataset.profileBadgeSignature === signature) return;
  const markup = `
    <div class="profile-v2-badge-collection" data-profile-badge-collection data-profile-badge-signature="${escapeAttr(signature)}" aria-label="Бейджи">
      <div class="profile-v2-collection-title">Бейджи</div>
      <div class="profile-v2-badge-grid">
        ${owned.map(item => profileBadgeCard(item, active)).join('')}
      </div>
    </div>
  `;

  if (section instanceof HTMLElement) {
    section.outerHTML = markup;
  } else {
    const gameCollection = collection.querySelector('.profile-v2-game-collection');
    const nameColors = collection.querySelector('.profile-v2-name-color-collection');
    if (gameCollection) gameCollection.insertAdjacentHTML('beforebegin', markup);
    else if (nameColors) nameColors.insertAdjacentHTML('afterend', markup);
    else collection.insertAdjacentHTML('beforeend', markup);
  }
  section = collection.querySelector('[data-profile-badge-collection]');
  section?.querySelectorAll('[data-profile-badge-preview]').forEach(button => {
    button.addEventListener('click', () => openBadgePreview(String(button.dataset.profileBadgePreview || '')));
  });
}
function profileBadgeCard(item, activeItemId){
  const itemId = String(item.item_id || '');
  const active = itemId === activeItemId;
  return `<button class="profile-v2-badge-card${active ? ' active' : ''}" type="button" data-profile-badge-preview="${escapeAttr(itemId)}" aria-label="${escapeAttr(badgeName(item))}" aria-pressed="${active ? 'true' : 'false'}"><strong data-profile-badge-item-id="${escapeAttr(itemId)}">Mini Games</strong><small>${escapeHtml(badgeName(item))}</small>${active ? '<i class="profile-v2-selected-check" aria-hidden="true">✓</i>' : ''}</button>`;
}

function openBadgePurchase(itemId){
  const item = badgeCatalog().find(candidate => candidate.item_id === itemId && candidate.owned !== true);
  if (!item || badgeBusy) return;
  const price = badgePrice(item);
  const balance = Number(state.user?.balance || 0);
  const missing = Math.max(0, price - balance);
  const disabled = missing > 0 ? ' disabled' : '';
  const label = missing > 0 ? 'Не хватает ' + formatNumber(missing) : 'Купить за ' + formatNumber(price);
  openSheet(
    '<div class="sheet-head"><div><h2>Подтвердить покупку</h2></div><button class="close" data-close-sheet type="button">×</button></div>' +
    '<div class="store-v2-confirm">' +
      '<div class="profile-v2-badge-preview-wrap"><strong data-profile-badge-item-id="' + escapeAttr(itemId) + '">Mini Games</strong></div>' +
      '<div class="store-v2-confirm-copy"><strong>' + escapeHtml(badgeName(item)) + '</strong></div>' +
      '<div class="store-v2-confirm-price"><span>К оплате</span><strong>' + formatNumber(price) + ' коинов</strong></div>' +
      '<div class="store-v2-confirm-balance"><span>Останется</span><b>' + formatNumber(Math.max(0, balance - price)) + '</b></div>' +
      '<button class="btn primary full" id="mgwProfileBadgeConfirmBuy" type="button"' + disabled + '>' + escapeHtml(label) + '</button>' +
    '</div>'
  );
  document.getElementById('mgwProfileBadgeConfirmBuy')?.addEventListener('click', event => {
    void purchaseBadge(item, event.currentTarget);
  });
}

async function purchaseBadge(item, button){
  if (badgeBusy || !(button instanceof HTMLButtonElement) || button.disabled) return;
  badgeBusy = true;
  button.disabled = true;
  try {
    const result = await api.cosmeticStorePurchase(badgeOfferId(item), purchaseToken());
    const nextBalance = Number(result?.store?.balance);
    if (Number.isFinite(nextBalance) && state.user && typeof state.user === 'object') {
      state.user = { ...state.user, balance:nextBalance };
      renderBalances(state.user);
    }
    closeSheet();
    await refreshBadgeSnapshot();
    toast('Бейдж добавлен в коллекцию.');
  } catch (error) {
    toast(error?.message || 'Не удалось купить бейдж.');
  } finally {
    badgeBusy = false;
  }
}

function openBadgePreview(itemId){
  const item = badgeCatalog().find(candidate => candidate.item_id === itemId && candidate.owned === true);
  if (!item) return;
  const active = itemId === currentBadgeItemId();
  openSheet(`
    <div class="sheet-head"><div><h2>${escapeHtml(badgeName(item))}</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="profile-v2-badge-preview-wrap"><strong data-profile-badge-item-id="${escapeAttr(itemId)}">Mini Games</strong></div>
    <div class="profile-v2-badge-preview-meta"><strong>Бейдж</strong><small>${escapeHtml(badgeTierLabel(item))}</small></div>
    <button class="btn ${active ? 'ghost' : 'primary'} full" id="mgwProfileBadgeEquip" type="button">${active ? 'Снять' : 'Выбрать'}</button>
  `);
  document.getElementById('mgwProfileBadgeEquip')?.addEventListener('click', () => void saveBadge(itemId, active));
}

async function saveBadge(itemId, remove){
  if (badgeBusy) return;
  const item = badgeCatalog().find(candidate => candidate.item_id === itemId && candidate.owned === true);
  const active = itemId === currentBadgeItemId();
  if (!item || (remove && !active) || (!remove && active)) return;

  const previousInventory = cloneObject(state.profileInventory);
  badgeBusy = true;
  applyOptimisticBadge(itemId, !remove);
  closeSheet();
  scheduleDecorate();
  try {
    if (remove) await api.cosmeticStoreUnequip(BADGE_SLOT);
    else await api.cosmeticStoreEquip(itemId);
    await refreshBadgeSnapshot();
    toast(remove ? 'Бейдж снят.' : 'Бейдж выбран.');
  } catch (error) {
    state.profileInventory = previousInventory;
    scheduleDecorate();
    toast(error?.message || (remove ? 'Не удалось снять бейдж.' : 'Не удалось выбрать бейдж.'));
  } finally {
    badgeBusy = false;
  }
}

function applyOptimisticBadge(itemId, equipped){
  const inventory = cloneObject(state.profileInventory) || { catalog:[], owned:[], equipped:{} };
  if (!inventory.equipped || typeof inventory.equipped !== 'object') inventory.equipped = {};
  if (equipped) inventory.equipped[BADGE_SLOT] = itemId;
  else delete inventory.equipped[BADGE_SLOT];
  if (Array.isArray(inventory.catalog)) {
    inventory.catalog = inventory.catalog.map(item => {
      if (!item || String(item.equip_slot || '') !== BADGE_SLOT) return item;
      return { ...item, equipped:equipped && String(item.item_id || '') === itemId };
    });
  }
  state.profileInventory = inventory;
  document.dispatchEvent(new CustomEvent('mgw:cosmetic-inventory-changed', { detail:{ slot:BADGE_SLOT } }));
}

function purchaseToken(){
  if (globalThis.crypto?.randomUUID) return `store:${globalThis.crypto.randomUUID()}`;
  return `store:${Date.now().toString(36)}:${Math.random().toString(36).slice(2,14)}`;
}

function cloneObject(value){ return value && typeof value === 'object' ? JSON.parse(JSON.stringify(value)) : value; }
function formatNumber(value){ return Number(value || 0).toLocaleString('ru-RU'); }
function escapeHtml(value){ return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }
function escapeAttr(value){ return escapeHtml(value); }