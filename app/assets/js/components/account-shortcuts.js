import { api } from '../api/client.js?v=38';

export function initAccountShortcuts(){
  document.addEventListener('click', event => {
    const trigger = event.target.closest('#moreMenuOpen, #gameMenuOpen');
    if (!trigger) return;

    // home-screen opens the menu synchronously before this listener runs.
    queueMicrotask(enhanceCurrentMenu);
  });
}

async function enhanceCurrentMenu(){
  const sheet = document.getElementById('sheet');
  const menu = sheet?.querySelector('.menu-list');
  if (!menu || sheet.querySelector('[data-account-orders-shortcut]')) return;

  const button = document.createElement('button');
  button.className = 'btn menu-item account-menu-entry';
  button.type = 'button';
  button.dataset.accountOrdersShortcut = '1';
  button.dataset.openStoreOrders = '1';
  button.innerHTML = `
    <span class="account-menu-icon" aria-hidden="true">🎁</span>
    <span class="account-menu-copy">
      <strong>Мои заявки</strong>
      <small>Проверяем историю заказов…</small>
    </span>
    <b class="account-menu-count" hidden>0</b>
  `;

  menu.prepend(button);

  try {
    const result = await api.shopOrders();
    if (!document.body.contains(button)) return;

    const orders = Array.isArray(result.orders) ? result.orders : [];
    const active = orders.filter(order => ['pending', 'processing'].includes(String(order.status || ''))).length;
    const meta = button.querySelector('small');
    const badge = button.querySelector('.account-menu-count');

    if (meta) {
      meta.textContent = orders.length
        ? (active > 0 ? `${active} ожидают обработки` : `${orders.length} в истории`)
        : 'Заказов пока нет';
    }

    if (badge && orders.length > 0) {
      badge.hidden = false;
      badge.textContent = orders.length > 99 ? '99+' : String(orders.length);
    }
  } catch (error) {
    const meta = button.querySelector('small');
    if (meta) meta.textContent = 'Открыть историю заказов';
  }
}
