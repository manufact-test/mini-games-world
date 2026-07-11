import { api } from '../api/client.js?v=31';
import { state } from '../state.js?v=27';
import { openSheet } from '../components/sheet.js?v=27';
import { toast } from '../components/toast.js?v=27';
import { renderBalances } from '../ui.js?v=27';
import { haptic } from '../telegram/telegram-app.js?v=27';

let storeState = null;

export function initStoreScreen(){
  document.addEventListener('click', event => {
    const trigger = event.target.closest('#storeOpen');
    if (!trigger) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    openStoreSheet();
  }, true);
}

export async function openStoreSheet(){
  haptic('light');
  openSheet(`
    <div class="sheet-head">
      <div><h2>Магазин призов</h2><p>Загружаем доступные сертификаты.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="store-loading" aria-live="polite">
      <div class="store-loading-icon">🎁</div>
      <strong>Готовим витрину</strong>
      <span>Проверяем каталог и доступный Gold.</span>
    </div>
  `);

  try {
    const result = await api.shopStatus();
    if (result.user) {
      state.user = result.user;
      renderBalances(state.user);
    }

    storeState = createStoreState(result.shop || {});
    renderStore();
  } catch (error) {
    renderStoreError(error);
  }
}

function createStoreState(shop){
  const items = Array.isArray(shop.items) ? shop.items : [];
  const rawCountries = Array.isArray(shop.countries) ? shop.countries : [];
  const countryCodesWithItems = new Set(items.map(item => String(item.country_code || '')));
  const countries = rawCountries.filter(country => countryCodesWithItems.has(String(country.code || '')));

  const selectedCountry = countries[0]?.code || items[0]?.country_code || '';
  const selectedItem = items.find(item => String(item.country_code || '') === String(selectedCountry)) || items[0] || null;
  const selectedDenomination = pickInitialDenomination(selectedItem, Number(shop.available || 0));

  return {
    shop,
    countries,
    items,
    selectedCountry: String(selectedCountry || ''),
    selectedItemId: String(selectedItem?.id || ''),
    selectedDenominationId: String(selectedDenomination?.id || ''),
  };
}

function pickInitialDenomination(item, available){
  const denominations = Array.isArray(item?.denominations) ? item.denominations : [];
  return denominations.find(option => Number(option.gold_cost || 0) <= available) || denominations[0] || null;
}

function renderStore(){
  if (!storeState) return;

  const { shop, countries, items } = storeState;
  const available = Number(shop.available || 0);
  const balance = Number(shop.balance_gold || 0);
  const filteredItems = items.filter(item => String(item.country_code || '') === storeState.selectedCountry);
  const selectedItem = items.find(item => String(item.id || '') === storeState.selectedItemId) || filteredItems[0] || null;

  if (selectedItem && selectedItem.id !== storeState.selectedItemId) {
    storeState.selectedItemId = String(selectedItem.id || '');
    const denomination = pickInitialDenomination(selectedItem, available);
    storeState.selectedDenominationId = String(denomination?.id || '');
  }

  const selectedDenomination = (selectedItem?.denominations || []).find(option => String(option.id || '') === storeState.selectedDenominationId)
    || pickInitialDenomination(selectedItem, available);

  if (selectedDenomination && selectedDenomination.id !== storeState.selectedDenominationId) {
    storeState.selectedDenominationId = String(selectedDenomination.id || '');
  }

  const storeNote = shop.test_mode
    ? 'Тестовый режим администратора: весь текущий Gold доступен для проверки магазина. Для обычных игроков действует правило отыгрыша Gold в завершённых матчах.'
    : 'В магазине доступен только Gold, который уже участвовал в завершённых Gold-матчах.';

  openSheet(`
    <div class="sheet-head">
      <div><h2>Магазин призов</h2><p>Выберите страну, сертификат и номинал.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="store-scroll">
      <section class="store-balance-card">
        <div>
          <span>Доступно для магазина</span>
          <strong>${formatNumber(available)} Gold</strong>
        </div>
        <div class="store-balance-meta">
          <span>Баланс: ${formatNumber(balance)} Gold</span>
          <span>Минимум: ${formatNumber(shop.min_order || 0)} Gold</span>
        </div>
      </section>

      <div class="store-note">
        ${escapeHtml(storeNote)}
      </div>

      ${items.length ? renderCatalog(countries, filteredItems, selectedItem, selectedDenomination, available) : renderEmptyCatalog()}
    </div>

    ${items.length ? renderSelectionFooter(selectedItem, selectedDenomination, available) : ''}
  `);

  bindStoreEvents();
}

function renderCatalog(countries, filteredItems, selectedItem, selectedDenomination, available){
  return `
    <section class="store-section">
      <div class="store-section-head">
        <span>1</span>
        <div><strong>Страна</strong><small>Покажем доступные варианты для региона</small></div>
      </div>
      <div class="store-country-tabs" role="tablist" aria-label="Выбор страны">
        ${countries.map(country => `
          <button
            class="store-country-tab ${String(country.code) === storeState.selectedCountry ? 'active' : ''}"
            data-store-country="${escapeAttr(country.code)}"
            type="button"
          >${escapeHtml(country.name)}</button>
        `).join('')}
      </div>
    </section>

    <section class="store-section">
      <div class="store-section-head">
        <span>2</span>
        <div><strong>Сертификат</strong><small>Выберите провайдера</small></div>
      </div>
      ${filteredItems.length ? `
        <div class="store-product-grid">
          ${filteredItems.map(item => renderProductCard(item, selectedItem, available)).join('')}
        </div>
      ` : `
        <div class="store-empty compact">
          <div>🗺️</div>
          <strong>Для этой страны пока нет призов</strong>
          <span>Выберите другой регион.</span>
        </div>
      `}
    </section>

    ${selectedItem ? `
      <section class="store-section">
        <div class="store-section-head">
          <span>3</span>
          <div><strong>Номинал</strong><small>Недоступные суммы останутся видны</small></div>
        </div>
        <div class="store-denominations">
          ${(selectedItem.denominations || []).map(option => {
            const cost = Number(option.gold_cost || 0);
            const affordable = available >= cost;
            const active = String(option.id || '') === String(selectedDenomination?.id || '');
            return `
              <button
                class="store-denomination ${active ? 'active' : ''} ${affordable ? '' : 'locked'}"
                data-store-denomination="${escapeAttr(option.id)}"
                type="button"
                aria-pressed="${active ? 'true' : 'false'}"
              >
                <strong>${escapeHtml(option.label || `${formatNumber(cost)} Gold`)}</strong>
                <span>${affordable ? 'Доступно' : `Не хватает ${formatNumber(Math.max(0, cost - available))}`}</span>
              </button>
            `;
          }).join('')}
        </div>
      </section>
    ` : ''}
  `;
}

function renderProductCard(item, selectedItem, available){
  const denominations = Array.isArray(item.denominations) ? item.denominations : [];
  const minCost = denominations.length ? Math.min(...denominations.map(option => Number(option.gold_cost || 0)).filter(cost => cost > 0)) : 0;
  const active = String(item.id || '') === String(selectedItem?.id || '');
  const hasAffordable = denominations.some(option => Number(option.gold_cost || 0) <= available);
  const image = String(item.image || '').trim();
  const initials = providerInitials(item.provider || item.title || 'Prize');

  return `
    <button class="store-product-card ${active ? 'active' : ''}" data-store-item="${escapeAttr(item.id)}" type="button">
      <div class="store-product-visual ${image ? 'has-image' : ''}">
        ${image
          ? `<img src="${escapeAttr(image)}" alt="${escapeAttr(item.image_alt || item.title || '')}" loading="lazy">`
          : `<span>${escapeHtml(initials)}</span>`}
      </div>
      <div class="store-product-copy">
        <small>${escapeHtml(item.country || '')}</small>
        <strong>${escapeHtml(item.title || item.provider || 'Приз')}</strong>
        <p>${escapeHtml(item.description || 'Электронный сертификат.')}</p>
        <div class="store-product-bottom">
          <span>от ${formatNumber(minCost)} Gold</span>
          <em class="${hasAffordable ? 'available' : ''}">${hasAffordable ? 'Можно выбрать' : 'Недостаточно Gold'}</em>
        </div>
      </div>
    </button>
  `;
}

function renderSelectionFooter(item, denomination, available){
  if (!item || !denomination) {
    return `<div class="store-footer"><button class="btn gold full" type="button" disabled>Выберите приз</button></div>`;
  }

  const cost = Number(denomination.gold_cost || 0);
  const affordable = available >= cost;

  return `
    <div class="store-footer">
      <div class="store-selection-summary">
        <div>
          <span>Вы выбрали</span>
          <strong>${escapeHtml(item.provider || item.title || 'Приз')} · ${escapeHtml(denomination.label || `${formatNumber(cost)} Gold`)}</strong>
        </div>
        <b>${formatNumber(cost)} Gold</b>
      </div>
      <button class="btn gold full" id="storeContinue" type="button" ${affordable ? '' : 'disabled'}>
        ${affordable ? 'Продолжить' : `Не хватает ${formatNumber(Math.max(0, cost - available))} Gold`}
      </button>
    </div>
  `;
}

function renderEmptyCatalog(){
  return `
    <div class="store-empty">
      <div>🎁</div>
      <strong>Каталог пока пуст</strong>
      <span>Новые призы появятся здесь, как только будут доступны.</span>
    </div>
  `;
}

function bindStoreEvents(){
  document.querySelectorAll('[data-store-country]').forEach(button => {
    button.addEventListener('click', () => {
      const countryCode = String(button.dataset.storeCountry || '');
      if (!countryCode || !storeState) return;

      storeState.selectedCountry = countryCode;
      const item = storeState.items.find(entry => String(entry.country_code || '') === countryCode) || null;
      storeState.selectedItemId = String(item?.id || '');
      storeState.selectedDenominationId = String(pickInitialDenomination(item, Number(storeState.shop.available || 0))?.id || '');
      haptic('light');
      renderStore();
    });
  });

  document.querySelectorAll('[data-store-item]').forEach(button => {
    button.addEventListener('click', () => {
      if (!storeState) return;
      const item = storeState.items.find(entry => String(entry.id || '') === String(button.dataset.storeItem || '')) || null;
      if (!item) return;

      storeState.selectedItemId = String(item.id || '');
      storeState.selectedDenominationId = String(pickInitialDenomination(item, Number(storeState.shop.available || 0))?.id || '');
      haptic('light');
      renderStore();
    });
  });

  document.querySelectorAll('[data-store-denomination]').forEach(button => {
    button.addEventListener('click', () => {
      if (!storeState) return;
      storeState.selectedDenominationId = String(button.dataset.storeDenomination || '');
      haptic('light');
      renderStore();
    });
  });

  document.getElementById('storeContinue')?.addEventListener('click', () => {
    if (!storeState) return;
    const item = storeState.items.find(entry => String(entry.id || '') === storeState.selectedItemId);
    const denomination = (item?.denominations || []).find(option => String(option.id || '') === storeState.selectedDenominationId);
    if (!item || !denomination) return;

    const available = Number(storeState.shop.available || 0);
    const cost = Number(denomination.gold_cost || 0);
    if (available < cost) {
      toast(`Для этого номинала не хватает ${formatNumber(cost - available)} Gold.`);
      return;
    }

    haptic('light');
    toast('Выбор сохранён. Безопасное оформление заявки подключается следующим этапом.');
  });
}

function renderStoreError(error){
  openSheet(`
    <div class="sheet-head">
      <div><h2>Магазин призов</h2><p>Не удалось загрузить витрину.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="store-empty error">
      <div>⚠️</div>
      <strong>Магазин временно недоступен</strong>
      <span>${escapeHtml(error?.message || 'Попробуйте открыть магазин ещё раз.')}</span>
    </div>
    <button class="btn ghost full" id="storeRetry" type="button">Попробовать снова</button>
  `);

  document.getElementById('storeRetry')?.addEventListener('click', openStoreSheet);
}

function providerInitials(value){
  return String(value || 'P')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map(part => part.charAt(0).toUpperCase())
    .join('') || 'P';
}

function formatNumber(value){
  return Number(value || 0).toLocaleString('ru-RU');
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function escapeAttr(value){
  return escapeHtml(value);
}
