import { api } from '../api/client.js?v=34';
import { state } from '../state.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=27';
import { renderBalances } from '../ui.js?v=89';
import { haptic } from '../telegram/telegram-app.js?v=27';
import { t, formatNumber as formatLocalizedNumber } from '@mgw/i18n';
import { dominoPreviewMarkup, dominoHeaderMarksMarkup } from './store-screen-domino-store-v1.js?v=14&mvp19_9=domino-svg-pips-v48';

const STORE_TABS = Object.freeze([
  { id:'coins', labelKey:'store.tabs.coins', available:false },
  { id:'profile', labelKey:'store.tabs.profile' },
  { id:'games', labelKey:'store.tabs.games' },
  { id:'bundles', labelKey:'store.tabs.bundles' },
]);
const GAME_CATALOG_ORDER = Object.freeze(['tictactoe','chess','checkers','domino']);
const BUNDLE_REFERENCE_GAMES = Object.freeze(['tictactoe','chess','checkers','reversi','go','domino','four_in_a_row','battleship']);

let storeState = null;
let storeSurface = 'tab';
let activeTab = 'profile';
let activeGameCatalog = 'tictactoe';
let activeBundleGame = 'tictactoe';
let storeLoadPromise = null;
let purchaseBusy = false;
let equipBusy = false;
let bundlePreviewResizeBound = false;

ensureBundlePrototypeStyles();

function ensureBundlePrototypeStyles(){
  const href = new URL('../../css/screens/store-bundle-prototype-v1.css?v=12&mvp19_13=bundle-selector-click-hint-v10', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-store-bundle-prototype]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwStoreBundlePrototype = 'mvp19-13-bundle-selector-click-hint-v10';
  link.href = href;
  document.head.appendChild(link);
}

export function initStoreScreen(){
  document.addEventListener('click', event => {
    const trigger = event.target.closest('#storeOpen');
    if (!trigger) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    openStoreTab();
  }, true);

  if (!bundlePreviewResizeBound) {
    bundlePreviewResizeBound = true;
    globalThis.addEventListener?.('resize', () => scheduleBundlePreviewFit(currentRoot()), { passive:true });
  }

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
      if (!purchaseBusy && !equipBusy) applyStoreResponse(result, { preserveBalance:true });
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
    if (!purchaseBusy && !equipBusy) {
      renderStore();
    } else {
      updateVisibleBalance();
    }
  } catch (_) {
    // Keep the already rendered Store snapshot if a background refresh fails.
  }
}

function applyStoreResponse(result, options = {}){
  const incomingStore = result?.store && typeof result.store === 'object' ? result.store : null;
  if (!incomingStore) throw new Error(t('store.errors.incomplete_response'));

  const preserveBalance = options?.preserveBalance === true;
  const currentHasBalance = Boolean(
    state.user
    && typeof state.user === 'object'
    && Object.prototype.hasOwnProperty.call(state.user, 'balance')
  );

  // Read-only Store status is a catalogue/inventory owner, not a wallet owner.
  // During mobile startup it is intentionally warmed under the preloader and can
  // race the already-authoritative bootstrap/profile balance. Keep the current
  // exact wallet value (including a legitimate zero) and normalize the Store
  // snapshot to it. Purchase/equip/unequip responses keep their existing
  // mutation path and may still advance the wallet after a real Store action.
  storeState = preserveBalance && currentHasBalance
    ? { ...incomingStore, balance:state.user.balance }
    : incomingStore;

  if (state.user && typeof state.user === 'object' && (!preserveBalance || !currentHasBalance)) {
    state.user = { ...state.user, balance:Number(storeState.balance || 0) };
    renderBalances(state.user);
  }
  const visibleTabs = storeTabs();
  if (!visibleTabs.some(tab => String(tab.id) === activeTab)) activeTab = String(visibleTabs[0]?.id || 'profile');
  const catalogs = storeState?.games?.catalogs && typeof storeState.games.catalogs === 'object' ? storeState.games.catalogs : {};
  if (!catalogs[activeGameCatalog]) activeGameCatalog = orderedGameCatalogs(catalogs)[0]?.game_type || 'tictactoe';
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
  scheduleBundlePreviewFit(currentRoot());
}

function renderBalanceHero(){
  const balance = storeState?.balance ?? state.user?.balance ?? 0;
  return `
    <section class="store-v2-balance">
      <span>${escapeHtml(t('store.balance.label'))}</span>
      <strong data-store-v2-balance>${formatNumber(balance)} <small>${escapeHtml(t('store.units.coins'))}</small></strong>
    </section>
  `;
}

function updateVisibleBalance(){
  const root = currentRoot();
  const target = root?.querySelector('[data-store-v2-balance]');
  if (!target) return;
  target.innerHTML = `${formatNumber(storeState?.balance ?? state.user?.balance ?? 0)} <small>${escapeHtml(t('store.units.coins'))}</small>`;
}

function storeTabs(){
  const serverTabs = Array.isArray(storeState?.tabs) ? storeState.tabs : [];
  const serverById = new Map(serverTabs.map(tab => [String(tab?.id || ''), tab]));
  return STORE_TABS
    .map(tab => ({ ...tab, ...(serverById.get(tab.id) || {}) }))
    .filter(tab => tab.available !== false);
}

function renderTabs(){
  return `
    <div class="store-v2-tabs" role="tablist" aria-label="${escapeHtml(t('store.tabs.aria'))}">
      ${storeTabs().map(tab => `
        <button class="store-v2-tab ${activeTab === String(tab.id) ? 'active' : ''}" data-store-v2-tab="${escapeAttr(tab.id)}" type="button" role="tab" aria-selected="${activeTab === String(tab.id) ? 'true' : 'false'}">
          ${escapeHtml(tab.labelKey ? t(tab.labelKey) : (tab.label || tab.id))}
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
  return emptyState(t('store.coins.unavailable'));
}

function renderProfileTab(){
  const avatars = Array.isArray(storeState?.profile?.avatars) ? storeState.profile.avatars : [];
  const nameColors = Array.isArray(storeState?.profile?.name_colors) ? storeState.profile.name_colors : [];
  return `
    <div class="store-v2-title-row"><h2>${escapeHtml(t('store.profile.avatars_title'))}</h2></div>
    <div class="store-v2-product-grid">
      ${avatars.map(renderAvatarOffer).join('') || emptyState(t('store.profile.avatars_empty'))}
    </div>
    <section class="store-v2-name-color-section">
      <div class="store-v2-title-row"><h2>${escapeHtml(t('store.profile.name_color_title'))}</h2></div>
      <div class="store-v2-name-color-grid">
        ${nameColors.map(renderNameColorOffer).join('') || emptyState(t('store.profile.name_colors_empty'))}
      </div>
    </section>
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
        ${equipped ? `<i class="store-v2-selected-check" aria-label="${escapeAttr(t('store.actions.selected_feminine'))}">✓</i>` : ''}
      </div>
      <strong class="store-v2-product-name">${escapeHtml(t('store.profile.avatar_name',{number:number || ''}))}</strong>
      <div class="store-v2-product-foot">
        ${owned
          ? `<b>${equipped ? '' : escapeHtml(t('store.actions.purchased'))}</b>`
          : `<b>${formatNumber(offer?.price_coins || 0)}</b><button class="store-v2-buy" data-store-v2-buy="${escapeAttr(offer?.offer_id || '')}" type="button">${escapeHtml(t('store.actions.buy'))}</button>`}
      </div>
    </article>
  `;
}

function renderNameColorOffer(offer){
  const owned = Boolean(offer?.already_owned);
  const equipped = owned && Boolean(offer?.equipped);
  const itemId = String(offer?.item_ids?.[0] || '');
  const slot = String(offer?.equip_slot || 'profile_name_color');
  const title = String(offer?.display_name || itemId || t('store.profile.name_color_title'));
  const nickname = String(state.mgwProfile?.nickname || state.user?.display_name || t('profile.player'));
  const tier = t(`store.profile.name_color_tiers.${({ normal:'normal', rare:'rare', gradient:'gradient' })[String(offer?.metadata?.tier || 'normal')] || 'fallback'}`);
  return `
    <article class="store-v2-name-color-card ${owned ? 'owned' : ''} ${equipped ? 'equipped' : ''}">
      <div class="store-v2-name-color-preview"><strong data-name-color-item-id="${escapeAttr(itemId)}">${escapeHtml(nickname)}</strong>${equipped ? `<i class="store-v2-selected-check" aria-label="${escapeAttr(t('store.actions.selected'))}">✓</i>` : ''}</div>
      <div class="store-v2-name-color-copy"><strong>${escapeHtml(title)}</strong><small>${escapeHtml(tier)}</small></div>
      <div class="store-v2-name-color-foot">
        ${owned
          ? (equipped
            ? `<button class="store-v2-equip active" data-store-v2-unequip="${escapeAttr(slot)}" type="button">${escapeHtml(t('store.actions.remove'))}</button>`
            : `<button class="store-v2-equip" data-store-v2-equip="${escapeAttr(itemId)}" type="button">${escapeHtml(t('store.actions.select'))}</button>`)
          : `<b>${formatNumber(offer?.price_coins || 0)}</b><button class="store-v2-buy" data-store-v2-buy="${escapeAttr(offer?.offer_id || '')}" type="button">${escapeHtml(t('store.actions.buy'))}</button>`}
      </div>
    </article>
  `;
}

function orderedGameCatalogs(catalogs = storeState?.games?.catalogs || {}){
  return Object.values(catalogs || {}).filter(Boolean).sort((left, right) => {
    const li = GAME_CATALOG_ORDER.indexOf(String(left?.game_type || ''));
    const ri = GAME_CATALOG_ORDER.indexOf(String(right?.game_type || ''));
    return (li < 0 ? 999 : li) - (ri < 0 ? 999 : ri) || String(left?.title || '').localeCompare(String(right?.title || ''));
  });
}

function gamePresentation(gameType){
  const key = ['chess','checkers','domino'].includes(gameType) ? gameType : 'tictactoe';
  const marks = { chess:'♞♜', checkers:'●○', domino:'', tictactoe:'✕○' };
  const groupIds = key === 'domino'
    ? [['tables','themes'],['tiles','elements'],['effects','effects']]
    : key === 'checkers'
      ? [['boards','themes'],['pieces','elements'],['effects','effects']]
      : key === 'chess'
        ? [['boards','themes'],['pieces','elements'],['effects','effects']]
        : [['fields','themes'],['marks','elements'],['effects','effects']];
  return {
    mark:marks[key],
    groups:groupIds.map(([group,sourceKey]) => [
      t(`store.games.presentation.${key}.groups.${group}.title`),
      t(`store.games.presentation.${key}.groups.${group}.subtitle`),
      sourceKey,
    ]),
    kinds:{
      theme:t(`store.games.presentation.${key}.kinds.theme`),
      elements:t(`store.games.presentation.${key}.kinds.elements`),
      effect:t(`store.games.presentation.${key}.kinds.effect`),
    },
  };
}
function renderGamesTab(){
  const catalogs = orderedGameCatalogs();
  if (!catalogs.length) return emptyState(t('store.games.empty'));
  let catalog = catalogs.find(item => String(item?.game_type || '') === activeGameCatalog) || catalogs[0];
  activeGameCatalog = String(catalog?.game_type || 'tictactoe');
  const presentation = gamePresentation(activeGameCatalog);
  const selector = catalogs.length > 1 ? `
    <div class="store-v2-game-selector" role="tablist" aria-label="${escapeHtml(t('store.tabs.games'))}">
      ${catalogs.map(item => {
        const gameType = String(item?.game_type || '');
        const active = gameType === activeGameCatalog;
        const label = gameType === 'domino' ? t('games.domino.name') : String(item?.title || gameType);
        return `<button type="button" role="tab" class="store-v2-game-select${active ? ' active' : ''}" data-store-v2-game="${escapeAttr(gameType)}" aria-selected="${active ? 'true' : 'false'}">${escapeHtml(label)}</button>`;
      }).join('')}
    </div>` : '';
  const title = activeGameCatalog === 'domino' ? t('games.domino.name') : String(catalog.title || activeGameCatalog);
  const marks = activeGameCatalog === 'domino'
    ? dominoHeaderMarksMarkup()
    : `<b>${escapeHtml(presentation.mark.slice(0,1))}</b><b>${escapeHtml(presentation.mark.slice(1))}</b>`;
  return `
    ${selector}
    <div class="store-v2-game-head" data-store-game-type="${escapeAttr(activeGameCatalog)}">
      <div>
        <span>${escapeHtml(t('store.games.cosmetics_title'))}</span>
        <h2>${escapeHtml(title)}</h2>
      </div>
      <div class="store-v2-game-head-marks" aria-hidden="true">${marks}</div>
    </div>
    ${presentation.groups.map(([groupTitle, subtitle, key]) => renderGameCosmeticGroup(groupTitle, subtitle, catalog[key], activeGameCatalog)).join('')}
  `;
}

function renderGameCosmeticGroup(title, subtitle, offers, gameType){
  const items = Array.isArray(offers) ? offers : [];
  return `
    <section class="store-v2-game-group">
      <div class="store-v2-title-row store-v2-game-title-row">
        <div><h2>${escapeHtml(title)}</h2>${subtitle ? `<p>${escapeHtml(subtitle)}</p>` : ''}</div>
      </div>
      <div class="store-v2-game-grid">${items.map(offer => renderGameOffer(offer, gameType)).join('')}</div>
    </section>
  `;
}

function renderGameOffer(offer, gameType){
  const owned = Boolean(offer?.already_owned);
  const equipped = owned && Boolean(offer?.equipped);
  const itemId = String(offer?.item_ids?.[0] || '');
  const slot = String(offer?.equip_slot || '');
  const layer = String(offer?.metadata?.layer || 'theme');
  const variant = String(offer?.metadata?.variant || 'base');
  const price = formatNumber(offer?.price_coins || 0);
  const presentation = gamePresentation(gameType);
  const kind = presentation.kinds[layer] || t('store.games.generic_item');
  const description = gameCosmeticDescription(gameType, layer, variant);
  return `
    <article class="store-v2-game-product ${owned ? 'owned' : ''} ${equipped ? 'equipped' : ''}" data-store-game-product="${escapeAttr(gameType)}">
      ${gameCosmeticPreview(gameType, layer, variant, offer?.display_name || '')}
      <div class="store-v2-game-product-copy">
        <span>${escapeHtml(kind)}</span>
        <strong>${escapeHtml(offer?.display_name || itemId)}</strong>
        <p>${escapeHtml(description)}</p>
      </div>
      <div class="store-v2-game-product-foot">
        ${owned
          ? (equipped
            ? `<button class="store-v2-equip active" data-store-v2-unequip="${escapeAttr(slot)}" type="button">${escapeHtml(t('store.actions.remove'))}</button>`
            : `<button class="store-v2-equip" data-store-v2-equip="${escapeAttr(itemId)}" type="button">${escapeHtml(t('store.actions.select'))}</button>`)
          : `<button class="store-v2-buy store-v2-game-buy" data-store-v2-buy="${escapeAttr(offer?.offer_id || '')}" type="button"><span>${escapeHtml(t('store.actions.buy'))}</span><b>${price} ${escapeHtml(t('store.units.coins'))}</b></button>`}
      </div>
    </article>
  `;
}

function gameCosmeticDescription(gameType, layer, variant){
  const key = ['chess','checkers','domino'].includes(gameType) ? gameType : 'tictactoe';
  const normalizedVariant = key === 'tictactoe' && layer === 'effect' ? normalizeEffectVariant(variant) : variant;
  const variantKey = String(normalizedVariant || 'base').replaceAll('-', '_');
  const exactKey = `store.games.descriptions.${key}.${layer}.${variantKey}`;
  try {
    return t(exactKey);
  } catch (_) {
    return t(`store.games.descriptions.${key}.${layer}.fallback`);
  }
}
function normalizeEffectVariant(variant){
  return ({ sign:'impact', 'winning-line':'sparks', 'move-pulse':'wave', 'strike-through':'wave' })[variant] || variant;
}

function checkersMiniBoardMarkup(withStartingPieces = true){
  return `<i class="store-v2-mini-checkers-board" aria-hidden="true">${Array.from({ length:64 }, (_, cell) => {
    const row = Math.floor(cell / 8);
    const col = cell % 8;
    const dark = (row + col) % 2 === 1;
    const hasPiece = withStartingPieces && dark && (row < 3 || row > 4);
    const pieceClass = row < 3 ? 'black' : 'white';
    return `<span class="${dark ? 'dark' : 'light'}">${hasPiece ? `<i class="${pieceClass}"></i>` : ''}</span>`;
  }).join('')}</i>`;
}

function checkersEffectPreviewMarkup(variant){
  const cells = Array.from({ length:64 }, (_, cell) => `<span class="${(Math.floor(cell / 8) + cell % 8) % 2 ? 'dark' : 'light'}"></span>`).join('');
  return `<i class="store-v2-mini-checkers-effect checkers-store-fx-${variant}" data-checkers-effect-preview aria-hidden="true"><span class="checkers-fx-board">${cells}</span><b class="checkers-fx-piece from"></b><b class="checkers-fx-piece target"></b><em class="checkers-fx-impact"></em><u class="checkers-fx-crown">♛</u><small class="checkers-fx-play">▶</small></i>`;
}

function gameCosmeticPreview(gameType, layer, variant, label = ''){
  const safeLayer = ['theme','elements','effect'].includes(String(layer)) ? String(layer) : 'theme';
  const normalizedVariant = gameType === 'tictactoe' && safeLayer === 'effect' ? normalizeEffectVariant(String(variant || 'base')) : String(variant || 'base');
  const safeVariant = normalizedVariant.replace(/[^a-z0-9-]/g, '');
  let content = '';
  if (gameType === 'chess') {
    if (safeLayer === 'theme') {
      const pieces = ['♜','','','♚','','♟','', '', '', '', '♙','', '♔','','','♖'];
      content = `<i class="store-v2-mini-chess-board">${pieces.map(piece => `<span>${piece ? `<b>${piece}</b>` : ''}</span>`).join('')}</i>`;
    } else if (safeLayer === 'elements') {
      content = '<i class="store-v2-mini-chess-pieces"><span>♚</span><span>♞</span><span>♟</span></i>';
    } else {
      content = `<i class="store-v2-mini-chess-effect chess-store-fx-${safeVariant}" aria-hidden="true"><span>♞</span><b></b><em></em></i>`;
    }
  } else if (gameType === 'checkers') {
    if (safeLayer === 'theme') {
      content = checkersMiniBoardMarkup(true);
    } else if (safeLayer === 'elements') {
      content = '<i class="store-v2-mini-checkers-pieces" aria-hidden="true"><span class="black"></span><span class="white"></span><span class="king"><b>♛</b></span></i>';
    } else {
      content = checkersEffectPreviewMarkup(safeVariant);
    }
  } else if (gameType === 'domino') {
    content = dominoPreviewMarkup(safeLayer, safeVariant);
  } else if (safeLayer === 'theme') {
    const marks = ['✕','','○','','○','','✕','','✕'];
    content = `<i class="store-v2-mini-board">${marks.map(mark => `<span>${mark ? `<b>${mark}</b>` : ''}</span>`).join('')}</i>`;
  } else if (safeLayer === 'elements') {
    content = '<i class="store-v2-mini-marks"><span>✕</span><span>○</span></i>';
  } else {
    content = `<i class="store-v2-mini-effect"><span class="ttt-mark ttt-effect-mark ttt-fx-${safeVariant}" aria-hidden="true">✕</span></i>`;
  }
  return `<div class="store-v2-game-preview" data-game-type="${escapeAttr(gameType)}" data-cosmetic-layer="${safeLayer}" data-cosmetic-variant="${safeVariant}" role="img" aria-label="${escapeAttr(label)}">${content}</div>`;
}

function gameBundlesFromSnapshot(snapshot = storeState){
  const gameBundles = Array.isArray(snapshot?.bundles?.game_bundles) ? snapshot.bundles.game_bundles.filter(Boolean) : [];
  if (gameBundles.length) return gameBundles;
  return [snapshot?.bundles?.tictactoe_bundle, snapshot?.bundles?.checkers_bundle].filter(Boolean);
}

function bundleGameType(bundle){
  return String(bundle?.game_type || bundle?.subcategory || '');
}

function bundleMemberOffers(bundle, snapshot = storeState){
  const gameType = bundleGameType(bundle);
  const catalogs = snapshot?.games?.catalogs && typeof snapshot.games.catalogs === 'object' ? snapshot.games.catalogs : {};
  const catalog = catalogs[gameType];
  if (!catalog || typeof catalog !== 'object') return [];
  const memberIds = new Set(Array.isArray(bundle?.item_ids) ? bundle.item_ids.map(String) : []);
  return [catalog.themes, catalog.elements, catalog.effects]
    .flatMap(group => Array.isArray(group) ? group : [])
    .filter(offer => memberIds.has(String(offer?.item_ids?.[0] || '')));
}

function bundlePresentation(gameType){
  const key = BUNDLE_REFERENCE_GAMES.includes(gameType) ? gameType : 'generic';
  return {
    gameTitle:key === 'generic' ? String(gameType || t('store.bundles.generic_game')) : t(`games.${key}.name`),
    description:t(`store.bundles.presentation.${key}.description`),
    labels:{
      theme:t(`store.bundles.presentation.${key}.labels.theme`),
      elements:t(`store.bundles.presentation.${key}.labels.elements`),
      effect:t(`store.bundles.presentation.${key}.labels.effect`),
    },
  };
}
function bundleMemberLabel(gameType, offer){
  const layer = String(offer?.metadata?.layer || '');
  const presentation = bundlePresentation(gameType);
  return presentation.labels[layer] || t('store.bundles.effect');
}

function renderBundleMemberStorePreview(gameType, layer, variant, name, owned, sheet = false){
  const preview = gameCosmeticPreview(gameType, layer, variant, name);
  if (gameType === 'tictactoe') return preview;
  return `
    <div
      class="store-v2-bundle-native-store-viewport ${sheet ? 'is-sheet' : ''}"
      data-store-v2-native-preview-viewport
      data-store-v2-native-preview-layer="${escapeAttr(layer)}"
      data-store-v2-native-preview-game="${escapeAttr(gameType)}"
    >
      <div
        class="store-v2-game-product store-v2-bundle-native-store-product ${owned ? 'owned' : ''}"
        data-store-game-product="${escapeAttr(gameType)}"
        data-store-v2-native-preview-source
        aria-hidden="true"
      >
        ${preview}
      </div>
    </div>
  `;
}

function renderBundleMembers(bundle, sheet = false){
  const gameType = bundleGameType(bundle) || 'tictactoe';
  const missing = new Set(Array.isArray(bundle?.missing_item_ids) ? bundle.missing_item_ids.map(String) : []);
  const members = bundleMemberOffers(bundle);
  return `
    <div class="store-v2-bundle-reference-members ${sheet ? 'is-sheet' : ''}">
      ${members.map(offer => {
        const itemId = String(offer?.item_ids?.[0] || '');
        const owned = itemId !== '' && !missing.has(itemId);
        const layer = String(offer?.metadata?.layer || 'theme');
        const variant = String(offer?.metadata?.variant || 'base');
        const name = String(offer?.display_name || itemId || t('store.games.generic_item'));
        return `
          <div
            class="store-v2-bundle-reference-member layer-${escapeAttr(layer)} ${owned ? 'owned' : ''}"
            data-store-bundle-member-game="${escapeAttr(gameType)}"
            data-store-bundle-member-layer="${escapeAttr(layer)}"
          >
            <div class="store-v2-bundle-reference-preview">
              ${renderBundleMemberStorePreview(gameType, layer, variant, name, owned, sheet)}
              ${owned ? `<i class="store-v2-bundle-owned-check" aria-label="${escapeAttr(t('store.bundles.already_in_collection'))}">✓</i>` : ''}
            </div>
            <div class="store-v2-bundle-reference-member-copy">
              <span>${escapeHtml(bundleMemberLabel(gameType, offer))}</span>
              <strong>${escapeHtml(name)}</strong>
            </div>
          </div>
        `;
      }).join('')}
    </div>
  `;
}

function renderBundlesTab(){
  const bundles = gameBundlesFromSnapshot().filter(bundle => BUNDLE_REFERENCE_GAMES.includes(bundleGameType(bundle)));
  if (!bundles.length) return emptyState(t('store.bundles.empty'));

  const availableGames = bundles.map(bundle => bundleGameType(bundle)).filter(Boolean);
  if (!availableGames.includes(activeBundleGame)) activeBundleGame = availableGames[0] || 'tictactoe';
  return `
    <div class="store-v2-bundle-game-picker" aria-label="${escapeHtml(t('store.bundles.choose_game'))}">
      <div class="store-v2-bundle-game-picker-track" role="tablist">
        ${bundles.map(bundle => {
          const gameType = bundleGameType(bundle);
          const presentation = bundlePresentation(gameType);
          const active = gameType === activeBundleGame;
          return `
            <button
              class="store-v2-bundle-game-option ${active ? 'active' : ''}"
              type="button"
              role="tab"
              aria-selected="${active ? 'true' : 'false'}"
              data-store-v2-bundle-game="${escapeAttr(gameType)}"
            >
              <span>${escapeHtml(presentation.gameTitle)}</span>
            </button>
          `;
        }).join('')}
      </div>
    </div>
    <div class="store-v2-bundle-reference-list" data-store-v2-bundle-stage="${escapeAttr(activeBundleGame)}">
      ${bundles.map(bundle => {
        const gameType = bundleGameType(bundle);
        const active = gameType === activeBundleGame;
        return `
          <div
            class="store-v2-bundle-reference-panel ${active ? 'active' : ''}"
            data-store-v2-bundle-panel="${escapeAttr(gameType)}"
            data-store-v2-bundle-hydrated="${active ? '1' : '0'}"
            aria-hidden="${active ? 'false' : 'true'}"
          >
            ${active ? renderGameBundle(bundle) : ''}
          </div>
        `;
      }).join('')}
    </div>
  `;
}

function renderGameBundle(bundle){
  const gameType = bundleGameType(bundle) || 'tictactoe';
  const itemCount = Array.isArray(bundle?.item_ids) ? bundle.item_ids.length : 0;
  const missing = Number(bundle?.missing_count || 0);
  const owned = Number(bundle?.owned_count || 0);
  const allOwned = Boolean(bundle?.already_owned);
  const currentPrice = Number(bundle?.price_coins || 0);
  const regularMissingPrice = regularBundlePrice(bundle);
  const regularFullPrice = Number(bundle?.regular_price_coins || regularMissingPrice || 0);
  const saving = Math.max(0, regularMissingPrice - currentPrice);
  const title = String(bundle?.display_name || t('store.bundles.default_title'));
  const presentation = bundlePresentation(gameType);
  const progress = allOwned
    ? t('store.bundles.progress_complete',{owned:itemCount || 5,total:itemCount || 5})
    : (owned > 0
      ? t('store.bundles.progress_partial',{owned,total:itemCount || 5,missing})
      : t('store.bundles.progress_new',{total:itemCount || 5}));
  return `
    <article class="store-v2-bundle-reference ${allOwned ? 'owned' : ''}" data-store-bundle-game="${escapeAttr(gameType)}">
      <div class="store-v2-bundle-reference-topline">
        <span>${escapeHtml(presentation.gameTitle)}</span>
        <b>${escapeHtml(t('store.bundles.premium'))}</b>
      </div>
      <div class="store-v2-bundle-reference-hero">
        <div>
          <h2>${escapeHtml(title)}</h2>
          <p>${escapeHtml(presentation.description)}</p>
        </div>
        <em>${itemCount || 5}</em>
      </div>
      ${renderBundleMembers(bundle)}
      <div class="store-v2-bundle-reference-progress">
        <span>${escapeHtml(progress)}</span>
        <i><b style="width:${Math.max(0, Math.min(100, ((itemCount || 5) ? owned / (itemCount || 5) * 100 : 0)))}%"></b></i>
      </div>
      ${allOwned ? '' : `
        <div class="store-v2-bundle-reference-pricing">
          <div>
            <span>${escapeHtml(owned > 0 ? t('store.bundles.remaining_price') : t('store.bundles.bundle_price'))}</span>
            <strong>${formatNumber(currentPrice)} <small>${escapeHtml(t('store.units.coins'))}</small></strong>
          </div>
          <div class="store-v2-bundle-reference-saving">
            ${regularMissingPrice > currentPrice ? `<s>${formatNumber(regularMissingPrice)}</s><b>${escapeHtml(t('store.bundles.saving',{saving:formatNumber(saving)}))}</b>` : ''}
            ${owned === 0 && regularFullPrice > 0 ? `<small>${escapeHtml(t('store.bundles.separate_total',{total:formatNumber(regularFullPrice)}))}</small>` : ''}
          </div>
        </div>
        <button class="store-v2-bundle-reference-buy" data-store-v2-buy="${escapeAttr(bundle?.offer_id || '')}" type="button">
          <span>${escapeHtml(t('store.bundles.view_buy'))}</span>
          <b>→</b>
        </button>
        <small class="store-v2-bundle-reference-note">${escapeHtml(t('store.bundles.no_auto_equip'))}</small>
      `}
    </article>
  `;
}

function regularBundlePrice(bundle){
  return Number(bundle?.regular_missing_price_coins || bundle?.regular_price_coins || 0);
}

function renderBundleConfirmVisual(bundle){
  const owned = Number(bundle?.owned_count || 0);
  const missing = Number(bundle?.missing_count || 0);
  const itemCount = Array.isArray(bundle?.item_ids) ? bundle.item_ids.length : 0;
  const gameType = bundleGameType(bundle) || 'tictactoe';
  const members = gameType === 'checkers'
    ? '<div class="store-v2-bundle-confirm-clone-host" data-store-v2-bundle-confirm-clone></div>'
    : renderBundleMembers(bundle, true);
  return `
    <div class="store-v2-bundle-confirm-reference">
      <div class="store-v2-bundle-confirm-reference-head">
        <span>${escapeHtml(t('store.bundles.contents'))}</span>
        <b>${escapeHtml(owned > 0 ? t('store.bundles.confirm_progress',{missing,owned}) : t('store.bundles.item_count',{count:itemCount || 5}))}</b>
      </div>
      ${members}
      <p>${escapeHtml(t('store.bundles.confirm_note'))}</p>
    </div>
  `;
}

function renderBundleConfirmPricing(bundle){
  const current = Number(bundle?.price_coins || 0);
  const regular = regularBundlePrice(bundle);
  const saving = Math.max(0, regular - current);
  return `
    <div class="store-v2-bundle-confirm-price">
      <div><span>${escapeHtml(t('store.bundles.separately'))}</span><s>${formatNumber(regular)}</s></div>
      <div><span>${escapeHtml(t('store.purchase.to_pay'))}</span><strong>${formatNumber(current)} ${escapeHtml(t('store.units.coins'))}</strong></div>
      ${saving > 0 ? `<p>${escapeHtml(t('store.bundles.you_save',{saving:formatNumber(saving)}))}</p>` : ''}
    </div>
  `;
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

function bindBundleGamePickerScroll(root){
  root.querySelectorAll('.store-v2-bundle-game-picker-track').forEach(track => {
    if (!(track instanceof HTMLElement) || track.dataset.mgwBundlePickerBound === '1') return;
    track.dataset.mgwBundlePickerBound = '1';

    const picker = track.closest('.store-v2-bundle-game-picker');
    let drag = null;

    const updateAffordance = () => {
      if (!(picker instanceof HTMLElement)) return;
      const maxScroll = Math.max(0, track.scrollWidth - track.clientWidth);
      const canScrollLeft = track.scrollLeft > 3;
      const canScrollRight = track.scrollLeft < maxScroll - 3;
      picker.classList.toggle('can-scroll-left', canScrollLeft);
      picker.classList.toggle('can-scroll-right', canScrollRight);
      if (canScrollRight && !picker.dataset.mgwBundleHintShown) {
        picker.dataset.mgwBundleHintShown = '1';
        picker.classList.add('show-scroll-hint');
        globalThis.setTimeout?.(() => picker.classList.remove('show-scroll-hint'), 3600);
      }
    };

    const finishDrag = event => {
      if (!drag || (event && event.pointerId !== drag.pointerId)) return;
      if (drag.moved) {
        track.dataset.mgwBundlePickerSuppressClickUntil = String(Date.now() + 260);
      }
      track.classList.remove('is-dragging');
      try {
        if (track.hasPointerCapture?.(drag.pointerId)) track.releasePointerCapture(drag.pointerId);
      } catch (_) {}
      drag = null;
      updateAffordance();
    };

    track.addEventListener('pointerdown', event => {
      if (event.pointerType !== 'mouse' || event.button !== 0) return;
      drag = {
        pointerId:event.pointerId,
        startX:event.clientX,
        startY:event.clientY,
        startScrollLeft:track.scrollLeft,
        axis:null,
        moved:false,
        captured:false,
      };
    });

    track.addEventListener('pointermove', event => {
      if (!drag || event.pointerId !== drag.pointerId) return;
      const dx = event.clientX - drag.startX;
      const dy = event.clientY - drag.startY;
      if (drag.axis === null) {
        if (Math.max(Math.abs(dx), Math.abs(dy)) < 8) return;
        drag.axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
      }
      if (drag.axis !== 'x') return;

      if (!drag.captured) {
        drag.captured = true;
        try { track.setPointerCapture?.(event.pointerId); } catch (_) {}
      }
      drag.moved = true;
      track.classList.add('is-dragging');
      track.scrollLeft = drag.startScrollLeft - dx;
      updateAffordance();
      if (event.cancelable) event.preventDefault();
    }, { passive:false });

    track.addEventListener('pointerup', finishDrag);
    track.addEventListener('pointercancel', finishDrag);

    track.addEventListener('click', event => {
      const suppressUntil = Number(track.dataset.mgwBundlePickerSuppressClickUntil || 0);
      if (!Number.isFinite(suppressUntil) || Date.now() >= suppressUntil) return;
      event.preventDefault();
      event.stopImmediatePropagation();
    }, true);

    track.addEventListener('wheel', event => {
      if (track.scrollWidth <= track.clientWidth) return;
      const delta = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.deltaY;
      if (!delta) return;
      const before = track.scrollLeft;
      track.scrollLeft += delta;
      updateAffordance();
      if (track.scrollLeft !== before && event.cancelable) event.preventDefault();
    }, { passive:false });

    track.addEventListener('scroll', updateAffordance, { passive:true });
    globalThis.requestAnimationFrame?.(updateAffordance);
  });
}

function centerBundlePickerOption(panel, gameType){
  const track = panel?.querySelector('.store-v2-bundle-game-picker-track');
  const button = panel?.querySelector(`[data-store-v2-bundle-game="${CSS.escape(gameType)}"]`);
  if (!(track instanceof HTMLElement) || !(button instanceof HTMLElement)) return;
  const left = button.offsetLeft - Math.max(0, (track.clientWidth - button.offsetWidth) / 2);
  track.scrollTo({ left:Math.max(0, left), behavior:'smooth' });
}

function bindPanelEvents(root){
  bindBundleGamePickerScroll(root);
  root.querySelectorAll('[data-store-v2-game]:not([data-store-v2-bundle-game])').forEach(button => {
    button.addEventListener('click', () => activateGameCatalog(String(button.dataset.storeV2Game || '')));
  });
  root.querySelectorAll('[data-store-v2-bundle-game]').forEach(button => {
    button.addEventListener('click', () => activateBundleGame(String(button.dataset.storeV2BundleGame || '')));
  });
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
      void equipStoreItem(itemId);
    });
  });
  root.querySelectorAll('[data-store-v2-unequip]').forEach(button => {
    button.addEventListener('click', () => {
      const slot = String(button.dataset.storeV2Unequip || '');
      if (!slot || button.disabled) return;
      void unequipStoreSlot(slot);
    });
  });
}

function activateStoreTab(nextTab){
  if (!storeTabs().some(tab => String(tab.id) === nextTab) || nextTab === activeTab) return;
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
  scheduleBundlePreviewFit(panel);
}

function activateGameCatalog(gameType){
  const catalogs = storeState?.games?.catalogs || {};
  if (!gameType || !catalogs[gameType] || gameType === activeGameCatalog) return;
  activeGameCatalog = gameType;
  haptic('light');
  const root = currentRoot();
  const panel = root?.querySelector('[data-store-v2-panel="games"]');
  if (!panel) return;
  panel.innerHTML = renderGamesTab();
  bindPanelEvents(panel);
}

function activateBundleGame(gameType){
  const bundles = gameBundlesFromSnapshot().filter(bundle => BUNDLE_REFERENCE_GAMES.includes(bundleGameType(bundle)));
  if (!gameType || !bundles.some(bundle => bundleGameType(bundle) === gameType) || gameType === activeBundleGame) return;
  activeBundleGame = gameType;
  haptic('light');
  const root = currentRoot();
  const panel = root?.querySelector('[data-store-v2-panel="bundles"]');
  if (!panel) return;

  const targetBundle = bundles.find(bundle => bundleGameType(bundle) === activeBundleGame) || null;
  const targetPanel = panel.querySelector(`[data-store-v2-bundle-panel="${CSS.escape(activeBundleGame)}"]`);
  if (targetBundle && targetPanel instanceof HTMLElement && targetPanel.dataset.storeV2BundleHydrated !== '1') {
    targetPanel.innerHTML = renderGameBundle(targetBundle);
    targetPanel.dataset.storeV2BundleHydrated = '1';
    bindPanelEvents(targetPanel);
  }

  panel.querySelectorAll('[data-store-v2-bundle-game]').forEach(button => {
    const active = String(button.dataset.storeV2BundleGame || '') === activeBundleGame;
    button.classList.toggle('active', active);
    button.setAttribute('aria-selected', active ? 'true' : 'false');
  });

  const stage = panel.querySelector('[data-store-v2-bundle-stage]');
  if (stage instanceof HTMLElement) stage.dataset.storeV2BundleStage = activeBundleGame;

  panel.querySelectorAll('[data-store-v2-bundle-panel]').forEach(bundlePanel => {
    if (!(bundlePanel instanceof HTMLElement)) return;
    const active = String(bundlePanel.dataset.storeV2BundlePanel || '') === activeBundleGame;
    bundlePanel.classList.toggle('active', active);
    bundlePanel.setAttribute('aria-hidden', active ? 'false' : 'true');
  });
  centerBundlePickerOption(panel, activeBundleGame);
  scheduleBundlePreviewFit(targetPanel instanceof HTMLElement ? targetPanel : panel);
}

function hydrateCheckersBundleConfirmFromVisibleCard(){
  const sheet = document.getElementById('sheet');
  const target = sheet?.querySelector('[data-store-v2-bundle-confirm-clone]');
  const root = currentRoot();
  const source = root?.querySelector(
    '[data-store-v2-bundle-panel="checkers"] .store-v2-bundle-reference-members'
  );
  if (!(target instanceof HTMLElement) || !(source instanceof HTMLElement)) return false;

  const rect = source.getBoundingClientRect();
  if (!rect.width || !rect.height) return false;

  const clone = source.cloneNode(true);
  if (!(clone instanceof HTMLElement)) return false;
  clone.classList.remove('is-sheet');
  clone.classList.add('store-v2-bundle-confirm-cloned-members');
  clone.dataset.mgwCheckersFrozenSnapshot = '1';
  clone.querySelectorAll('[id]').forEach(node => node.removeAttribute('id'));
  clone.querySelectorAll('.is-previewing,.is-playing').forEach(node => {
    node.classList.remove('is-previewing','is-playing');
  });
  clone.querySelectorAll('[data-mgw-checkers-fx-observed],[data-mgw-checkers-fx-visible],[data-mgw-checkers-fx-busy]').forEach(node => {
    node.removeAttribute('data-mgw-checkers-fx-observed');
    node.removeAttribute('data-mgw-checkers-fx-visible');
    node.removeAttribute('data-mgw-checkers-fx-busy');
  });

  const sourceWidth = Math.round(rect.width * 100) / 100;
  const sourceHeight = Math.round(rect.height * 100) / 100;
  clone.style.width = `${sourceWidth}px`;
  clone.style.maxWidth = 'none';
  clone.style.position = 'absolute';
  clone.style.left = '0';
  clone.style.top = '0';
  clone.style.transformOrigin = '0 0';

  target.dataset.storeV2BundleConfirmSnapshot = 'checkers';
  target.style.position = 'relative';
  target.style.overflow = 'hidden';
  target.replaceChildren(clone);

  const fitSnapshot = () => {
    const available = target.clientWidth;
    if (!available) return;
    const scale = Math.min(1, available / sourceWidth);
    clone.style.transform = `scale(${scale})`;
    target.style.height = `${Math.ceil(sourceHeight * scale)}px`;
  };

  fitSnapshot();
  globalThis.requestAnimationFrame?.(() => {
    fitSnapshot();
    globalThis.requestAnimationFrame?.(fitSnapshot);
  });
  return true;
}

function fitBundleNativePreviews(root){
  if (!(root instanceof HTMLElement)) return;
  root.querySelectorAll('[data-store-v2-native-preview-viewport]').forEach(viewport => {
    if (!(viewport instanceof HTMLElement)) return;
    const bundlePanel = viewport.closest('[data-store-v2-bundle-panel]');
    if (bundlePanel instanceof HTMLElement && !bundlePanel.classList.contains('active')) return;
    const source = viewport.querySelector('[data-store-v2-native-preview-source]');
    if (!(source instanceof HTMLElement)) return;
    const viewportWidth = viewport.clientWidth;
    const viewportHeight = viewport.clientHeight;
    const sourceWidth = source.offsetWidth;
    const sourceHeight = source.offsetHeight;
    if (!viewportWidth || !viewportHeight || !sourceWidth || !sourceHeight) return;
    const scale = Math.max(.35, Math.min(1.16, viewportWidth / sourceWidth, viewportHeight / sourceHeight));
    source.style.setProperty('--mgw-bundle-native-scale', scale.toFixed(4));
  });
}

function scheduleBundlePreviewFit(root){
  if (!(root instanceof HTMLElement)) return;
  const fit = () => fitBundleNativePreviews(root);
  queueMicrotask(fit);
  if (typeof globalThis.requestAnimationFrame === 'function') {
    globalThis.requestAnimationFrame(fit);
  } else {
    globalThis.setTimeout(fit, 0);
  }
}

function findOffer(offerId){
  return offersFromSnapshot(storeState).find(item => String(item?.offer_id || '') === offerId) || null;
}

function offersFromSnapshot(snapshot){
  const catalogs = snapshot?.games?.catalogs && typeof snapshot.games.catalogs === 'object' ? Object.values(snapshot.games.catalogs) : [];
  const gameOffers = catalogs.flatMap(catalog => [catalog?.themes, catalog?.elements, catalog?.effects].flatMap(group => Array.isArray(group) ? group : []));
  const offers = [
    ...(Array.isArray(snapshot?.profile?.avatars) ? snapshot.profile.avatars : []),
    ...(Array.isArray(snapshot?.profile?.name_colors) ? snapshot.profile.name_colors : []),
    ...gameOffers,
  ];
  const avatarBundle = snapshot?.bundles?.avatar_bundle;
  if (avatarBundle) offers.push(avatarBundle);
  gameBundlesFromSnapshot(snapshot).forEach(bundle => {
    if (!offers.some(candidate => String(candidate?.offer_id || '') === String(bundle?.offer_id || ''))) offers.push(bundle);
  });
  return offers;
}

function isKnownSelectableSlot(slot){
  const normalized = String(slot || '');
  if (!normalized || !storeState) return false;
  return offersFromSnapshot(storeState).some(candidate => {
    if (String(candidate?.equip_slot || '') !== normalized) return false;
    const itemType = String(candidate?.item_type || '');
    const itemFamily = String(candidate?.item_family || '');
    if (itemType === 'game' && normalized.startsWith('game_')) return true;
    return itemType === 'profile' && itemFamily === 'name_color' && normalized === 'profile_name_color';
  });
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

  const bundles = [next?.bundles?.avatar_bundle, ...gameBundlesFromSnapshot(next)].filter(Boolean);
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
  const isNameColor = String(offer.item_family || '') === 'name_color';
  const number = Number(offer.preview_number || 0);
  const itemId = Array.isArray(offer.item_ids) ? String(offer.item_ids[0] || '') : '';
  const balance = Number(storeState?.balance || 0);
  const price = Number(offer.price_coins || 0);
  const missing = Math.max(0, price - balance);
  const bundleGameType = String(offer?.game_type || offer?.subcategory || '');
  const title = isBundle
    ? String(offer.display_name || (bundleGameType === 'checkers' ? t('store.bundles.checkers_default_title') : t('store.bundles.default_title')))
    : (isAvatar ? t('store.profile.avatar_name',{number}) : String(offer.display_name || (isNameColor ? t('store.profile.name_color_title') : t('store.games.generic_item'))));
  let visual;
  if (isBundle) {
    visual = renderBundleConfirmVisual(offer);
  } else if (isAvatar) {
    visual = `<div class="store-v2-confirm-avatar store-v2-avatar-preview" data-avatar-item-id="${escapeAttr(itemId)}" data-avatar-preview="${number}" role="img" aria-label="${escapeAttr(t('store.profile.avatar_name',{number}))}"><span>${String(number).padStart(2,'0')}</span></div>`;
  } else if (isNameColor) {
    const nickname = String(state.mgwProfile?.nickname || state.user?.display_name || t('profile.player'));
    visual = `<div class="profile-v2-name-color-preview-wrap"><strong data-name-color-item-id="${escapeAttr(itemId)}">${escapeHtml(nickname)}</strong></div>`;
  } else {
    const gameType = String(offer?.metadata?.game_type || offer?.subcategory || 'tictactoe');
    visual = `<div class="store-v2-confirm-game">${gameCosmeticPreview(gameType, offer?.metadata?.layer, offer?.metadata?.variant, title)}</div>`;
  }

  openSheet(`
    <div class="sheet-head"><div><h2>${escapeHtml(t('store.purchase.confirm_title'))}</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="store-v2-confirm ${isBundle ? 'store-v2-confirm-bundle-detail' : ''}">
      ${visual}
      <div class="store-v2-confirm-copy"><strong>${escapeHtml(title)}</strong></div>
      ${isBundle
        ? renderBundleConfirmPricing(offer)
        : `<div class="store-v2-confirm-price"><span>${escapeHtml(t('store.purchase.to_pay'))}</span><strong>${formatNumber(price)} ${escapeHtml(t('store.units.coins'))}</strong></div>`}
      <div class="store-v2-confirm-balance"><span>${escapeHtml(t('store.purchase.remaining'))}</span><b>${formatNumber(Math.max(0, balance - price))}</b></div>
      <button class="btn primary full" id="storeV2ConfirmBuy" type="button" ${missing > 0 ? 'disabled' : ''}>${escapeHtml(missing > 0 ? t('store.purchase.missing',{count:formatNumber(missing)}) : t('store.purchase.buy_for',{count:formatNumber(price)}))}</button>
    </div>
  `);

  const sheetElement = document.getElementById('sheet');
  const confirmElement = sheetElement?.querySelector('.store-v2-confirm-bundle-detail');
  if (sheetElement instanceof HTMLElement) sheetElement.scrollTop = 0;
  if (confirmElement instanceof HTMLElement) confirmElement.scrollTop = 0;

  if (isBundle && bundleGameType === 'checkers') {
    hydrateCheckersBundleConfirmFromVisibleCard();
  } else {
    scheduleBundlePreviewFit(sheetElement);
  }

  globalThis.requestAnimationFrame?.(() => {
    if (sheetElement instanceof HTMLElement) sheetElement.scrollTop = 0;
    if (confirmElement instanceof HTMLElement) confirmElement.scrollTop = 0;
  });

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
    const isNameColor = String(offer?.item_family || '') === 'name_color';
    toast(String(offer.offer_type || '') === 'bundle'
      ? t('store.purchase.success_bundle')
      : (isAvatar ? t('store.purchase.success_avatar') : (isNameColor ? t('store.purchase.success_name_color') : t('store.purchase.success_item'))));
  } catch (error) {
    storeState = previousStoreState;
    state.user = previousUser;
    state.profileInventory = previousProfileInventory;
    if (state.user) renderBalances(state.user);
    renderStore();
    haptic('error');
    toast(error?.message || t('store.errors.purchase'));
  } finally {
    purchaseBusy = false;
  }
}

async function equipStoreItem(itemId){
  if (equipBusy || !storeState) return;
  const previousStoreState = cloneObject(storeState);
  const next = cloneObject(storeState);
  const target = offersFromSnapshot(next).find(candidate => String(candidate?.item_ids?.[0] || '') === itemId);
  const slot = String(target?.equip_slot || '');
  if (!target || !target.already_owned || !slot || !isKnownSelectableSlot(slot)) return;

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
    toast(t('store.actions.selected_toast'));
  } catch (error) {
    storeState = previousStoreState;
    renderStore();
    haptic('error');
    toast(error?.message || t('store.errors.select'));
  } finally {
    equipBusy = false;
  }
}

async function unequipStoreSlot(slot){
  if (equipBusy || !storeState || !isKnownSelectableSlot(slot)) return;
  const previousStoreState = cloneObject(storeState);
  const next = cloneObject(storeState);

  equipBusy = true;
  offersFromSnapshot(next).forEach(candidate => {
    if (String(candidate?.equip_slot || '') === slot) candidate.equipped = false;
  });
  next.inventory ||= { items:[], equipped:{} };
  next.inventory.equipped ||= {};
  delete next.inventory.equipped[slot];
  (next.inventory.items || []).forEach(item => {
    if (String(item?.equip_slot || '') === slot) item.equipped = false;
  });
  storeState = next;
  renderStore();

  try {
    const result = await api.cosmeticStoreUnequip(slot);
    applyStoreResponse(result);
    renderStore();
    haptic('success');
    toast(t('store.actions.removed_toast'));
  } catch (error) {
    storeState = previousStoreState;
    renderStore();
    haptic('error');
    toast(error?.message || t('store.errors.remove'));
  } finally {
    equipBusy = false;
  }
}

function renderStoreHead(){
  if (storeSurface === 'tab') {
    return `<div class="page-head app-shell-page-head store-tab-head"><div><h1 class="page-title">${escapeHtml(t('shell.store_title'))}</h1></div></div>`;
  }
  return `<div class="sheet-head"><div><h2>${escapeHtml(t('shell.store_title'))}</h2></div><button class="close" data-close-sheet type="button">×</button></div>`;
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
    <div class="store-v2-empty error"><strong>${escapeHtml(t('store.errors.unavailable'))}</strong><span>${escapeHtml(error?.message || t('store.errors.retry'))}</span></div>
    <button class="btn ghost full" id="storeV2Retry" type="button">${escapeHtml(t('common.retry'))}</button>
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
function formatNumber(value){ return formatLocalizedNumber(Number(value || 0)); }
function formatEuro(cents){ return formatLocalizedNumber(Number(cents || 0) / 100, { style:'currency', currency:'EUR' }); }
function escapeHtml(value){
  return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
}
function escapeAttr(value){ return escapeHtml(value); }
