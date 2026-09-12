import {
  initStoreScreen as initBaseStoreScreen,
  openStoreTab as openBaseStoreTab,
  openStoreSheet as openBaseStoreSheet,
} from './store-screen-intent-wrapper.js?v=19&mvp19_6=accepted-base-preserved';
import { api } from '../api/client.js?v=34';

const STORE_API_REPAIR_HOOK = Symbol.for('mgw.store.checkers-full-store-parity.v2');
let initialized = false;
let latestStoreSnapshot = null;

ensureCheckersCosmeticStyles();
installStoreApiRepairHooks();

export function initStoreScreen(){
  const result = initBaseStoreScreen();
  upgradeCheckersStorePresentation();
  if (!initialized) {
    initialized = true;
    installStoreRepairIntents();
  }
  return result;
}

export async function openStoreTab(){
  const result = await openBaseStoreTab();
  rememberStoreSnapshot(result);
  upgradeCheckersStorePresentation();
  return result;
}

export async function openStoreSheet(){
  const result = await openBaseStoreSheet();
  rememberStoreSnapshot(result);
  upgradeCheckersStorePresentation();
  return result;
}

function ensureCheckersCosmeticStyles(){
  const existing = document.querySelector('link[data-mgw-checkers-cosmetics]');
  const baseHref = new URL('../../css/games/checkers/cosmetics.css?v=3&mvp19_6=full-store-v1', import.meta.url).href;
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== baseHref) existing.href = baseHref;
  } else {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.dataset.mgwCheckersCosmetics = 'mvp19-6-full-store-v1';
    link.href = baseHref;
    document.head.appendChild(link);
  }

  const correctiveHref = new URL('../../css/games/checkers/store-visual-corrective-v2.css?v=1&mvp19_6=manual-review-pass-1', import.meta.url).href;
  const corrective = document.querySelector('link[data-mgw-checkers-store-corrective]');
  if (corrective instanceof HTMLLinkElement) {
    if (corrective.href !== correctiveHref) corrective.href = correctiveHref;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersStoreCorrective = 'mvp19-6-manual-review-pass-1';
  link.href = correctiveHref;
  document.head.appendChild(link);
}

function normalizeStoreSnapshot(candidate){
  if (!candidate || typeof candidate !== 'object') return null;
  if (candidate.store && typeof candidate.store === 'object') return candidate.store;
  if (candidate.games || candidate.bundles || candidate.profile || candidate.coins) return candidate;
  return null;
}

function rememberStoreSnapshot(candidate){
  const snapshot = normalizeStoreSnapshot(candidate);
  if (snapshot) latestStoreSnapshot = snapshot;
}

function installStoreApiRepairHooks(){
  ['cosmeticStoreStatus','cosmeticStorePurchase','cosmeticStoreEquip','cosmeticStoreUnequip'].forEach(methodName => {
    const current = api?.[methodName];
    if (typeof current !== 'function' || current[STORE_API_REPAIR_HOOK]) return;
    const wrapped = async (...args) => {
      try {
        const result = await current.apply(api, args);
        rememberStoreSnapshot(result);
        return result;
      } finally {
        scheduleCheckersStoreRepair();
      }
    };
    Object.defineProperty(wrapped, STORE_API_REPAIR_HOOK, { value:true });
    api[methodName] = wrapped;
  });
}

function installStoreRepairIntents(){
  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const inlineBundleBuy = target.closest('[data-mgw-checkers-bundle-buy]');
    if (inlineBundleBuy instanceof HTMLButtonElement) {
      event.preventDefault();
      event.stopPropagation();
      bridgeInlineBundlePurchase(inlineBundleBuy);
      return;
    }

    if (!target.closest('[data-store-v2-tab="games"], [data-store-v2-tab="bundles"], [data-store-v2-game], [data-store-v2-buy], #storeV2ConfirmBuy, [data-store-v2-equip], [data-store-v2-unequip]')) return;
    scheduleCheckersStoreRepair();
  });
}

function scheduleCheckersStoreRepair(){
  const repair = () => upgradeCheckersStorePresentation();
  queueMicrotask(repair);
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(repair);
  else globalThis.setTimeout(repair, 0);
}

function upgradeCheckersStorePresentation(){
  const roots = [
    document.querySelector('[data-store-v2-panel="games"]'),
    document.querySelector('[data-store-v2-panel="bundles"]'),
    document.getElementById('sheet'),
  ];
  roots.forEach(root => {
    if (!(root instanceof HTMLElement)) return;
    renameCheckersSelector(root);
    upgradeCheckersHeader(root);
    makeCheckersEffectsPassive(root);
    injectCheckersBundleIntoGame(root);
  });
}

function renameCheckersSelector(root){
  root.querySelectorAll('[data-store-v2-game="checkers"]').forEach(button => {
    if (button instanceof HTMLElement && button.textContent !== 'Шашки') button.textContent = 'Шашки';
  });
}

function upgradeCheckersHeader(root){
  const head = root.querySelector('.store-v2-game-head[data-store-game-type="checkers"]');
  if (!(head instanceof HTMLElement)) return;
  const title = head.querySelector('h2');
  if (title instanceof HTMLElement) title.textContent = 'Шашки';
  const marks = head.querySelectorAll('.store-v2-game-head-marks b');
  marks.forEach((mark, index) => {
    if (!(mark instanceof HTMLElement)) return;
    mark.textContent = '';
    mark.classList.add('checkers-store-head-piece');
    mark.classList.toggle('black', index === 0);
    mark.classList.toggle('white', index === 1);
    mark.setAttribute('aria-hidden', 'true');
  });
}

function makeCheckersEffectsPassive(root){
  root.querySelectorAll('.store-v2-game-preview[data-game-type="checkers"][data-cosmetic-layer="effect"]').forEach(preview => {
    if (!(preview instanceof HTMLElement)) return;
    preview.removeAttribute('role');
    preview.removeAttribute('tabindex');
    preview.removeAttribute('title');
    preview.classList.remove('is-playing','is-reduced-preview');
  });
}

function findCheckersBundle(){
  const snapshot = latestStoreSnapshot;
  if (!snapshot || typeof snapshot !== 'object') return null;
  const direct = snapshot?.bundles?.checkers_bundle;
  if (direct && typeof direct === 'object') return direct;
  const bundles = Array.isArray(snapshot?.bundles?.game_bundles) ? snapshot.bundles.game_bundles : [];
  return bundles.find(bundle => String(bundle?.game_type || bundle?.subcategory || '') === 'checkers'
    || String(bundle?.offer_id || '') === 'checkers-premium-bundle') || null;
}

function injectCheckersBundleIntoGame(root){
  const head = root.querySelector('.store-v2-game-head[data-store-game-type="checkers"]');
  if (!(head instanceof HTMLElement)) return;
  const bundle = findCheckersBundle();
  if (!bundle) return;
  const panel = head.closest('[data-store-v2-panel="games"]') || root;
  if (!(panel instanceof HTMLElement) || panel.querySelector('[data-mgw-checkers-inline-bundle]')) return;

  const section = document.createElement('section');
  section.className = 'store-v2-game-group mgw-checkers-inline-bundle-group';
  section.dataset.mgwCheckersInlineBundle = '1';
  section.innerHTML = inlineBundleMarkup(bundle);
  const groups = panel.querySelectorAll('.store-v2-game-group');
  const lastGroup = groups[groups.length - 1];
  if (lastGroup instanceof HTMLElement) lastGroup.after(section);
  else head.after(section);
}

function inlineBundleMarkup(bundle){
  const allOwned = Boolean(bundle?.already_owned);
  const owned = Number(bundle?.owned_count || 0);
  const missing = Number(bundle?.missing_count || 0);
  const price = Number(bundle?.price_coins || 0);
  const regular = Number(bundle?.regular_missing_price_coins || bundle?.regular_price_coins || 0);
  const saving = Math.max(0, regular - price);
  const offerId = escapeHtml(String(bundle?.offer_id || 'checkers-premium-bundle'));
  const title = escapeHtml(String(bundle?.display_name || 'Неоновый комплект шашек'));
  return `
    <div class="store-v2-title-row store-v2-game-title-row mgw-checkers-bundle-title">
      <div><h2>Набор</h2><p>Неоновая доска, неоновые шашки и все три эффекта одним комплектом</p></div>
    </div>
    <div class="store-v2-game-bundles mgw-checkers-inline-bundle-wrap">
      <article class="store-v2-bundle ${allOwned ? 'owned' : ''}" data-store-bundle-game="checkers">
        ${checkersBundleVisualMarkup()}
        <div class="store-v2-bundle-copy">
          <h2>${title}</h2>
          <p>5 премиальных предметов для шашек.</p>
          ${allOwned ? '<p>Комплект уже собран.</p>' : (owned ? `<p>Осталось ${missing} из 5.</p>` : '')}
          ${!allOwned ? `<div class="store-v2-bundle-price"><strong>${formatNumber(price)} коинов</strong>${saving > 0 ? `<span>−${formatNumber(saving)}</span>` : ''}</div>` : ''}
        </div>
        <button class="btn primary full" data-mgw-checkers-bundle-buy="${offerId}" type="button" ${allOwned ? 'disabled' : ''}>
          ${allOwned ? 'Комплект собран' : `Купить комплект за ${formatNumber(price)}`}
        </button>
      </article>
    </div>
  `;
}

function checkersBundleVisualMarkup(){
  const cells = Array.from({ length:64 }, (_, cell) => {
    const row = Math.floor(cell / 8);
    const col = cell % 8;
    return `<span class="${(row + col) % 2 === 1 ? 'dark' : 'light'}"></span>`;
  }).join('');
  return `
    <div class="store-v2-bundle-visual checkers-bundle-visual" aria-hidden="true">
      <i class="store-v2-mini-checkers-board">${cells}</i>
      <i class="store-v2-mini-checkers-pieces"><span class="black"></span><span class="white"></span></i>
      <b>＋3</b>
    </div>
  `;
}

function bridgeInlineBundlePurchase(button){
  if (button.disabled) return;
  const offerId = String(button.dataset.mgwCheckersBundleBuy || 'checkers-premium-bundle');
  const storeRoot = document.getElementById('storeTabSurface') || document.getElementById('sheet');
  if (!(storeRoot instanceof HTMLElement)) return;
  const bundlesTab = storeRoot.querySelector('[data-store-v2-tab="bundles"]');
  const gamesTab = storeRoot.querySelector('[data-store-v2-tab="games"]');
  if (!(bundlesTab instanceof HTMLButtonElement)) return;

  bundlesTab.click();
  globalThis.requestAnimationFrame?.(() => {
    const actualButton = storeRoot.querySelector(`[data-store-bundle-game="checkers"] [data-store-v2-buy="${offerId}"]`);
    if (actualButton instanceof HTMLButtonElement && !actualButton.disabled) actualButton.click();
    if (gamesTab instanceof HTMLButtonElement && gamesTab.isConnected) gamesTab.click();
    scheduleCheckersStoreRepair();
  });
}

function formatNumber(value){
  return Number(value || 0).toLocaleString('ru-RU');
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#039;');
}
