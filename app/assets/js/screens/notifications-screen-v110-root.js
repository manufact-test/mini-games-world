import { openSheet } from '../components/sheet.js?v=68';
import { haptic, getInitData } from '../telegram/telegram-app.js?v=27';
import { getSessionId } from '../session.js?v=27';

const NOTIFICATIONS_URL = `${window.location.origin}/bot/notifications.php`;
const ANNOUNCED_STORAGE_KEY = 'mgw_announced_notifications_v3';
const MAX_ANNOUNCED_IDS = 300;
const MAX_LIVE_ITEMS = 30;
const NOTIFICATION_POLL_MS = 30000;
const NOTIFICATION_TOAST_DURATION = 8000;
const EMPTY_RETRY_MS = 160;

let initialized = false;
let notificationPoll = null;
let refreshingBadge = false;
let openingSheetPromise = null;
let appReady = false;
let baselineLoaded = false;
let unreadHint = 0;
let announcedIds = loadAnnouncedIds();
let liveItems = new Map();
let toastItem = null;
let toastTimer = null;
let toastPointer = null;
let suppressToastClickUntil = 0;

export function initNotificationsScreen(){
  if (initialized) return;
  initialized = true;
  ensureToast();

  document.addEventListener('click', event => {
    const trigger = event.target instanceof Element ? event.target.closest('#notificationsOpen') : null;
    if (!trigger) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    void openNotificationsSheet(currentItems());
  }, true);

  document.addEventListener('mgw:app-ready', () => {
    appReady = true;
    void refreshBadge(false);
  }, { once:true });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') void refreshBadge(true);
    else dismissToast();
  });

  document.addEventListener('mgw:notification-count', event => {
    setUnreadCount(Number(event.detail?.unreadCount || 0));
  });

  document.addEventListener('mgw:notification-sync', event => {
    const item = event.detail?.item || null;
    const unreadCount = Number(event.detail?.unreadCount || 0);
    setUnreadCount(unreadCount);
    if (!item?.id) return;

    upsert(item);
    if (isNotificationsSheetOpen()) {
      renderNotifications(currentItems());
      return;
    }

    const id = String(item.id || '');
    if (!id || announcedIds.has(id)) return;
    rememberAnnouncedId(id);
    if (appReady) showToast(item);
  });

  document.addEventListener('mgw:notifications-refresh', () => {
    if (isNotificationsSheetOpen()) void refreshOpenSheet();
    else void refreshBadge(false);
  });

  void refreshBadge(false);
  notificationPoll = window.setInterval(() => void refreshBadge(true), NOTIFICATION_POLL_MS);
}

async function refreshBadge(announce){
  if (refreshingBadge) return;
  refreshingBadge = true;
  try {
    const result = await rawNotifications(false);
    const items = Array.isArray(result?.items) ? result.items : [];
    mergeItems(items);
    setUnreadCount(Number(result?.unread_count || 0));

    if (!baselineLoaded || !announce || !appReady) {
      rememberAnnouncedItems(items);
      baselineLoaded = true;
      return;
    }

    const item = items.find(value => {
      const id = String(value?.id || '');
      return id && !value?.read && !announcedIds.has(id);
    });
    if (item) {
      rememberAnnouncedId(String(item.id || ''));
      showToast(item);
    }
  } catch (error) {
    // Background failures keep the last trustworthy live list.
  } finally {
    refreshingBadge = false;
  }
}

async function openNotificationsSheet(seedItems = [], hapticFeedback = true){
  mergeItems(seedItems);
  const immediate = currentItems();
  if (immediate.length) renderNotifications(immediate);
  else renderLoading();

  if (hapticFeedback) haptic('light');
  dismissToast();
  return refreshOpenSheet();
}

async function openToastNotification(){
  const item = toastItem ? cloneItem(toastItem) : currentItems()[0] || null;
  if (!item?.id) {
    void openNotificationsSheet(currentItems());
    return;
  }

  upsert(item);
  // Paint the exact live item synchronously before the blue toast is hidden and
  // before any notifications request can render an older empty snapshot.
  renderNotifications(mergeNotificationItems([item], currentItems()));
  haptic('light');
  dismissToast();
  void refreshOpenSheet();
}

async function refreshOpenSheet(){
  if (openingSheetPromise) return openingSheetPromise;

  openingSheetPromise = (async () => {
    try {
      let result = await rawNotifications(false);
      let serverItems = Array.isArray(result?.items) ? result.items : [];
      mergeItems(serverItems);
      rememberAnnouncedItems(serverItems);
      baselineLoaded = true;

      let visible = mergeNotificationItems(serverItems, currentItems());
      if (!visible.length && (Number(result?.unread_count || 0) > 0 || unreadHint > 0)) {
        renderLoading();
        await delay(EMPTY_RETRY_MS);
        result = await rawNotifications(false);
        serverItems = Array.isArray(result?.items) ? result.items : [];
        mergeItems(serverItems);
        rememberAnnouncedItems(serverItems);
        visible = mergeNotificationItems(serverItems, currentItems());
      }

      if (visible.length) renderNotifications(visible);
      else renderNotifications([]);
      setUnreadCount(0);
      void rawNotifications(true).catch(() => null);
    } catch (error) {
      if (!currentItems().length) renderError();
    } finally {
      openingSheetPromise = null;
    }
  })();

  return openingSheetPromise;
}

function renderLoading(){
  openSheet(`
    <div class="sheet-head">
      <div><h2>Уведомления</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="notifications-loading"><div>🔔</div><strong>Загружаем…</strong></div>
  `);
}

function renderNotifications(items){
  const body = items.length
    ? `<div class="notifications-list">${items.map(renderNotification).join('')}</div>`
    : '<div class="notifications-empty"><div>🔔</div><strong>Пока уведомлений нет</strong></div>';

  openSheet(`
    <div class="sheet-head">
      <div><h2>Уведомления</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${body}
  `);
}

function renderNotification(item){
  const tone = ['success','danger','info','warning'].includes(String(item?.tone || ''))
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

function ensureToast(){
  let element = document.getElementById('notificationToast');
  if (element) return element;

  element = document.createElement('div');
  element.id = 'notificationToast';
  element.className = 'notification-toast';
  element.setAttribute('role', 'button');
  element.setAttribute('tabindex', '0');
  element.setAttribute('aria-label', 'Открыть уведомления');
  element.innerHTML = `
    <div class="notification-toast-icon" aria-hidden="true">🔔</div>
    <div class="notification-toast-copy"><strong></strong><span></span></div>
  `;
  (document.getElementById('app') || document.body).appendChild(element);

  element.addEventListener('click', event => {
    if (!element.classList.contains('show') || Date.now() < suppressToastClickUntil) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    void openToastNotification();
  });

  element.addEventListener('keydown', event => {
    if (!element.classList.contains('show')) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      dismissToast();
    } else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      void openToastNotification();
    }
  });

  element.addEventListener('pointerdown', event => {
    if (!element.classList.contains('show')) return;
    toastPointer = { id:event.pointerId, startX:event.clientX, startY:event.clientY, dx:0, dy:0 };
    element.classList.add('dragging');
    element.setPointerCapture?.(event.pointerId);
  });

  element.addEventListener('pointermove', event => {
    if (!toastPointer || toastPointer.id !== event.pointerId) return;
    toastPointer.dx = event.clientX - toastPointer.startX;
    toastPointer.dy = event.clientY - toastPointer.startY;
    const distance = Math.max(Math.abs(toastPointer.dx), Math.abs(toastPointer.dy));
    element.style.transform = `translate3d(${toastPointer.dx}px,${toastPointer.dy}px,0)`;
    element.style.opacity = String(Math.max(.3, 1 - distance / 220));
  });

  const finishPointer = (event, cancelled = false) => {
    if (!toastPointer || toastPointer.id !== event.pointerId) return;
    const { dx, dy } = toastPointer;
    toastPointer = null;
    element.classList.remove('dragging');
    element.releasePointerCapture?.(event.pointerId);
    if (!cancelled && Math.max(Math.abs(dx), Math.abs(dy)) >= 64) {
      suppressToastClickUntil = Date.now() + 400;
      dismissToast();
      return;
    }
    element.style.transform = '';
    element.style.opacity = '';
  };

  element.addEventListener('pointerup', event => finishPointer(event));
  element.addEventListener('pointercancel', event => finishPointer(event, true));
  return element;
}

function showToast(item){
  if (!canShowToast()) return false;
  const element = ensureToast();
  const tone = ['success','danger','warning','info'].includes(String(item?.tone || ''))
    ? String(item.tone)
    : 'info';
  const title = String(item?.title || 'Уведомление').trim();
  const message = notificationMessage(item);

  upsert(item);
  toastItem = cloneItem(item);
  window.clearTimeout(toastTimer);
  toastPointer = null;
  element.className = `notification-toast ${tone}`;
  element.style.transform = '';
  element.style.opacity = '';
  element.querySelector('.notification-toast-icon').textContent = notificationIcon(tone, item?.type);
  element.querySelector('.notification-toast-copy strong').textContent = title;
  element.querySelector('.notification-toast-copy span').textContent = message;
  element.querySelector('.notification-toast-copy span').hidden = message === '';
  element.setAttribute('aria-label', `${title}${message ? `. ${message}` : ''}`);
  requestAnimationFrame(() => element.classList.add('show'));
  toastTimer = window.setTimeout(dismissToast, NOTIFICATION_TOAST_DURATION);
  haptic(tone === 'danger' ? 'medium' : 'light');
  return true;
}

function canShowToast(){
  if (!appReady || document.visibilityState !== 'visible') return false;
  const screen = document.querySelector('.screen.active');
  if (String(screen?.dataset.screen || '') !== 'home') return false;
  return !document.getElementById('sheetOverlay')?.classList.contains('active');
}

function dismissToast(){
  window.clearTimeout(toastTimer);
  toastTimer = null;
  toastItem = null;
  toastPointer = null;
  const element = document.getElementById('notificationToast');
  if (!element) return;
  element.classList.remove('show','dragging');
  element.style.transform = '';
  element.style.opacity = '';
}

async function rawNotifications(markRead){
  const speed = window.__MGW_V101_SPEED__;
  const fetcher = typeof speed?.rawFetch === 'function' ? speed.rawFetch : window.fetch.bind(window);
  const response = await fetcher(NOTIFICATIONS_URL, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({ initData:getInitData(), sessionId:getSessionId(), markRead:Boolean(markRead) }),
    priority:'high',
    cache:'no-store',
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) throw new Error(data?.error || `Ошибка уведомлений: ${response.status}`);
  return data;
}

function mergeItems(items){
  for (const item of Array.isArray(items) ? items : []) upsert(item);
}

function upsert(item){
  const id = String(item?.id || '');
  if (!id) return;
  liveItems.set(id, { ...(liveItems.get(id) || {}), ...cloneItem(item) });
  liveItems = new Map(currentItems(MAX_LIVE_ITEMS).map(value => [String(value.id), value]));
}

function currentItems(limit = MAX_LIVE_ITEMS){
  return [...liveItems.values()]
    .sort((a,b) => itemTime(b) - itemTime(a))
    .slice(0, limit)
    .map(cloneItem);
}

function mergeNotificationItems(primary, fallback){
  const merged = new Map();
  for (const item of [...(Array.isArray(fallback) ? fallback : []), ...(Array.isArray(primary) ? primary : [])]) {
    const id = String(item?.id || '');
    if (!id) continue;
    merged.set(id, { ...(merged.get(id) || {}), ...cloneItem(item) });
  }
  return [...merged.values()].sort((a,b) => itemTime(b) - itemTime(a)).slice(0, MAX_LIVE_ITEMS);
}

function cloneItem(item){
  if (!item || typeof item !== 'object') return {};
  if (typeof structuredClone === 'function') return structuredClone(item);
  return JSON.parse(JSON.stringify(item));
}

function itemTime(item){
  const value = Date.parse(String(item?.created_at || ''));
  return Number.isFinite(value) ? value : 0;
}

function notificationIcon(tone, type = ''){
  if (String(type).startsWith('invite_')) return '🎮';
  if (tone === 'success') return '✓';
  return tone === 'danger' || tone === 'warning' ? '!' : 'i';
}

function notificationMessage(item){
  let message = String(item?.message || '').trim();
  if (!message) return '';
  const technical = [
    /\s*Баланс уже обновлён\.?/giu,
    /\s*Баланс не изменён\.?/giu,
    /\s*Баланс:\s*-?[\d\s]+\s*→\s*-?[\d\s]+\.?/giu,
    /\s*Статус (?:уже )?обновлён[^.]*\.?/giu,
    /\s*Проверьте статус возврата[^.]*\.?/giu,
    /\s*Статус и возврат можно проверить[^.]*\.?/giu,
    /\s*Возвращено\s*\+\s*[\d\s]+\s*Gold\.?/giu,
    /\s*Откройте Mini App[^.]*\.?/giu,
  ];
  for (const pattern of technical) message = message.replace(pattern, ' ');
  return message.replace(/\s+/g, ' ').replace(/\s+([.,!?])/g, '$1').replace(/\.{2,}/g, '.').trim();
}

function renderError(){
  openSheet(`
    <div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="notifications-empty error"><div>⚠️</div><strong>Не удалось открыть уведомления</strong><span>Попробуйте ещё раз.</span></div>
  `);
}

function setUnreadCount(count){
  const button = document.getElementById('notificationsOpen');
  const safe = Math.max(0, Math.trunc(Number(count || 0)));
  unreadHint = safe;
  if (!button) return;
  button.dataset.unread = safe > 99 ? '99+' : String(safe);
  button.classList.toggle('has-unread', safe > 0);
  button.setAttribute('aria-label', safe > 0 ? `Уведомления: ${safe} новых` : 'Уведомления');
}

function isNotificationsSheetOpen(){
  const overlay = document.getElementById('sheetOverlay');
  if (!overlay?.classList.contains('active')) return false;
  return String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').trim() === 'Уведомления';
}

function rememberAnnouncedItems(items){
  for (const item of Array.isArray(items) ? items : []) {
    const id = String(item?.id || '');
    if (id) announcedIds.add(id);
  }
  persistAnnouncedIds();
}

function rememberAnnouncedId(id){
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

function formatDate(value){
  const date = new Date(value || '');
  if (Number.isNaN(date.getTime())) return '';
  return new Intl.DateTimeFormat('ru-RU', {
    day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit',
  }).format(date);
}

function delay(ms){
  return new Promise(resolve => window.setTimeout(resolve, Math.max(0, Number(ms || 0))));
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#039;');
}
