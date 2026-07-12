import { api } from '../api/client.js?v=38';
import { openSheet } from '../components/sheet.js?v=27';
import { haptic } from '../telegram/telegram-app.js?v=27';

const ANNOUNCED_STORAGE_KEY = 'mgw_announced_notifications_v2';
const MAX_ANNOUNCED_IDS = 50;
const NOTIFICATION_TOAST_DURATION = 8000;

let notificationPoll = null;
let refreshingBadge = false;
let appReady = false;
let pendingAnnouncementRefresh = false;
let announcedIds = loadAnnouncedIds();
let notificationToastTimer = null;
let notificationToastPointer = null;
let suppressNotificationToastClickUntil = 0;

export function initNotificationsScreen(){
  ensureNotificationToast();

  document.addEventListener('click', event => {
    const trigger = event.target.closest('#notificationsOpen');
    if (!trigger) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    openNotificationsSheet();
  }, true);

  document.addEventListener('mgw:app-ready', () => {
    appReady = true;
    refreshNotificationBadge(true);
  }, { once:true });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      refreshNotificationBadge(appReady);
    }
  });

  // Before the preloader is gone we may update only the bell badge.
  refreshNotificationBadge(false);

  if (!notificationPoll) {
    notificationPoll = setInterval(() => refreshNotificationBadge(appReady), 10000);
  }
}

export async function refreshNotificationBadge(announce = appReady){
  if (refreshingBadge) {
    if (announce) pendingAnnouncementRefresh = true;
    return;
  }

  refreshingBadge = true;

  try {
    const result = await api.notifications(false);
    const items = Array.isArray(result.items) ? result.items : [];
    setUnreadCount(Number(result.unread_count || 0));

    if (announce && appReady) {
      announceNewestUnread(items);
    }
  } catch (error) {
    // Keep the current badge state on a temporary network error.
  } finally {
    refreshingBadge = false;

    if (pendingAnnouncementRefresh) {
      pendingAnnouncementRefresh = false;
      queueMicrotask(() => refreshNotificationBadge(true));
    }
  }
}

async function openNotificationsSheet(){
  dismissNotificationToast();
  haptic('light');
  openSheet(`
    <div class="sheet-head">
      <div><h2>Уведомления</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="notifications-loading">
      <div>🔔</div>
      <strong>Загружаем…</strong>
    </div>
  `);

  try {
    const result = await api.notifications(true);
    const items = Array.isArray(result.items) ? result.items : [];
    rememberNotifications(items);
    setUnreadCount(0);
    renderNotifications(items);
  } catch (error) {
    renderNotificationsError();
  }
}

function announceNewestUnread(items){
  const notification = items.find(item => {
    const id = String(item?.id || '');
    return id !== '' && !item?.read && !announcedIds.has(id);
  });

  if (!notification) return;

  rememberNotificationId(String(notification.id || ''));
  haptic(String(notification.tone || '') === 'danger' ? 'medium' : 'light');
  showNotificationToast(notification);
}

function ensureNotificationToast(){
  let el = document.getElementById('notificationToast');
  if (el) return el;

  el = document.createElement('div');
  el.id = 'notificationToast';
  el.className = 'notification-toast';
  el.setAttribute('role', 'button');
  el.setAttribute('tabindex', '0');
  el.setAttribute('aria-label', 'Открыть уведомления');
  el.innerHTML = `
    <div class="notification-toast-icon" aria-hidden="true">🔔</div>
    <div class="notification-toast-copy">
      <strong></strong>
      <span></span>
    </div>
    <button class="notification-toast-close" data-notification-toast-close type="button" aria-label="Закрыть уведомление">×</button>
  `;

  (document.getElementById('app') || document.body).appendChild(el);

  el.addEventListener('click', event => {
    if (!el.classList.contains('show') || Date.now() < suppressNotificationToastClickUntil) return;

    if (event.target.closest('[data-notification-toast-close]')) {
      event.preventDefault();
      event.stopPropagation();
      dismissNotificationToast();
      return;
    }

    dismissNotificationToast();
    openNotificationsSheet();
  });

  el.addEventListener('keydown', event => {
    if (!el.classList.contains('show')) return;

    if (event.key === 'Escape') {
      event.preventDefault();
      dismissNotificationToast();
      return;
    }

    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      dismissNotificationToast();
      openNotificationsSheet();
    }
  });

  el.addEventListener('pointerdown', event => {
    if (!el.classList.contains('show') || event.target.closest('[data-notification-toast-close]')) return;

    notificationToastPointer = {
      id: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      dx: 0,
      dy: 0,
    };
    el.classList.add('dragging');
    el.setPointerCapture?.(event.pointerId);
  });

  el.addEventListener('pointermove', event => {
    if (!notificationToastPointer || notificationToastPointer.id !== event.pointerId) return;

    notificationToastPointer.dx = event.clientX - notificationToastPointer.startX;
    notificationToastPointer.dy = event.clientY - notificationToastPointer.startY;

    const x = notificationToastPointer.dx;
    const y = Math.min(0, notificationToastPointer.dy);
    const distance = Math.max(Math.abs(x), Math.abs(y));
    el.style.transform = `translate3d(${x}px, ${y}px, 0)`;
    el.style.opacity = String(Math.max(0.35, 1 - distance / 220));
  });

  const finishPointer = (event, cancelled = false) => {
    if (!notificationToastPointer || notificationToastPointer.id !== event.pointerId) return;

    const { dx, dy } = notificationToastPointer;
    notificationToastPointer = null;
    el.classList.remove('dragging');
    el.releasePointerCapture?.(event.pointerId);

    const shouldDismiss = !cancelled && (Math.abs(dx) >= 72 || dy <= -48);
    if (shouldDismiss) {
      suppressNotificationToastClickUntil = Date.now() + 350;
      dismissNotificationToast();
      return;
    }

    el.style.transform = '';
    el.style.opacity = '';
  };

  el.addEventListener('pointerup', event => finishPointer(event));
  el.addEventListener('pointercancel', event => finishPointer(event, true));

  return el;
}

function showNotificationToast(item){
  const el = ensureNotificationToast();
  const tone = ['success', 'danger', 'warning', 'info'].includes(String(item?.tone || ''))
    ? String(item.tone)
    : 'info';
  const icon = tone === 'danger'
    ? '!'
    : tone === 'success'
      ? '✓'
      : tone === 'warning'
        ? '⚠'
        : '•';
  const title = String(item?.title || 'Уведомление').trim();
  const message = notificationMessage(item);

  clearTimeout(notificationToastTimer);
  notificationToastPointer = null;
  el.className = `notification-toast ${tone}`;
  el.style.transform = '';
  el.style.opacity = '';
  el.querySelector('.notification-toast-icon').textContent = icon;
  el.querySelector('.notification-toast-copy strong').textContent = title;
  el.querySelector('.notification-toast-copy span').textContent = message;
  el.querySelector('.notification-toast-copy span').hidden = message === '';
  el.setAttribute('aria-label', `${title}${message ? `. ${message}` : ''}. Нажмите, чтобы открыть уведомления.`);

  requestAnimationFrame(() => el.classList.add('show'));
  notificationToastTimer = setTimeout(dismissNotificationToast, NOTIFICATION_TOAST_DURATION);
}

function dismissNotificationToast(){
  clearTimeout(notificationToastTimer);
  notificationToastTimer = null;
  notificationToastPointer = null;

  const el = document.getElementById('notificationToast');
  if (!el) return;

  el.classList.remove('show', 'dragging');
  el.style.transform = '';
  el.style.opacity = '';
}

function renderNotifications(items){
  const body = items.length
    ? `<div class="notifications-list">${items.map(renderNotification).join('')}</div>`
    : `
      <div class="notifications-empty">
        <div>🔔</div>
        <strong>Пока уведомлений нет</strong>
      </div>
    `;

  openSheet(`
    <div class="sheet-head">
      <div><h2>Уведомления</h2></div>
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
  const message = notificationMessage(item);

  return `
    <article class="notification-card ${tone}">
      <div class="notification-icon">${icon}</div>
      <div class="notification-copy">
        <div class="notification-head">
          <strong>${escapeHtml(item.title || 'Уведомление')}</strong>
          <span>${escapeHtml(formatDate(item.created_at))}</span>
        </div>
        ${message ? `<p>${escapeHtml(message)}</p>` : ''}
      </div>
    </article>
  `;
}

function notificationMessage(item){
  let message = String(item?.message || '').trim();
  if (!message) return '';

  const technicalFragments = [
    /\s*Баланс уже обновлён\.?/giu,
    /\s*Баланс не изменён\.?/giu,
    /\s*Баланс:\s*-?[\d\s]+\s*→\s*-?[\d\s]+\.?/giu,
    /\s*Статус (?:уже )?обновлён[^.]*\.?/giu,
    /\s*Проверьте статус возврата[^.]*\.?/giu,
    /\s*Возвращено\s*\+\s*[\d\s]+\s*Gold\.?/giu,
  ];

  for (const pattern of technicalFragments) {
    message = message.replace(pattern, ' ');
  }

  return message
    .replace(/\s+/g, ' ')
    .replace(/\s+([.,!?])/g, '$1')
    .replace(/\.{2,}/g, '.')
    .trim();
}

function renderNotificationsError(){
  openSheet(`
    <div class="sheet-head">
      <div><h2>Уведомления</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="notifications-empty error">
      <div>⚠️</div>
      <strong>Не удалось открыть уведомления</strong>
      <span>Попробуйте ещё раз.</span>
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
