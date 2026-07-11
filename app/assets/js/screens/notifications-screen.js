import { api } from '../api/client.js?v=38';
import { openSheet } from '../components/sheet.js?v=27';
import { toast } from '../components/toast.js?v=41';
import { haptic } from '../telegram/telegram-app.js?v=27';

const ANNOUNCED_STORAGE_KEY = 'mgw_announced_notifications_v1';
const MAX_ANNOUNCED_IDS = 50;

let notificationPoll = null;
let refreshingBadge = false;
let announcedIds = loadAnnouncedIds();

export function initNotificationsScreen(){
  document.addEventListener('click', event => {
    const trigger = event.target.closest('#notificationsOpen');
    if (!trigger) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    openNotificationsSheet();
  }, true);

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      refreshNotificationBadge();
    }
  });

  refreshNotificationBadge();
  if (!notificationPoll) {
    notificationPoll = setInterval(refreshNotificationBadge, 10000);
  }
}

export async function refreshNotificationBadge(){
  if (refreshingBadge) return;
  refreshingBadge = true;

  try {
    const result = await api.notifications(false);
    const items = Array.isArray(result.items) ? result.items : [];
    setUnreadCount(Number(result.unread_count || 0));
    announceNewestUnread(items);
  } catch (error) {
    // Keep the current badge state on a temporary network error.
  } finally {
    refreshingBadge = false;
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
    const items = Array.isArray(result.items) ? result.items : [];
    rememberNotifications(items);
    setUnreadCount(0);
    renderNotifications(items);
  } catch (error) {
    renderNotificationsError(error);
  }
}

function announceNewestUnread(items){
  const notification = items.find(item => {
    const id = String(item?.id || '');
    return id !== '' && !item?.read && !announcedIds.has(id);
  });

  if (!notification) return;

  rememberNotificationId(String(notification.id || ''));

  const tone = String(notification.tone || 'info');
  const icon = tone === 'danger'
    ? '🚫'
    : tone === 'success'
      ? '✅'
      : tone === 'warning'
        ? '⚠️'
        : '🔔';
  const title = String(notification.title || 'Важное уведомление').trim();
  const message = String(notification.message || '').trim();
  const text = message ? `${icon} ${title}. ${message}` : `${icon} ${title}`;

  haptic(tone === 'danger' ? 'medium' : 'light');
  toast(text, 6000);
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

function rememberNotifications(items){
  for (const item of items) {
    const id = String(item?.id || '');
    if (id) announcedIds.add(id);
  }
  persistAnnouncedIds();
}

function rememberNotificationId(id){
  if (!id) return;
  announcedIds.add(id);
  persistAnnouncedIds();
}

function loadAnnouncedIds(){
  try {
    const parsed = JSON.parse(localStorage.getItem(ANNOUNCED_STORAGE_KEY) || '[]');
    return new Set(Array.isArray(parsed) ? parsed.map(String).filter(Boolean).slice(-MAX_ANNOUNCED_IDS) : []);
  } catch (error) {
    return new Set();
  }
}

function persistAnnouncedIds(){
  try {
    const ids = Array.from(announcedIds).slice(-MAX_ANNOUNCED_IDS);
    announcedIds = new Set(ids);
    localStorage.setItem(ANNOUNCED_STORAGE_KEY, JSON.stringify(ids));
  } catch (error) {
    // Notifications still work even when WebView storage is unavailable.
  }
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
