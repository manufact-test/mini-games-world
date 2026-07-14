import { api } from '../api/client.js?v=47';
import { openSheet } from '../components/sheet.js?v=68';
import { haptic } from '../telegram/telegram-app.js?v=27';

const ANNOUNCED_STORAGE_KEY = 'mgw_announced_notifications_v3';
const MAX_ANNOUNCED_IDS = 300;
const NOTIFICATION_POLL_MS = 30000;
const NOTIFICATION_TOAST_DURATION = 8000;

let initialized = false;
let notificationPoll = null;
let refreshingBadge = false;
let openingSheet = false;
let appReady = false;
let baselineLoaded = false;
let announcedIds = loadAnnouncedIds();
let notificationToastTimer = null;
let notificationToastPointer = null;
let suppressNotificationToastClickUntil = 0;

export function initNotificationsScreen(){
  if (initialized) return;
  initialized = true;
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
    refreshNotificationBadge(false);
  }, { once:true });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') refreshNotificationBadge(true);
    else dismissNotificationToast();
  });

  document.addEventListener('mgw:notification-count', event => {
    setUnreadCount(Number(event.detail?.unreadCount || 0));
  });

  document.addEventListener('mgw:notification-sync', event => {
    const item = event.detail?.item || null;
    const unreadCount = Number(event.detail?.unreadCount || 0);
    setUnreadCount(unreadCount);
    if (!item?.id) return;

    if (isNotificationsSheetOpen()) {
      openNotificationsSheet({ hapticFeedback:false });
      return;
    }

    const id = String(item.id || '');
    if (!id || announcedIds.has(id)) return;
    rememberNotificationId(id);
    if (appReady) showNotificationToast(item);
  });

  document.addEventListener('mgw:notifications-refresh', () => {
    if (isNotificationsSheetOpen()) openNotificationsSheet({ hapticFeedback:false });
    else refreshNotificationBadge(false);
  });

  // The first request is only a baseline. Historical weekly/payment events are
  // remembered before the application is allowed to display live alerts.
  refreshNotificationBadge(false);
  notificationPoll = window.setInterval(() => refreshNotificationBadge(true), NOTIFICATION_POLL_MS);
}

export async function refreshNotificationBadge(announce = false){
  if (refreshingBadge) return;
  refreshingBadge = true;
  try {
    const result = await api.notifications(false);
    const items = Array.isArray(result.items) ? result.items : [];
    setUnreadCount(Number(result.unread_count || 0));

    if (!baselineLoaded || !announce || !appReady) {
      rememberNotifications(items);
      baselineLoaded = true;
      return;
    }

    const item = items.find(notification => {
      const id = String(notification?.id || '');
      return id && !notification?.read && !announcedIds.has(id);
    });
    if (item) {
      rememberNotificationId(String(item.id || ''));
      showNotificationToast(item);
    }
  } catch (error) {
    // Keep the last visible count during a temporary network error.
  } finally {
    refreshingBadge = false;
  }
}

async function openNotificationsSheet({ hapticFeedback = true } = {}){
  if (openingSheet) return;
  openingSheet = true;
  dismissNotificationToast();
  if (hapticFeedback) haptic('light');

  openSheet(`
    <div class="sheet-head">
      <div><h2>Уведомления</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="notifications-loading"><div>🔔</div><strong>Загружаем…</strong></div>
  `);

  try {
    const result = await api.notifications(true);
    const items = Array.isArray(result.items) ? result.items : [];
    rememberNotifications(items);
    baselineLoaded = true;
    setUnreadCount(0);
    renderNotifications(items);
  } catch (error) {
    renderNotificationsError();
  } finally {
    openingSheet = false;
  }
}

function renderNotifications(items){
  const body = items.length
    ? `<div class="notifications-list">${items.map(renderNotification).join('')}</div>`
    : `<div class="notifications-empty"><div>🔔</div><strong>Пока уведомлений нет</strong></div>`;

  openSheet(`
    <div class="sheet-head">
      <div><h2>Уведомления</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${body}
  `);
}

function renderNotification(item){
  const tone = ['success', 'danger', 'info', 'warning'].includes(String(item?.tone || ''))
    ? String(item.tone)
    : 'info';
  const message = notificationMessage(item);
  const actions = renderInviteActions(item);

  return `
    <article class="notification-card ${tone}">
      <div class="notification-icon">${notificationIcon(tone, item?.type)}</div>
      <div class="notification-copy">
        <div class="notification-head">
          <strong>${escapeHtml(item?.title || 'Уведомление')}</strong>
          <span>${escapeHtml(formatDate(item?.created_at))}</span>
        </div>
        ${message ? `<p>${escapeHtml(message)}</p>` : ''}
        ${actions}
      </div>
    </article>
  `;
}

function renderInviteActions(item){
  const actions = Array.isArray(item?.actions) ? item.actions : [];
  const token = String(item?.invite_token || '');
  if (!token || !actions.length) return '';

  const buttons = actions.map(action => {
    const primary = action === 'accept' || action === 'start';
    return `<button class="btn ${primary ? 'primary' : 'ghost'} full" data-invite-action="${escapeHtml(action)}" data-invite-token="${escapeHtml(token)}" type="button">${escapeHtml(actionLabel(action))}</button>`;
  }).join('');
  return `<div class="notification-actions invite-actions">${buttons}</div>`;
}

function actionLabel(action){
  return {
    accept:'Принять приглашение',
    decline:'Отклонить',
    start:'Начать игру',
    cancel:'Отменить',
  }[String(action || '')] || 'Открыть';
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
    <div class="notification-toast-copy"><strong></strong><span></span></div>
  `;
  (document.getElementById('app') || document.body).appendChild(el);

  el.addEventListener('click', () => {
    if (!el.classList.contains('show') || Date.now() < suppressNotificationToastClickUntil) return;
    dismissNotificationToast();
    openNotificationsSheet();
  });
  el.addEventListener('keydown', event => {
    if (!el.classList.contains('show')) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      dismissNotificationToast();
    } else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      dismissNotificationToast();
      openNotificationsSheet();
    }
  });
  el.addEventListener('pointerdown', event => {
    if (!el.classList.contains('show')) return;
    notificationToastPointer = { id:event.pointerId, startX:event.clientX, startY:event.clientY, dx:0, dy:0 };
    el.classList.add('dragging');
    el.setPointerCapture?.(event.pointerId);
  });
  el.addEventListener('pointermove', event => {
    if (!notificationToastPointer || notificationToastPointer.id !== event.pointerId) return;
    notificationToastPointer.dx = event.clientX - notificationToastPointer.startX;
    notificationToastPointer.dy = event.clientY - notificationToastPointer.startY;
    const distance = Math.max(Math.abs(notificationToastPointer.dx), Math.abs(notificationToastPointer.dy));
    el.style.transform = `translate3d(${notificationToastPointer.dx}px,${notificationToastPointer.dy}px,0)`;
    el.style.opacity = String(Math.max(.3, 1 - distance / 220));
  });

  const finishPointer = (event, cancelled = false) => {
    if (!notificationToastPointer || notificationToastPointer.id !== event.pointerId) return;
    const { dx, dy } = notificationToastPointer;
    notificationToastPointer = null;
    el.classList.remove('dragging');
    el.releasePointerCapture?.(event.pointerId);
    if (!cancelled && Math.max(Math.abs(dx), Math.abs(dy)) >= 64) {
      suppressNotificationToastClickUntil = Date.now() + 400;
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
  if (!canShowNotificationToast()) return false;
  const el = ensureNotificationToast();
  const tone = ['success', 'danger', 'warning', 'info'].includes(String(item?.tone || ''))
    ? String(item.tone)
    : 'info';
  const title = String(item?.title || 'Уведомление').trim();
  const message = notificationMessage(item);

  window.clearTimeout(notificationToastTimer);
  notificationToastPointer = null;
  el.className = `notification-toast ${tone}`;
  el.style.transform = '';
  el.style.opacity = '';
  el.querySelector('.notification-toast-icon').textContent = notificationIcon(tone, item?.type);
  el.querySelector('.notification-toast-copy strong').textContent = title;
  el.querySelector('.notification-toast-copy span').textContent = message;
  el.querySelector('.notification-toast-copy span').hidden = message === '';
  el.setAttribute('aria-label', `${title}${message ? `. ${message}` : ''}`);
  requestAnimationFrame(() => el.classList.add('show'));
  notificationToastTimer = window.setTimeout(dismissNotificationToast, NOTIFICATION_TOAST_DURATION);
  haptic(tone === 'danger' ? 'medium' : 'light');
  return true;
}

function canShowNotificationToast(){
  if (!appReady || document.visibilityState !== 'visible') return false;
  const activeScreen = document.querySelector('.screen.active');
  if (String(activeScreen?.dataset.screen || '') !== 'home') return false;
  return !document.getElementById('sheetOverlay')?.classList.contains('active');
}

function dismissNotificationToast(){
  window.clearTimeout(notificationToastTimer);
  notificationToastTimer = null;
  notificationToastPointer = null;
  const el = document.getElementById('notificationToast');
  if (!el) return;
  el.classList.remove('show', 'dragging');
  el.style.transform = '';
  el.style.opacity = '';
}

function notificationIcon(tone, type = ''){
  if (String(type).startsWith('invite_')) return '🎮';
  if (tone === 'success') return '✓';
  if (tone === 'danger' || tone === 'warning') return '!';
  return 'i';
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
    /\s*Статус и возврат можно проверить[^.]*\.?/giu,
    /\s*Возвращено\s*\+\s*[\d\s]+\s*Gold\.?/giu,
    /\s*Откройте Mini App[^.]*\.?/giu,
  ];
  for (const pattern of technicalFragments) message = message.replace(pattern, ' ');
  return message.replace(/\s+/g, ' ').replace(/\s+([.,!?])/g, '$1').replace(/\.{2,}/g, '.').trim();
}

function renderNotificationsError(){
  openSheet(`
    <div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="notifications-empty error"><div>⚠️</div><strong>Не удалось открыть уведомления</strong><span>Попробуйте ещё раз.</span></div>
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

function isNotificationsSheetOpen(){
  const overlay = document.getElementById('sheetOverlay');
  if (!overlay?.classList.contains('active')) return false;
  return String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').trim() === 'Уведомления';
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
    // Notifications still work when storage is unavailable.
  }
}

function formatDate(value){
  const date = new Date(value || '');
  if (Number.isNaN(date.getTime())) return '';
  return new Intl.DateTimeFormat('ru-RU', {
    day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit',
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
