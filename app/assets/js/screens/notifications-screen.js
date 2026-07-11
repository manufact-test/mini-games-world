import { api } from '../api/client.js?v=38';
import { openSheet } from '../components/sheet.js?v=27';
import { haptic } from '../telegram/telegram-app.js?v=27';

export function initNotificationsScreen(){
  document.addEventListener('click', event => {
    const trigger = event.target.closest('#notificationsOpen');
    if (!trigger) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    openNotificationsSheet();
  }, true);

  refreshNotificationBadge();
}

export async function refreshNotificationBadge(){
  try {
    const result = await api.notifications(false);
    setUnreadCount(Number(result.unread_count || 0));
  } catch (error) {
    setUnreadCount(0);
  }
}

async function openNotificationsSheet(){
  haptic('light');
  openSheet(`
    <div class="sheet-head">
      <div><h2>Уведомления</h2><p>Изменения по вашим заявкам и важные сообщения.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="notifications-loading">
      <div>🔔</div>
      <strong>Загружаем уведомления</strong>
      <span>Проверяем последние изменения.</span>
    </div>
  `);

  try {
    const result = await api.notifications(true);
    setUnreadCount(0);
    renderNotifications(Array.isArray(result.items) ? result.items : []);
  } catch (error) {
    renderNotificationsError(error);
  }
}

function renderNotifications(items){
  const body = items.length
    ? `<div class="notifications-list">${items.map(renderNotification).join('')}</div>`
    : `
      <div class="notifications-empty">
        <div>🔔</div>
        <strong>Пока уведомлений нет</strong>
        <span>Когда изменится статус заявки, сообщение появится здесь.</span>
      </div>
    `;

  openSheet(`
    <div class="sheet-head">
      <div><h2>Уведомления</h2><p>${items.length ? 'Последние изменения по вашему аккаунту.' : 'Здесь появятся важные изменения.'}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${body}
  `);
}

function renderNotification(item){
  const tone = ['success', 'danger', 'info', 'warning'].includes(String(item.tone || ''))
    ? String(item.tone)
    : 'info';
  const icon = tone === 'success' ? '✓' : (tone === 'danger' ? '!' : '•');

  return `
    <article class="notification-card ${tone}">
      <div class="notification-icon">${icon}</div>
      <div class="notification-copy">
        <div class="notification-head">
          <strong>${escapeHtml(item.title || 'Уведомление')}</strong>
          <span>${escapeHtml(formatDate(item.created_at))}</span>
        </div>
        <p>${escapeHtml(item.message || '')}</p>
      </div>
    </article>
  `;
}

function renderNotificationsError(error){
  openSheet(`
    <div class="sheet-head">
      <div><h2>Уведомления</h2><p>Не удалось загрузить сообщения.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="notifications-empty error">
      <div>⚠️</div>
      <strong>Что-то пошло не так</strong>
      <span>${escapeHtml(error?.message || 'Попробуйте открыть уведомления ещё раз.')}</span>
    </div>
  `);
}

function setUnreadCount(count){
  const button = document.getElementById('notificationsOpen');
  if (!button) return;

  const safeCount = Math.max(0, Math.trunc(Number(count || 0)));
  button.dataset.unread = safeCount > 99 ? '99+' : String(safeCount);
  button.classList.toggle('has-unread', safeCount > 0);
  button.setAttribute('aria-label', safeCount > 0 ? `Уведомления: ${safeCount} новых` : 'Уведомления');
}

function formatDate(value){
  const date = new Date(value || '');
  if (Number.isNaN(date.getTime())) return '';
  return new Intl.DateTimeFormat('ru-RU', {
    day:'2-digit',
    month:'2-digit',
    hour:'2-digit',
    minute:'2-digit',
  }).format(date);
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
