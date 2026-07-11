import { api } from '../api/client.js?v=34';
import { openSheet } from '../components/sheet.js?v=27';
import { haptic } from '../telegram/telegram-app.js?v=27';
import { openStoreSheet } from './store-screen.js?v=34';

export function initStoreOrders(){
  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-open-store-orders]');
    if (!trigger) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    openStoreOrders();
  }, true);
}

export async function openStoreOrders(){
  haptic('light');
  openSheet(`
    <div class="sheet-head">
      <div><h2>Мои заявки</h2><p>Загружаем историю заказов.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="store-orders-loading">
      <div>🧾</div>
      <strong>Проверяем статусы</strong>
      <span>Загружаем последние заявки магазина.</span>
    </div>
  `);

  try {
    const result = await api.shopOrders();
    renderStoreOrders(Array.isArray(result.orders) ? result.orders : []);
  } catch (error) {
    renderStoreOrdersError(error);
  }
}

function renderStoreOrders(orders){
  const list = orders.length
    ? orders.map(renderOrderCard).join('')
    : `
      <div class="store-orders-empty">
        <div>🎁</div>
        <strong>Заявок пока нет</strong>
        <span>После оформления заказа его статус появится здесь.</span>
      </div>
    `;

  openSheet(`
    <div class="sheet-head">
      <div><h2>Мои заявки</h2><p>${orders.length ? `Всего в истории: ${orders.length}` : 'История заказов магазина'}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="store-orders-scroll">
      ${list}
    </div>

    <button class="btn ghost full" id="storeOrdersBack" type="button">Вернуться в магазин</button>
  `);

  document.getElementById('storeOrdersBack')?.addEventListener('click', openStoreSheet);
}

function renderOrderCard(order){
  const status = String(order.status || 'unknown');
  const tone = String(order.status_tone || 'muted');
  const amount = Number(order.gold_cost || 0);
  const title = order.prize_title || order.provider || 'Приз';
  const denomination = order.denomination_label || `${formatNumber(amount)} Gold`;
  const statusText = order.status_label || statusLabel(status);
  const details = orderStatusDetails(order);
  const refund = order.refund_done
    ? `<div class="store-order-refund">↩ Возвращено +${formatNumber(order.refund_amount || amount)} Gold</div>`
    : '';

  return `
    <article class="store-order-history-card">
      <div class="store-order-history-head">
        <div>
          <span>Заявка #${escapeHtml(order.short_id || '—')}</span>
          <strong>${escapeHtml(title)}</strong>
        </div>
        <b class="store-order-status ${escapeAttr(tone)}">${escapeHtml(statusText)}</b>
      </div>

      <div class="store-order-history-meta">
        <span>${escapeHtml(order.country || 'Регион не указан')}</span>
        <span>${escapeHtml(denomination)}</span>
        <span>${formatNumber(amount)} Gold</span>
      </div>

      <div class="store-order-status-copy ${escapeAttr(tone)}">
        ${details}
      </div>

      ${refund}

      <div class="store-order-history-date">
        <span>Создана ${escapeHtml(formatDate(order.created_at))}</span>
        ${statusDate(order) ? `<span>${escapeHtml(statusDate(order))}</span>` : ''}
      </div>
    </article>
  `;
}

function orderStatusDetails(order){
  const status = String(order.status || 'unknown');

  if (status === 'pending') {
    return 'Заявка сохранена и ожидает обработки администратором.';
  }
  if (status === 'processing') {
    return 'Администратор уже взял заявку в работу.';
  }
  if (status === 'done') {
    return 'Приз выдан. Заявка завершена.';
  }
  if (status === 'rejected') {
    const reason = order.reject_reason || 'Причина не указана.';
    return `<strong>Причина:</strong> ${escapeHtml(reason)}`;
  }
  if (status === 'cancelled') {
    return 'Заявка отменена.';
  }

  return 'Статус заявки уточняется. При необходимости обратитесь в поддержку.';
}

function statusDate(order){
  if (order.status === 'done' && order.completed_at) {
    return `Выполнена ${formatDate(order.completed_at)}`;
  }
  if (order.status === 'rejected' && order.rejected_at) {
    return `Отклонена ${formatDate(order.rejected_at)}`;
  }
  if (order.status === 'cancelled' && order.cancelled_at) {
    return `Отменена ${formatDate(order.cancelled_at)}`;
  }
  if (order.status === 'processing' && order.updated_at) {
    return `Обновлена ${formatDate(order.updated_at)}`;
  }
  return '';
}

function statusLabel(status){
  return {
    pending: 'Ожидает обработки',
    processing: 'В обработке',
    done: 'Выполнена',
    rejected: 'Отклонена',
    cancelled: 'Отменена',
  }[status] || 'Статус уточняется';
}

function renderStoreOrdersError(error){
  openSheet(`
    <div class="sheet-head">
      <div><h2>Мои заявки</h2><p>Не удалось загрузить историю.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="store-orders-empty error">
      <div>⚠️</div>
      <strong>История временно недоступна</strong>
      <span>${escapeHtml(error?.message || 'Попробуйте ещё раз.')}</span>
    </div>
    <button class="btn ghost full" id="storeOrdersRetry" type="button">Попробовать снова</button>
  `);

  document.getElementById('storeOrdersRetry')?.addEventListener('click', openStoreOrders);
}

function formatDate(value){
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
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
