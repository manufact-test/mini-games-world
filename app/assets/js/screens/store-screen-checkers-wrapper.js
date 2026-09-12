import {
  initStoreScreen as initBaseStoreScreen,
  openStoreTab as openBaseStoreTab,
  openStoreSheet as openBaseStoreSheet,
} from './store-screen-intent-wrapper.js?v=19&mvp19_6=accepted-base-preserved';
import { api } from '../api/client.js?v=34';

const STORE_API_REPAIR_HOOK = Symbol.for('mgw.store.checkers-full-store-parity.v4');
let initialized = false;
let latestStoreSnapshot = null;
let checkersEffectObserver = null;

ensureCheckersCosmeticStyles();
ensureCheckersEffectPreviewStyles();
ensureCheckersBoardPiecePolishStyles();
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

  const correctiveHref = new URL('../../css/games/checkers/store-visual-corrective-v3.css?v=1&mvp19_6=manual-review-pass-3', import.meta.url).href;
  const corrective = document.querySelector('link[data-mgw-checkers-store-corrective]');
  if (corrective instanceof HTMLLinkElement) {
    if (corrective.href !== correctiveHref) corrective.href = correctiveHref;
    corrective.dataset.mgwCheckersStoreCorrective = 'mvp19-6-manual-review-pass-3';
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersStoreCorrective = 'mvp19-6-manual-review-pass-3';
  link.href = correctiveHref;
  document.head.appendChild(link);
}

function ensureCheckersEffectPreviewStyles(){
  const href = new URL('../../css/games/checkers/store-effects-live-board-v1.css?v=2&mvp19_6=effects-live-board-only', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-checkers-store-effects-live-board]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersStoreEffectsLiveBoard = 'mvp19-6-effects-live-board-v1';
  link.href = href;
  document.head.appendChild(link);
}

function ensureCheckersBoardPiecePolishStyles(){
  const href = new URL('../../css/games/checkers/store-boards-pieces-polish-v1.css?v=1&mvp19_6=boards-pieces-polish', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-checkers-store-boards-pieces-polish]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersStoreBoardsPiecesPolish = 'mvp19-6-boards-pieces-polish-v1';
  link.href = href;
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
    upgradeCheckersCopy(root);
    upgradeCheckersPieceBranding(root);
    removeInlineCheckersBundle(root);
    makeCheckersEffectsPassive(root);
    upgradeCheckersBundleVisuals(root);
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

function upgradeCheckersCopy(root){
  root.querySelectorAll('.store-v2-game-product[data-store-game-product="checkers"]').forEach(product => {
    if (!(product instanceof HTMLElement)) return;
    const preview = product.querySelector('.store-v2-game-preview[data-game-type="checkers"]');
    const copy = product.querySelector('.store-v2-game-product-copy p');
    const name = product.querySelector('.store-v2-game-product-copy strong');
    if (!(preview instanceof HTMLElement)) return;
    const layer = String(preview.dataset.cosmeticLayer || '');
    const variant = String(preview.dataset.cosmeticVariant || '');
    if (layer === 'elements' && variant === 'marble') {
      if (copy instanceof HTMLElement) copy.textContent = 'Полированный гранит с мелкой минеральной крошкой';
      if (name instanceof HTMLElement) name.textContent = 'Гранитные шашки';
      preview.setAttribute('aria-label', 'Гранитные шашки');
    }
  });

  root.querySelectorAll('.store-v2-confirm-game .store-v2-game-preview[data-game-type="checkers"][data-cosmetic-layer="elements"][data-cosmetic-variant="marble"]').forEach(preview => {
    if (!(preview instanceof HTMLElement)) return;
    preview.setAttribute('aria-label', 'Гранитные шашки');
    const confirm = preview.closest('.store-v2-confirm');
    const title = confirm?.querySelector('.store-v2-confirm-copy strong');
    if (title instanceof HTMLElement) title.textContent = 'Гранитные шашки';
  });
}

function upgradeCheckersPieceBranding(root){
  root.querySelectorAll('.store-v2-game-preview[data-game-type="checkers"][data-cosmetic-layer="elements"] .store-v2-mini-checkers-pieces>.king b').forEach(brand => {
    if (!(brand instanceof HTMLElement) || brand.dataset.mgwCheckersPieceBrand === '1') return;
    brand.dataset.mgwCheckersPieceBrand = '1';
    brand.classList.add('mgw-checkers-piece-brand');
    brand.innerHTML = '<span class="mgw-checkers-piece-crown">♛</span><span class="mgw-checkers-piece-mark">MG</span>';
  });
}

function removeInlineCheckersBundle(root){
  root.querySelectorAll('[data-mgw-checkers-inline-bundle]').forEach(section => section.remove());
}

function makeCheckersEffectsPassive(root){
  root.querySelectorAll('.store-v2-game-preview[data-game-type="checkers"][data-cosmetic-layer="effect"]').forEach(preview => {
    if (!(preview instanceof HTMLElement)) return;
    preview.removeAttribute('tabindex');
    preview.removeAttribute('title');
    preview.classList.remove('is-playing');
    upgradeCheckersEffectMarkup(preview);
    startPassiveEffectPreview(preview);
  });
}

function upgradeCheckersEffectMarkup(preview){
  const variant = String(preview.dataset.cosmeticVariant || 'move');
  const effect = preview.querySelector('.store-v2-mini-checkers-effect');
  if (!(effect instanceof HTMLElement)) return;
  if (effect.dataset.mgwCheckersFxMarkup === '4') return;
  effect.dataset.mgwCheckersFxMarkup = '4';
  effect.className = `store-v2-mini-checkers-effect checkers-store-fx-${variant}`;
  effect.setAttribute('data-checkers-effect-preview', '');
  effect.setAttribute('aria-hidden', 'true');
  effect.innerHTML = `${checkersEffectBoardMarkup()}<i class="checkers-fx-path"></i><b class="checkers-fx-piece from"></b><b class="checkers-fx-piece target"></b><em class="checkers-fx-impact"></em><u class="checkers-fx-crown">♛</u>`;
}

function checkersEffectBoardMarkup(){
  const cells = Array.from({ length:64 }, (_, cell) => {
    const row = Math.floor(cell / 8);
    const col = cell % 8;
    return `<span class="${(row + col) % 2 ? 'dark' : 'light'}"></span>`;
  }).join('');
  return `<span class="checkers-fx-board">${cells}</span>`;
}

function ensureCheckersEffectObserver(){
  if (checkersEffectObserver || typeof globalThis.IntersectionObserver !== 'function') return checkersEffectObserver;
  checkersEffectObserver = new globalThis.IntersectionObserver(entries => {
    entries.forEach(entry => {
      const preview = entry.target;
      if (!(preview instanceof HTMLElement)) return;
      const visible = entry.isIntersecting && entry.intersectionRatio >= 0.05;
      preview.dataset.mgwCheckersFxVisible = visible ? '1' : '0';
      if (visible) runBoundedEffectPreview(preview);
      else stopPassiveEffectPreview(preview);
    });
  }, { threshold:[0,0.05,0.35,0.7] });
  return checkersEffectObserver;
}

function startPassiveEffectPreview(preview){
  if (globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
    stopPassiveEffectPreview(preview);
    preview.classList.add('is-reduced-preview');
    return;
  }
  preview.classList.remove('is-reduced-preview');
  const observer = ensureCheckersEffectObserver();
  if (observer) {
    if (preview.dataset.mgwCheckersFxObserved !== '1') {
      preview.dataset.mgwCheckersFxObserved = '1';
      observer.observe(preview);
    }
    return;
  }
  preview.dataset.mgwCheckersFxVisible = '1';
  runBoundedEffectPreview(preview);
}

function nextCheckersEffectRunToken(preview){
  const token = Number(preview.dataset.mgwCheckersFxRunToken || 0) + 1;
  preview.dataset.mgwCheckersFxRunToken = String(token);
  return token;
}

function stopPassiveEffectPreview(preview){
  if (!(preview instanceof HTMLElement)) return;
  nextCheckersEffectRunToken(preview);
  preview.classList.remove('is-previewing');
  preview.dataset.mgwCheckersFxBusy = '0';
}

function runBoundedEffectPreview(preview){
  if (!(preview instanceof HTMLElement) || preview.dataset.mgwCheckersFxBusy === '1') return;
  preview.dataset.mgwCheckersFxBusy = '1';
  const runToken = nextCheckersEffectRunToken(preview);
  const isActive = () => preview.isConnected
    && preview.dataset.mgwCheckersFxVisible !== '0'
    && preview.dataset.mgwCheckersFxRunToken === String(runToken)
    && !globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  const finish = () => {
    if (preview.dataset.mgwCheckersFxRunToken !== String(runToken)) return;
    preview.classList.remove('is-previewing');
    preview.dataset.mgwCheckersFxBusy = '0';
  };
  const replay = () => {
    if (!isActive()) {
      finish();
      return;
    }
    preview.classList.remove('is-previewing');
    void preview.offsetWidth;
    preview.classList.add('is-previewing');
    globalThis.setTimeout(() => {
      if (!isActive()) {
        finish();
        return;
      }
      preview.classList.remove('is-previewing');
      globalThis.setTimeout(() => replay(), 260);
    }, 1900);
  };
  replay();
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
        <div class="store-v2-bundle-visual checkers-bundle-visual mgw-checkers-bundle-complete" aria-hidden="true">${checkersBundleVisualContents()}</div>
        <div class="store-v2-bundle-copy">
          <h2>${title}</h2>
          <p>Неоновая доска + неоновые шашки + ход + взятие + дамка.</p>
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

function upgradeCheckersBundleVisuals(root){
  root.querySelectorAll('.checkers-bundle-visual').forEach(visual => {
    if (!(visual instanceof HTMLElement)) return;
    if (visual.dataset.mgwCheckersBundleVisual === '4') return;
    visual.dataset.mgwCheckersBundleVisual = '4';
    visual.classList.add('mgw-checkers-bundle-complete');
    visual.innerHTML = checkersBundleVisualContents();
    visual.setAttribute('aria-hidden', 'true');
  });
}

function checkersBundleVisualContents(){
  return `
    <span class="mgw-checkers-bundle-board"><small class="mgw-checkers-bundle-label">Доска</small>${checkersMiniBoardMarkup(false)}</span>
    <span class="mgw-checkers-bundle-pieces"><small class="mgw-checkers-bundle-label">Шашки</small><i class="store-v2-mini-checkers-pieces"><span class="black"></span><span class="white"></span><span class="king"><b>♛</b></span></i></span>
    <span class="mgw-checkers-bundle-effects">
      ${bundleEffectMarkup('move','Ход')}
      ${bundleEffectMarkup('capture','Взятие')}
      ${bundleEffectMarkup('promotion','Дамка')}
    </span>
  `;
}

function bundleEffectMarkup(variant, label){
  const cells = Array.from({ length:16 }, (_, index) => '<i></i>').join('');
  return `<i class="mgw-checkers-bundle-effect ${variant}"><small class="mgw-checkers-bundle-label">${label}</small><span class="mgw-checkers-bundle-effect-board">${cells}</span></i>`;
}

function checkersMiniBoardMarkup(withStartingPieces){
  const cells = Array.from({ length:64 }, (_, cell) => {
    const row = Math.floor(cell / 8);
    const col = cell % 8;
    const dark = (row + col) % 2 === 1;
    const hasPiece = Boolean(withStartingPieces) && dark && (row < 3 || row > 4);
    const pieceClass = row < 3 ? 'black' : 'white';
    return `<span class="${dark ? 'dark' : 'light'}">${hasPiece ? `<i class="${pieceClass}"></i>` : ''}</span>`;
  }).join('');
  return `<i class="store-v2-mini-checkers-board" aria-hidden="true">${cells}</i>`;
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
