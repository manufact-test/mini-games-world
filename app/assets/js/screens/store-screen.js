import { api } from '../api/client.js?v=34';
import { state } from '../state.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=27';
import { renderBalances } from '../ui.js?v=89';
import { haptic } from '../telegram/telegram-app.js?v=27';

let storeState = null;
let storeSurface = 'tab';
let activeTab = 'profile';
let storeLoading = false;
let purchaseBusy = false;

export function initStoreScreen(){
  document.addEventListener('click', event => {
    const trigger = event.target.closest('#storeOpen');
    if (!trigger) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    openStoreTab();
  }, true);
}

export async function openStoreTab(){
  storeSurface = 'tab';
  haptic('light');
  renderStoreLoading();
  await loadStore();
}

export async function openStoreSheet(){
  storeSurface = 'sheet';
  haptic('light');
  renderStoreLoading();
  await loadStore();
}

async function loadStore(){
  if (storeLoading) return;
  storeLoading = true;
  try {
    applyStoreResponse(await api.cosmeticStoreStatus());
    renderStore();
  } catch (error) {
    renderStoreError(error);
  } finally {
    storeLoading = false;
  }
}

function applyStoreResponse(result){
  storeState = result?.store && typeof result.store === 'object' ? result.store : null;
  if (!storeState) throw new Error('Магазин вернул неполный ответ.');
  if (state.user && typeof state.user === 'object') {
    state.user = { ...state.user, balance:Number(storeState.balance || 0) };
    renderBalances(state.user);
  }
  const availableTabs = new Set((storeState.tabs || []).map(tab => String(tab.id || '')));
  if (!availableTabs.has(activeTab)) activeTab = 'profile';
}

function renderStoreLoading(){
  renderStoreSurface(`
    ${renderStoreHead('Косметика и коллекция MGW.')}
    <div class="store-v2-loading" aria-live="polite">
      <div class="store-v2-loading-mark">MG</div>
      <strong>Загружаем магазин</strong>
      <span>Проверяем каталог, покупки и текущий баланс.</span>
    </div>
  `);
}

function renderStore(){
  if (!storeState) return;
  renderStoreSurface(`
    ${renderStoreHead('Косметика и коллекция MGW.')}
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
  return `
    <section class="store-v2-balance">
      <div class="store-v2-balance-copy">
        <span>Ваш баланс</span>
        <strong>${formatNumber(storeState?.balance || 0)} <small>коинов</small></strong>
      </div>
      <div class="store-v2-balance-badge" aria-hidden="true">MG</div>
    </section>
  `;
}

function renderTabs(){
  return `
    <div class="store-v2-tabs" role="tablist" aria-label="Разделы магазина">
      ${(storeState?.tabs || []).map(tab => `
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
    case 'games': return renderFutureTab('Игровая косметика', storeState?.games?.message || 'Игровые предметы появятся позже.');
    case 'bundles': return renderBundlesTab();
    case 'inventory': return renderInventoryTab();
    case 'tournament_rewards': return renderFutureTab('Турнирные награды', storeState?.tournament_rewards?.message || 'Награды появятся вместе с турнирами.');
    default: return renderProfileTab();
  }
}

function renderCoinsTab(){
  const packages = Array.isArray(storeState?.coins?.packages) ? storeState.coins.packages : [];
  return `
    ${sectionHead('Коины', 'Пакеты пополнения уже зафиксированы в экономике. Реальная оплата подключается на платформенном этапе.')}
    <div class="store-v2-coin-grid">
      ${packages.map(pkg => `
        <article class="store-v2-coin-card">
          <span>MGW</span>
          <strong>${formatNumber(pkg.coins)} коинов</strong>
          <b>${formatEuro(pkg.price_eur_cents)}</b>
          <button class="btn ghost full" type="button" disabled>Оплата позже</button>
        </article>
      `).join('') || emptyState('Пакеты коинов недоступны', 'Обновите магазин позже.')}
    </div>
  `;
}

function renderProfileTab(){
  const avatars = Array.isArray(storeState?.profile?.avatars) ? storeState.profile.avatars : [];
  return `
    ${sectionHead('Аватары', 'Постоянная косметика профиля. Покупка добавляет предмет в коллекцию и не меняет активный аватар.')}
    <div class="store-v2-product-grid">
      ${avatars.map(renderAvatarOffer).join('') || emptyState('Аватары недоступны', 'Каталог профиля пока пуст.')}
    </div>
    <div class="store-v2-stage-note"><b>Сейчас:</b> numbered preview показывает место будущего арта. Финальные изображения и выбор активного аватара подключаются в профильном cosmetics MVP.</div>
  `;
}

function renderAvatarOffer(offer){
  const owned = Boolean(offer?.already_owned);
  const affordable = Boolean(offer?.affordable);
  const number = Number(offer?.preview_number || 0);
  return `
    <article class="store-v2-product ${owned ? 'owned' : ''}">
      <div class="store-v2-avatar-preview" data-avatar-preview="${number}"><span>${String(number).padStart(2, '0')}</span></div>
      <div class="store-v2-product-copy">
        <small>Профиль · Аватар</small>
        <strong>Аватар ${number || ''}</strong>
        <p>Постоянный предмет аккаунта MGW.</p>
      </div>
      <div class="store-v2-product-foot">
        <b>${owned ? 'В коллекции' : `${formatNumber(offer?.price_coins || 0)} коинов`}</b>
        <button class="store-v2-buy ${owned ? 'owned' : ''}" data-store-v2-buy="${escapeAttr(offer?.offer_id || '')}" type="button" ${owned ? 'disabled' : ''}>
          ${owned ? 'Куплено' : (affordable ? 'Купить' : 'Посмотреть')}
        </button>
      </div>
    </article>
  `;
}

function renderBundlesTab(){
  const bundle = storeState?.bundles?.avatar_bundle;
  if (!bundle) return emptyState('Наборы пока недоступны', 'Новые комплекты появятся здесь.');
  const missing = Number(bundle.missing_count || 0);
  const owned = Number(bundle.owned_count || 0);
  const allOwned = Boolean(bundle.already_owned);
  const regularMissingPrice = missing * 300;
  const saving = Math.max(0, regularMissingPrice - Number(bundle.price_coins || 0));
  return `
    ${sectionHead('Наборы', 'Комплект автоматически исключает уже купленные аватары — повторно за них платить не нужно.')}
    <article class="store-v2-bundle ${allOwned ? 'owned' : ''}">
      <div class="store-v2-bundle-visual" aria-hidden="true">
        ${[4,5,6,7,8].map(number => `<span>${String(number).padStart(2, '0')}</span>`).join('')}
      </div>
      <div class="store-v2-bundle-copy">
        <small>Набор профиля</small>
        <h2>Пять платных аватаров</h2>
        <p>${allOwned ? 'Все пять аватаров уже в вашей коллекции.' : (owned ? `Уже есть: ${owned} · осталось: ${missing}` : 'Все пять launch-аватаров одним комплектом.')}</p>
        ${!allOwned ? `<div class="store-v2-bundle-price"><strong>${formatNumber(bundle.price_coins || 0)} коинов</strong><span>${saving > 0 ? `Экономия ${formatNumber(saving)}` : 'Скидка 20%'}</span></div>` : ''}
      </div>
      <button class="btn primary full" data-store-v2-buy="${escapeAttr(bundle.offer_id || '')}" type="button" ${allOwned ? 'disabled' : ''}>
        ${allOwned ? 'Набор собран' : (bundle.affordable ? 'Купить набор' : 'Посмотреть набор')}
      </button>
    </article>
  `;
}

function renderInventoryTab(){
  const items = Array.isArray(storeState?.inventory?.items) ? storeState.inventory.items : [];
  return `
    ${sectionHead('Инвентарь', 'Все предметы, которые навсегда принадлежат вашему MGW-аккаунту.')}
    <div class="store-v2-inventory-grid">
      ${items.map(item => `
        <article class="store-v2-inventory-item ${item.equipped ? 'equipped' : ''}">
          <div class="store-v2-mini-avatar"><span>${String(item.preview_number || '').padStart(2, '0')}</span></div>
          <div><strong>Аватар ${escapeHtml(item.preview_number || '')}</strong><small>${item.starter ? 'Стартовый' : 'Купленный'}</small></div>
          <b>${item.equipped ? 'Активен' : 'В коллекции'}</b>
        </article>
      `).join('') || emptyState('Инвентарь пуст', 'Полученные предметы появятся здесь.')}
    </div>
    <div class="store-v2-stage-note">Смена активного предмета остаётся в профиле. Store v2 после покупки только добавляет ownership — auto-equip отключён.</div>
  `;
}

function renderFutureTab(title, message){
  return `
    ${sectionHead(title, 'Раздел уже занимает своё постоянное место в Store v2.')}
    ${emptyState('Раздел готов к каталогу', message)}
  `;
}

function sectionHead(title, note){
  return `<div class="store-v2-section-head"><div><h2>${escapeHtml(title)}</h2><p>${escapeHtml(note)}</p></div></div>`;
}

function emptyState(title, text){
  return `<div class="store-v2-empty"><div>MG</div><strong>${escapeHtml(title)}</strong><span>${escapeHtml(text)}</span></div>`;
}

function bindStoreEvents(){
  const root = currentRoot();
  if (!root) return;
  root.querySelectorAll('[data-store-v2-tab]').forEach(button => {
    button.addEventListener('click', () => {
      activeTab = String(button.dataset.storeV2Tab || 'profile');
      haptic('light');
      renderStore();
    });
  });
  root.querySelectorAll('[data-store-v2-buy]').forEach(button => {
    button.addEventListener('click', () => {
      const offer = findOffer(String(button.dataset.storeV2Buy || ''));
      if (!offer || offer.already_owned) return;
      haptic('light');
      openPurchaseConfirm(offer);
    });
  });
}

function findOffer(offerId){
  const avatars = Array.isArray(storeState?.profile?.avatars) ? storeState.profile.avatars : [];
  const avatar = avatars.find(item => String(item.offer_id || '') === offerId);
  if (avatar) return avatar;
  const bundle = storeState?.bundles?.avatar_bundle;
  return String(bundle?.offer_id || '') === offerId ? bundle : null;
}

function openPurchaseConfirm(offer){
  const token = purchaseToken();
  const isBundle = String(offer.offer_type || '') === 'bundle';
  const number = Number(offer.preview_number || 0);
  const balance = Number(storeState?.balance || 0);
  const price = Number(offer.price_coins || 0);
  const missing = Math.max(0, price - balance);
  const title = isBundle ? 'Набор из пяти аватаров' : `Аватар ${number}`;
  const visual = isBundle
    ? `<div class="store-v2-confirm-bundle">${[4,5,6,7,8].map(value => `<span>${String(value).padStart(2,'0')}</span>`).join('')}</div>`
    : `<div class="store-v2-confirm-avatar"><span>${String(number).padStart(2,'0')}</span></div>`;

  openSheet(`
    <div class="sheet-head"><div><h2>Подтвердить покупку</h2><p>Предмет навсегда останется в вашем MGW-аккаунте.</p></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="store-v2-confirm">
      ${visual}
      <div class="store-v2-confirm-copy"><small>${isBundle ? 'Набор профиля' : 'Профиль · Аватар'}</small><strong>${escapeHtml(title)}</strong>${isBundle && Number(offer.owned_count || 0) ? `<p>К покупке: ${Number(offer.missing_count || 0)} недостающих предмета.</p>` : '<p>Постоянный косметический предмет.</p>'}</div>
      <div class="store-v2-confirm-price"><span>К оплате</span><strong>${formatNumber(price)} коинов</strong></div>
      <div class="store-v2-confirm-balance"><span>Баланс после покупки</span><b>${formatNumber(Math.max(0, balance - price))}</b></div>
      <div class="store-v2-confirm-note">Покупка не активирует аватар автоматически. Выбор активного предмета — отдельное действие.</div>
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
  const originalText = button.textContent;
  button.textContent = 'Покупаем…';
  try {
    const result = await api.cosmeticStorePurchase(String(offer.offer_id || ''), token);
    applyStoreResponse(result);
    haptic('success');
    toast('Покупка добавлена в коллекцию.');
    closeSheet();
    renderStore();
  } catch (error) {
    haptic('error');
    toast(error?.message || 'Не удалось выполнить покупку.');
    button.disabled = false;
    button.textContent = originalText;
  } finally {
    purchaseBusy = false;
  }
}

function renderStoreHead(subtitle){
  if (storeSurface === 'tab') {
    return `<div class="page-head app-shell-page-head store-tab-head"><div><h1 class="page-title">Магазин</h1><p class="page-sub">${escapeHtml(subtitle)}</p></div></div>`;
  }
  return `<div class="sheet-head"><div><h2>Магазин</h2><p>${escapeHtml(subtitle)}</p></div><button class="close" data-close-sheet type="button">×</button></div>`;
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
    ${renderStoreHead('Не удалось загрузить каталог.')}
    <div class="store-v2-empty error"><div>!</div><strong>Магазин временно недоступен</strong><span>${escapeHtml(error?.message || 'Попробуйте открыть магазин ещё раз.')}</span></div>
    <button class="btn ghost full" id="storeV2Retry" type="button">Попробовать снова</button>
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

function formatNumber(value){ return Number(value || 0).toLocaleString('ru-RU'); }
function formatEuro(cents){ return new Intl.NumberFormat('ru-RU', { style:'currency', currency:'EUR' }).format(Number(cents || 0) / 100); }
function escapeHtml(value){
  return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
}
function escapeAttr(value){ return escapeHtml(value); }
