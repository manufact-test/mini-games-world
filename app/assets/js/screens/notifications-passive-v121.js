import { api } from '../api/client.js?v=47';
import { haptic } from '../telegram/telegram-app.js?v=27';

const ANNOUNCED_STORAGE_KEY = 'mgw_announced_notifications_v3';
const MAX_ANNOUNCED_IDS = 300;
const NOTIFICATION_POLL_MS = 30000;
const NOTIFICATION_TOAST_DURATION = 8000;

let initialized = false;
let notificationPoll = null;
let refreshingBadge = false;
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
    if (!item?.id || isNotificationsSheetOpen()) return;

    const id = String(item.id || '');
    if (!id || announcedIds.has(id)) return;
    rememberNotificationId(id);
    if (appReady) showNotificationToast(item);
  });

  document.addEventListener('mgw:notifications-refresh', () => {
    if (!isNotificationsSheetOpen()) refreshNotificationBadge(false);
  });

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

  // Opening is intentionally absent here. notification-window-owner-v121 owns
  // pointer, click and keyboard activation for both the bell and this toast.
  el.addEventListener('keydown', event => {
    if (event.key === 'Escape' && el.classList.contains('show')) {
      event.preventDefault();
      dismissNotificationToast();
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
  } catch (error) {}
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
