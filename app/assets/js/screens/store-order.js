import { api } from '../api/client.js?v=29';
import { state } from '../state.js?v=27';
import { openSheet } from '../components/sheet.js?v=27';
import { toast } from '../components/toast.js?v=27';
import { renderBalances } from '../ui.js?v=27';
import { haptic } from '../telegram/telegram-app.js?v=27';
import { openStoreSheet } from './store-screen.js?v=28';

let preparingOrder = false;
let pendingOrder = null;

export function initStoreOrder(){
  document.addEventListener('click', event => {
    const button = event.target.closest('#storeContinue');
    if (!button) return;

    // Перехватываем старую заглушку MVP-8.2 до её прямого click-handler.
    event.preventDefault();
    event.stopImmediatePropagation();

    if (preparingOrder) return;
    prepareOrderConfirmation(button);
  }, true);
}

async function prepareOrderConfirmation(button){
  const itemId = String(document.querySelector('.store-product-card.active')?.dataset.storeItem || '');
  const denominationId = String(document.querySelector('.store-denomination.active')?.dataset.storeDenomination || '');

  if (!itemId || !denominationId) {
    toast('Сначала выберите приз и номинал.');
    return;
  }

  preparingOrder = true;
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = 'Проверяем выбор...';

  try {
    // Перед подтверждением всегда получаем свежий каталог и доступный Gold.
    const result = await api.shopStatus();
    if (!document.body.contains(button)) return;

    if (result.user) {
      state.user = result.user;
      renderBalances(state.user);
    }

    const shop = result.shop || {};
    const item = (shop.items || []).find(entry => String(entry.id || '') === itemId);
    const denomination = (item?.denominations || []).find(entry => String(entry.id || '') === denominationId);

    if (!item || !denomination) {
      throw new Error('Выбранный приз или номинал больше недоступен. Откройте магазин заново.');
    }

    const cost = Number(denomination.gold_cost || 0);
    const available = Number(shop.available || 0);
    if (cost <= 0) {
      throw new Error('У выбранного номинала некорректная стоимость.');
    }
    if (available < cost) {
      throw new Error(`Для этого заказа не хватает ${formatNumber(cost - available)} Gold.`);
    }

    pendingOrder = {
      itemId,
      denominationId,
      requestToken: createRequestToken(),
      item,
      denomination,
      available,
      cost,
    };

    haptic('light');
    renderOrderConfirmation();
  } catch (error) {
    if (document.body.contains(button)) {
      button.disabled = false;
      button.textContent = originalText || 'Продолжить';
    }
    toast(error?.message || 'Не удалось проверить заказ.');
  } finally {
    preparingOrder = false;
  }
}

function renderOrderConfirmation(){
  if (!pendingOrder) return;

  const { item, denomination, available, cost } = pendingOrder;

  openSheet(`
    <div class="sheet-head">
      <div><h2>Подтвердить заказ</h2><p>Проверьте выбранный приз перед списанием Gold.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="store-order-review">
      <div class="store-order-review-row">
        <span>Страна</span>
        <strong>${escapeHtml(item.country || '—')}</strong>
      </div>
      <div class="store-order-review-row">
        <span>Приз</span>
        <strong>${escapeHtml(item.title || item.provider || 'Приз')}</strong>
      </div>
      <div class="store-order-review-row">
        <span>Номинал</span>
        <strong>${escapeHtml(denomination.label || `${formatNumber(cost)} Gold`)}</strong>
      </div>
      <div class="store-order-review-row total">
        <span>Будет списано</span>
        <strong>${formatNumber(cost)} Gold</strong>
      </div>
    </div>

    <div class="store-order-warning">
      После подтверждения заявка будет создана сразу, а ${formatNumber(cost)} Gold будут списаны с баланса.
      Доступно сейчас: ${formatNumber(available)} Gold.
    </div>

    <div class="store-order-actions">
      <button class="btn ghost full" id="storeOrderBack" type="button">Назад</button>
      <button class="btn gold full" id="storeCreateOrder" type="button">Создать заявку</button>
    </div>
  `);

  document.getElementById('storeOrderBack')?.addEventListener('click', openStoreSheet);
  document.getElementById('storeCreateOrder')?.addEventListener('click', submitPendingOrder);
}

async function submitPendingOrder(){
  if (!pendingOrder) return;

  const button = document.getElementById('storeCreateOrder');
  if (!button || button.disabled) return;

  const request = pendingOrder;
  button.disabled = true;
  button.textContent = 'Создаём заявку...';

  try {
    const result = await api.shopOrder(request.itemId, request.denominationId, request.requestToken);

    if (result.user) {
      state.user = result.user;
      renderBalances(state.user);
    }

    const order = result.order || {};
    const replayed = Boolean(order.request_replayed);
    pendingOrder = null;
    haptic('medium');
    renderOrderSuccess(order, replayed);
  } catch (error) {
    // requestToken сохраняется. Повторное нажатие после сетевой ошибки отправит
    // тот же ключ, поэтому сервер не сможет списать Gold второй раз.
    button.disabled = false;
    button.textContent = 'Повторить создание';
    toast(error?.message || 'Не удалось создать заявку.');
  }
}

function renderOrderSuccess(order, replayed){
  const amount = Number(order.gold_cost || order.amount || 0);
  const title = order.prize_title || order.provider || 'Приз';
  const denomination = order.denomination_label || `${formatNumber(amount)} Gold`;
  const orderNumber = shortOrderId(order.id);

  openSheet(`
    <div class="sheet-head">
      <div><h2>${replayed ? 'Заявка уже создана' : 'Заявка создана'}</h2><p>Заказ сохранён и ожидает обработки.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="store-order-success">
      <div class="store-order-success-icon">✓</div>
      <strong>${escapeHtml(title)}</strong>
      <span>${escapeHtml(denomination)}</span>
    </div>

    <div class="store-order-review compact">
      <div class="store-order-review-row">
        <span>Номер заявки</span>
        <strong>${escapeHtml(orderNumber)}</strong>
      </div>
      <div class="store-order-review-row">
        <span>Статус</span>
        <strong>Ожидает обработки</strong>
      </div>
      <div class="store-order-review-row total">
        <span>Списано</span>
        <strong>${formatNumber(amount)} Gold</strong>
      </div>
    </div>

    <div class="store-order-warning ${replayed ? 'safe' : ''}">
      ${replayed
        ? 'Повторный запрос распознан. Новая заявка не создавалась, Gold повторно не списывался.'
        : 'Gold списан один раз. Следить за статусом заявки можно будет в истории магазина на следующем этапе.'}
    </div>

    <button class="btn gold full" id="storeSuccessBack" type="button">Вернуться в магазин</button>
  `);

  document.getElementById('storeSuccessBack')?.addEventListener('click', openStoreSheet);
}

function createRequestToken(){
  let randomPart = Math.floor(Math.random() * 1000);
  try {
    const buffer = new Uint32Array(1);
    crypto.getRandomValues(buffer);
    randomPart = Number(buffer[0] % 1000);
  } catch (error) {}

  // 13 цифр времени + 3 цифры случайной части. Значение остаётся безопасным integer в JS.
  return (Date.now() * 1000) + randomPart;
}

function shortOrderId(value){
  const raw = String(value || '').replace(/^shop_/, '');
  return raw ? raw.slice(0, 8).toUpperCase() : '—';
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
