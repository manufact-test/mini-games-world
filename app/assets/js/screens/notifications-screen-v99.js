import { api } from '../api/client.js?v=47';
import { openSheet } from '../components/sheet.js?v=68';
import { haptic } from '../telegram/telegram-app.js?v=27';

const ANNOUNCED_STORAGE_KEY = 'mgw_announced_notifications_v3';
const MAX_ANNOUNCED_IDS = 300;
const NOTIFICATION_POLL_MS = 30000;
const NOTIFICATION_TOAST_DURATION = 8000;
const BASELINE_CLOCK_SAFETY_MS = 1500;

let initialized = false;
let appReady = false;
let baselineLoaded = false;
let refreshingBadge = false;
let notificationPoll = null;
let notificationToastTimer = null;
let notificationToastPointer = null;
let notificationToastGeneration = 0;
let suppressNotificationToastClickUntil = 0;
let pendingNotification = null;
let activeToastNotification = null;
let announcedIds = loadAnnouncedIds();
let sheetState = 'closed';
let sheetGeneration = 0;
let sheetItems = [];
let silentInviteToken = incomingInviteToken();

export function initNotificationsScreen(){
  if (initialized) return;
  initialized = true;
  ensureNotificationToast();

  document.addEventListener('click', handleNotificationActivation, true);
  document.addEventListener('mgw:sheet-closed', handleSheetClosed);

  document.addEventListener('mgw:app-ready', () => {
    appReady = true;
    showPendingNotification();
    refreshNotificationBadge(false);
  }, { once:true });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      showPendingNotification();
      refreshNotificationBadge(true);
    } else {
      dismissNotificationToast();
    }
  });

  document.addEventListener('mgw:notification-count', event => {
    setUnreadCount(Number(event.detail?.unreadCount || 0));
  });

  document.addEventListener('mgw:invite-link-opening', event => {
    const token = normalizeInviteToken(event.detail?.token);
    if (token) silentInviteToken = token;
    if (isSilentInviteNotification(pendingNotification)) pendingNotification = null;
    dismissNotificationToast();
  });

  document.addEventListener('mgw:invite-link-resolved', event => {
    const token = normalizeInviteToken(event.detail?.token);
    if (!token || token === silentInviteToken) silentInviteToken = '';
  });

  document.addEventListener('mgw:notification-sync', event => {
    const item = event.detail?.item || null;
    const unreadCount = Number(event.detail?.unreadCount || 0);
    const shouldAnnounce = event.detail?.announce !== false;
    setUnreadCount(unreadCount);
    if (!item?.id) return;

    const id = String(item.id || '');
    if (!shouldAnnounce || isSilentInviteNotification(item)) {
      if (String(pendingNotification?.id || '') === id) pendingNotification = null;
      rememberNotificationId(id);
      dismissNotificationToast();
      mergeSheetItems([item]);
      if (isNotificationsSheetOpen()) renderNotificationsBody(sheetItems);
      return;
    }

    mergeSheetItems([item]);
    if (isNotificationsSheetOpen()) {
      renderNotificationsBody(sheetItems);
      return;
    }

    if (String(pendingNotification?.id || '') === id) pendingNotification = null;
    announceNotification(item);
  });

  document.addEventListener('mgw:notifications-refresh', () => {
    if (isNotificationsSheetOpen()) loadNotificationsSheet({ keepShell:true, hapticFeedback:false });
    else refreshNotificationBadge(false);
  });

  refreshNotificationBadge(false);
  notificationPoll = window.setInterval(() => refreshNotificationBadge(true), NOTIFICATION_POLL_MS);
}

export async function refreshNotificationBadge(announce = false){
  if (refreshingBadge) return;
  refreshingBadge = true;
  const requestStartedAt = Date.now();

  try {
    const result = await api.notifications(false);
    const items = normalizeItems(result?.items);
    setUnreadCount(Number(result?.unread_count || 0));

    if (!baselineLoaded || !announce || !appReady) {
      const freshDuringRequest = rememberBaselineNotifications(items, requestStartedAt);
      baselineLoaded = true;
      const candidate = freshDuringRequest.find(item => !item?.read && !isSilentInviteNotification(item));
      if (candidate) {
        pendingNotification = candidate;
        showPendingNotification();
      }
      return;
    }

    const item = items.find(notification => {
      const id = String(notification?.id || '');
      return id && !notification?.read && !announcedIds.has(id) && !isSilentInviteNotification(notification);
    });
    if (item) announceNotification(item);
  } catch (error) {
    // Background notification refresh must not interrupt the current action.
  } finally {
    refreshingBadge = false;
  }
}

function handleNotificationActivation(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const trigger = origin.closest('#notificationsOpen, #notificationToast');
  if (!(trigger instanceof HTMLElement)) return;
  if (trigger.id === 'notificationToast' && !trigger.classList.contains('show')) return;
  if (trigger.id === 'notificationToast' && Date.now() < suppressNotificationToastClickUntil) return;

  const seedItems = trigger.id === 'notificationToast' && activeToastNotification
    ? [activeToastNotification]
    : [];
  event.preventDefault();
  event.stopImmediatePropagation();
  dismissNotificationToast();
  loadNotificationsSheet({ hapticFeedback:true, keepShell:false, seedItems });
}

function handleSheetClosed(){
  if (sheetState === 'closed') return;
  sheetGeneration += 1;
  sheetState = 'closed';
}

async function loadNotificationsSheet({ hapticFeedback = true, keepShell = false, seedItems = [] } = {}){
  const alreadyOpen = isNotificationsSheetOpen();
  if (!keepShell && alreadyOpen && ['opening', 'loading', 'ready'].includes(sheetState)) return;

  const generation = ++sheetGeneration;
  dismissNotificationToast();
  if (hapticFeedback) haptic('light');

  if (!keepShell || !alreadyOpen) {
    sheetState = 'opening';
    openNotificationsShell();
  }

  const immediateItems = normalizeItems(seedItems);
  if (immediateItems.length) {
    sheetItems = mergeNotificationCollections([], immediateItems);
    sheetState = 'ready';
    renderNotificationsBody(sheetItems);
  } else {
    sheetState = 'loading';
    renderNotificationsLoading();
  }

  try {
    const result = await api.notifications(true);
    if (!canApplySheetResult(generation)) return;
    sheetItems = mergeNotificationCollections(normalizeItems(result?.items), immediateItems);
    rememberNotifications(sheetItems);
    baselineLoaded = true;
    setUnreadCount(0);
    sheetState = 'ready';
    renderNotificationsBody(sheetItems);
  } catch (error) {
    if (!canApplySheetResult(generation)) return;
    sheetState = 'error';
    renderNotificationsError();
  }
}

function openNotificationsShell(){
  openSheet(`
    <span data-notifications-sheet hidden></span>
    <div class="sheet-head">
      <div><h2>Уведомления</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div data-notifications-body></div>
  `);
}

function renderNotificationsLoading(){
  replaceNotificationsBody(`
    <div class="notifications-loading" data-notifications-state="loading">
      <div>🔔</div><strong>Загружаем…</strong>
    </div>
  `);
}

function renderNotificationsBody(items){
  const body = items.length
    ? `<div class="notifications-list" data-notifications-state="loaded">${items.map(renderNotification).join('')}</div>`
    : `<div class="notifications-empty" data-notifications-state="empty"><div>🔔</div><strong>Пока уведомлений нет</strong></div>`;
  replaceNotificationsBody(body);
}

function renderNotificationsError(){
  replaceNotificationsBody(`
    <div class="notifications-empty error" data-notifications-state="error">
      <div>⚠️</div><strong>Не удалось открыть уведомления</strong><span>Попробуйте ещё раз.</span>
    </div>
  `);
}

function replaceNotificationsBody(html){
  const body = document.querySelector('#sheet [data-notifications-body]');
  if (!(body instanceof HTMLElement)) return;
  body.innerHTML = html;
}

function canApplySheetResult(generation){
  return generation === sheetGeneration && isNotificationsSheetOpen();
}

function mergeSheetItems(items){
  sheetItems = mergeNotificationCollections(sheetItems, items);
}

function mergeNotificationCollections(primaryItems, overlayItems){
  const byId = new Map();
  for (const item of normalizeItems(primaryItems)) {
    const id = String(item?.id || '');
    if (id) byId.set(id, item);
  }
  for (const item of normalizeItems(overlayItems)) {
    const id = String(item?.id || '');
    if (!id) continue;
    byId.set(id, { ...byId.get(id), ...item });
  }
  return Array.from(byId.values())
    .sort((left, right) => dateValue(right?.created_at) - dateValue(left?.created_at))
    .slice(0, 30);
}

function renderNotification(item){
  const tone = ['success', 'danger', 'info', 'warning'].includes(String(item?.tone || ''))
    ? String(item.tone)
    : 'info';
  const message = notificationMessage(item);
  return `
    <article class="notification-card ${tone}">
      <div class="notification-icon">${notificationIcon(tone, item?.type)}</div>
      <div class="notification-copy">
        <div class="notification-head">
          <strong>${escapeHtml(item?.title || 'Уведомление')}</strong>
          <span>${escapeHtml(formatDate(item?.created_at))}</span>
        </div>
        ${message ? `<p>${escapeHtml(message)}</p>` : ''}
        ${renderInviteActions(item)}
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

function rememberBaselineNotifications(items, requestStartedAt){
  const fresh = [];
  const threshold = requestStartedAt - BASELINE_CLOCK_SAFETY_MS;
  for (const item of items) {
    const id = String(item?.id || '');
    if (!id) continue;
    if (isSilentInviteNotification(item)) {
      announcedIds.add(id);
      continue;
    }
    const createdAt = dateValue(item?.created_at);
    if (!item?.read && createdAt > threshold) fresh.push(item);
    else announcedIds.add(id);
  }
  persistAnnouncedIds();
  return fresh;
}

function announceNotification(item){
  const id = String(item?.id || '');
  if (!id || announcedIds.has(id) || isSilentInviteNotification(item)) return false;
  if (!appReady || !canShowNotificationToast()) {
    pendingNotification = item;
    return false;
  }
  pendingNotification = null;
  rememberNotificationId(id);
  return showNotificationToast(item);
}

function showPendingNotification(){
  if (!pendingNotification || !appReady || !canShowNotificationToast()) return false;
  const item = pendingNotification;
  pendingNotification = null;
  return announceNotification(item);
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

  el.addEventListener('keydown', event => {
    if (event.key === 'Escape' && el.classList.contains('show')) {
      event.preventDefault();
      dismissNotificationToast();
      return;
    }
    if ((event.key === 'Enter' || event.key === ' ') && el.classList.contains('show')) {
      event.preventDefault();
      el.click();
    }
  });

  el.addEventListener('pointerdown', event => {
    if (!el.classList.contains('show')) return;
    notificationToastPointer = {
      id:event.pointerId,
      startX:event.clientX,
      startY:event.clientY,
      dx:0,
      dy:0,
    };
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
  if (!canShowNotificationToast() || isSilentInviteNotification(item)) return false;
  const el = ensureNotificationToast();
  const tone = ['success', 'danger', 'warning', 'info'].includes(String(item?.tone || ''))
    ? String(item.tone)
    : 'info';
  const title = String(item?.title || 'Уведомление').trim();
  const message = notificationMessage(item);

  window.clearTimeout(notificationToastTimer);
  const generation = ++notificationToastGeneration;
  notificationToastPointer = null;
  activeToastNotification = item;
  el.className = `notification-toast ${tone}`;
  el.style.transform = '';
  el.style.opacity = '';
  el.querySelector('.notification-toast-icon').textContent = notificationIcon(tone, item?.type);
  el.querySelector('.notification-toast-copy strong').textContent = title;
  el.querySelector('.notification-toast-copy span').textContent = message;
  el.querySelector('.notification-toast-copy span').hidden = message === '';
  el.setAttribute('aria-label', `${title}${message ? `. ${message}` : ''}`);
  requestAnimationFrame(() => {
    if (generation !== notificationToastGeneration || activeToastNotification !== item) return;
    el.classList.add('show');
  });
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
  notificationToastGeneration += 1;
  notificationToastPointer = null;
  activeToastNotification = null;
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
  return document.getElementById('sheetOverlay')?.classList.contains('active')
    && Boolean(document.querySelector('#sheet [data-notifications-sheet]'));
}

function isSilentInviteNotification(item){
  if (!silentInviteToken) return false;
  return normalizeInviteToken(item?.invite_token) === silentInviteToken;
}

function incomingInviteToken(){
  const startParam = String(window.Telegram?.WebApp?.initDataUnsafe?.start_param || '');
  const fromTelegram = startParam.startsWith('invite_') ? startParam.slice(7) : '';
  const fromQuery = new URLSearchParams(window.location.search).get('invite') || '';
  return normalizeInviteToken(fromTelegram || fromQuery);
}

function normalizeInviteToken(value){
  const token = String(value || '').toLowerCase();
  return /^[a-f0-9]{24}$/.test(token) ? token : '';
}

function rememberNotifications(items){
  for (const item of normalizeItems(items)) {
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

function normalizeItems(items){
  return Array.isArray(items) ? items.filter(item => item && typeof item === 'object') : [];
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

function formatDate(value){
  const date = new Date(value || '');
  if (Number.isNaN(date.getTime())) return '';
  return new Intl.DateTimeFormat('ru-RU', {
    day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit',
  }).format(date);
}

function dateValue(value){
  const time = new Date(value || '').getTime();
  return Number.isFinite(time) ? time : 0;
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
