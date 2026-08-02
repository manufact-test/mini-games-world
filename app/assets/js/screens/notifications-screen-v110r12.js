import { openSheet } from '../components/sheet.js?v=1109';
import { haptic, getInitData } from '../telegram/telegram-app.js?v=27';
import { getSessionId } from '../session.js?v=27';

const NOTIFICATIONS_URL = `${window.location.origin}/bot/notifications.php`;
const ANNOUNCED_STORAGE_KEY = 'mgw_announced_notifications_v6';
const LIVE_STORAGE_KEY_PREFIX = 'mgw_notification_live_items_v2';
const MAX_ITEMS = 40;
const MAX_ANNOUNCED_IDS = 400;
const POLL_MS = 30000;
const TOAST_MS = 8000;
const CACHE_TTL_MS = 900000;
const LOCAL_AUTHORITY_MS = 12000;
const CLOSE_GUARD_MS = 1100;
const OPEN_GUARD_MS = 450;

let initialized = false;
let appReady = false;
let baselineLoaded = false;
let pollTimer = null;
let refreshPromise = null;
let unreadHint = 0;
let items = new Map();
let localAuthority = new Map();
let announcedIds = loadAnnouncedIds();
let cacheHydrated = false;
let toastItem = null;
let pressedToastItem = null;
let toastTimer = null;
let toastPointer = null;
let closeGuardUntil = 0;
let openGuardUntil = 0;
let announcementGuardUntil = 0;
let sheetState = {
  open:false,
  generation:0,
  pinned:new Map(),
};

export function initNotificationsScreen(){
  if (initialized) return;
  initialized = true;
  ensureToast();

  document.addEventListener('pointerdown', handlePointerDown, true);
  document.addEventListener('click', handleDocumentClick, true);

  document.addEventListener('mgw:app-ready', () => {
    appReady = true;
    hydrateItems();
    void refreshNotifications({ announce:false });
  }, { once:true });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      hydrateItems();
      void refreshNotifications({ announce:true });
    } else {
      dismissToast();
    }
  });

  document.addEventListener('mgw:sheet-closed', handleSheetClosed);

  document.addEventListener('mgw:notification-count', event => {
    setUnreadCount(Number(event.detail?.unreadCount || 0));
  });

  document.addEventListener('mgw:notification-sync', event => {
    const item = normalizeItem(event.detail?.item);
    const unreadCount = Number(event.detail?.unreadCount || 0);
    const announce = event.detail?.announce !== false;
    if (Number.isFinite(unreadCount)) setUnreadCount(unreadCount);
    if (!item.id) return;

    rememberLocalAuthority(item);
    upsert(item);

    if (isNotificationsSheetOpen()) {
      pinItem(item);
      renderNotifications(visibleSheetItems());
      markVisibleReadLocally();
      setUnreadCount(0);
      rememberAnnouncedId(item.id);
      return;
    }

    if (!announce || !appReady || announcedIds.has(item.id)) return;
    if (showToast(item)) rememberAnnouncedId(item.id);
  });

  document.addEventListener('mgw:notification-remove', event => {
    removeInviteNotification(event.detail || {});
  });

  document.addEventListener('mgw:notifications-refresh', () => {
    void refreshNotifications({ announce:false });
  });

  pollTimer = window.setInterval(() => {
    if (document.visibilityState === 'visible') void refreshNotifications({ announce:true });
  }, POLL_MS);
}

function handlePointerDown(event){
  const target = event.target instanceof Element ? event.target : null;
  if (!target) return;

  const close = target.closest('[data-close-sheet]');
  if (close && isNotificationsSheetOpen()) {
    armCloseGuard();
    return;
  }

  const toast = target.closest('#notificationToast');
  if (toast?.classList.contains('show')) {
    pressedToastItem = toastSnapshot(toast);
  }
}

function handleDocumentClick(event){
  const target = event.target instanceof Element ? event.target : null;
  if (!target) return;

  const bell = target.closest('#notificationsOpen');
  if (bell) {
    event.preventDefault();
    event.stopImmediatePropagation();
    if (Date.now() < closeGuardUntil || Date.now() < openGuardUntil) return;
    openGuardUntil = Date.now() + OPEN_GUARD_MS;
    void openNotificationsSheet({ seed:currentItems(), source:'bell' });
  }
}

function handleSheetClosed(){
  if (!sheetState.open) return;
  sheetState.open = false;
  sheetState.generation += 1;
  sheetState.pinned.clear();
  armCloseGuard();
  markVisibleReadLocally();
  dismissToast();
}

function armCloseGuard(){
  const until = Date.now() + CLOSE_GUARD_MS;
  closeGuardUntil = Math.max(closeGuardUntil, until);
  openGuardUntil = Math.max(openGuardUntil, until);
  announcementGuardUntil = Math.max(announcementGuardUntil, until);
}

async function openNotificationsSheet({ seed = [], source = 'bell' } = {}){
  hydrateItems();

  if (isNotificationsSheetOpen()) {
    mergeItems(seed, true);
    for (const item of seed) pinItem(item);
    renderNotifications(visibleSheetItems());
    void refreshNotifications({ announce:false });
    return;
  }

  const generation = ++sheetState.generation;
  sheetState.open = true;
  sheetState.pinned = new Map();

  const immediate = mergeNotificationItems(seed, currentItems());
  for (const item of immediate) pinItem(item);
  mergeItems(immediate, source === 'toast');

  if (immediate.length) {
    renderNotifications(immediate);
    rememberAnnouncedItems(immediate);
    markVisibleReadLocally();
    setUnreadCount(0);
  } else {
    renderLoading();
  }

  haptic('light');
  dismissToast();
  if (source === 'toast') await waitForFirstSheetPaint(generation);
  await refreshOpenSheet(generation);
}

async function openToastNotification(){
  const item = normalizeItem(pressedToastItem || toastSnapshot() || newestItem());
  pressedToastItem = null;
  if (!item.id) {
    void openNotificationsSheet({ seed:currentItems(), source:'toast' });
    return;
  }

  openGuardUntil = Date.now() + OPEN_GUARD_MS;
  announcementGuardUntil = Date.now() + CLOSE_GUARD_MS;
  rememberLocalAuthority(item);
  upsert(item);
  rememberAnnouncedId(item.id);
  await openNotificationsSheet({ seed:[item], source:'toast' });
}

async function waitForFirstSheetPaint(generation){
  await new Promise(resolve => window.requestAnimationFrame(resolve));
  if (!isCurrentSheet(generation)) return;
  await new Promise(resolve => window.requestAnimationFrame(resolve));
}

async function refreshOpenSheet(generation){
  try {
    const result = await rawNotifications(false);
    const serverItems = normalizeItems(result?.items);
    mergeServerItems(serverItems);
    baselineLoaded = true;

    if (!isCurrentSheet(generation)) {
      setUnreadCount(Number(result?.unread_count || 0));
      return;
    }

    const visible = visibleSheetItems();
    renderNotifications(visible);
    rememberAnnouncedItems(visible);
    markVisibleReadLocally();
    setUnreadCount(0);
    void rawNotifications(true).catch(() => null);
  } catch (error) {
    if (isCurrentSheet(generation) && !visibleSheetItems().length) renderError();
  }
}

async function refreshNotifications({ announce = false } = {}){
  if (refreshPromise) return refreshPromise;

  refreshPromise = (async () => {
    try {
      const result = await rawNotifications(false);
      const serverItems = normalizeItems(result?.items);
      mergeServerItems(serverItems);
      const unreadCount = Number(result?.unread_count || 0);

      if (isNotificationsSheetOpen()) {
        renderNotifications(visibleSheetItems());
        markVisibleReadLocally();
        setUnreadCount(0);
        return;
      }

      setUnreadCount(unreadCount);
      if (!baselineLoaded || !announce || !appReady) {
        rememberAnnouncedItems(serverItems);
        baselineLoaded = true;
        return;
      }

      const next = nextUnannouncedItem(currentItems());
      if (next && showToast(next)) rememberAnnouncedId(next.id);
    } catch (error) {
      // Keep the most recent locally authoritative notification state.
    }
  })();

  try {
    return await refreshPromise;
  } finally {
    refreshPromise = null;
  }
}

function mergeServerItems(serverItems){
  pruneLocalAuthority();
  const preserved = [...localAuthority.values()].map(entry => entry.item);
  const merged = mergeNotificationItems(preserved, serverItems);
  items = new Map(merged.map(item => [item.id, item]));
  persistItems();
}

function mergeItems(values, authoritative = false){
  for (const value of normalizeItems(values)) {
    if (authoritative) rememberLocalAuthority(value);
    upsert(value);
  }
}

function upsert(value){
  const item = normalizeItem(value);
  if (!item.id) return;
  const existing = items.get(item.id) || findEquivalentItem(item) || {};
  const merged = normalizeItem({ ...existing, ...item });
  if (!merged.id) return;

  if (existing.id && existing.id !== merged.id) items.delete(existing.id);
  items.set(merged.id, merged);
  items = new Map(currentItems(MAX_ITEMS).map(entry => [entry.id, entry]));
  persistItems();
}

function findEquivalentItem(item){
  const identity = notificationIdentity(item);
  if (!identity) return null;
  return currentItems(MAX_ITEMS).find(value => notificationIdentity(value) === identity) || null;
}

function notificationIdentity(item){
  const token = String(item?.invite_token || '');
  const type = String(item?.type || '');
  if (token && type.startsWith('invite_')) return `${token}|${type}`;
  return String(item?.id || '');
}

function rememberLocalAuthority(value){
  const item = normalizeItem(value);
  if (!item.id) return;
  localAuthority.set(notificationIdentity(item) || item.id, {
    item,
    expiresAt:Date.now() + LOCAL_AUTHORITY_MS,
  });
}

function pruneLocalAuthority(){
  const now = Date.now();
  for (const [key, entry] of localAuthority.entries()) {
    if (!entry || Number(entry.expiresAt || 0) <= now) localAuthority.delete(key);
  }
}

function pinItem(value){
  const item = normalizeItem(value);
  if (!item.id) return;
  const key = notificationIdentity(item) || item.id;
  sheetState.pinned.set(key, item);
}

function visibleSheetItems(){
  const pinned = [...sheetState.pinned.values()];
  return mergeNotificationItems(pinned, currentItems());
}

function removeInviteNotification(detail){
  const token = String(detail.inviteToken || detail.token || '');
  if (!token) return;

  for (const [id, item] of items.entries()) {
    if (String(item?.invite_token || '') === token) items.delete(id);
  }
  for (const [key, entry] of localAuthority.entries()) {
    if (String(entry?.item?.invite_token || '') === token) localAuthority.delete(key);
  }
  for (const [key, item] of sheetState.pinned.entries()) {
    if (String(item?.invite_token || '') === token) sheetState.pinned.delete(key);
  }

  if (String(toastItem?.invite_token || '') === token
      || String(pressedToastItem?.invite_token || '') === token) {
    dismissToast();
  }

  announcementGuardUntil = Math.max(announcementGuardUntil, Date.now() + CLOSE_GUARD_MS);
  persistItems();
  const exactUnread = Number(detail.unreadCount);
  setUnreadCount(Number.isFinite(exactUnread) ? exactUnread : Math.max(0, unreadHint - 1));

  if (isNotificationsSheetOpen()) {
    renderNotifications(visibleSheetItems());
    markVisibleReadLocally();
  }
}

function renderLoading(){
  openSheet(`
    <span data-notifications-sheet data-notifications-owner="r12" hidden></span>
    <div class="sheet-head">
      <div><h2>Уведомления</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="notifications-loading"><div>🔔</div><strong>Загружаем…</strong></div>
  `);
}

function renderNotifications(values){
  const safe = normalizeItems(values);
  const body = safe.length
    ? `<div class="notifications-list">${safe.map(renderNotification).join('')}</div>`
    : '<div class="notifications-empty"><div>🔔</div><strong>Пока уведомлений нет</strong></div>';

  openSheet(`
    <span data-notifications-sheet data-notifications-owner="r12" hidden></span>
    <div class="sheet-head">
      <div><h2>Уведомления</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${body}
  `);
}

function renderNotification(item){
  const tone = ['success','danger','info','warning'].includes(item.tone) ? item.tone : 'info';
  const message = notificationMessage(item);
  return `
    <article class="notification-card ${tone}" data-notification-id="${escapeHtml(item.id)}" data-notification-invite-token="${escapeHtml(item.invite_token)}">
      <div class="notification-icon">${notificationIcon(tone, item.type)}</div>
      <div class="notification-copy">
        <div class="notification-head">
          <strong>${escapeHtml(item.title || 'Уведомление')}</strong>
          <span>${escapeHtml(formatDate(item.created_at))}</span>
        </div>
        ${message ? `<p>${escapeHtml(message)}</p>` : ''}
        ${renderInviteActions(item)}
      </div>
    </article>
  `;
}

function renderInviteActions(item){
  const token = String(item.invite_token || '');
  const actions = Array.isArray(item.actions) ? item.actions : [];
  if (!token || !actions.length) return '';
  return `<div class="notification-actions invite-actions">${actions.map(action => {
    const primary = action === 'accept' || action === 'start';
    return `<button class="btn ${primary ? 'primary' : 'ghost'} full" data-invite-action="${escapeHtml(action)}" data-invite-token="${escapeHtml(token)}" type="button">${escapeHtml(actionLabel(action))}</button>`;
  }).join('')}</div>`;
}

function actionLabel(action){
  return {
    accept:'Принять приглашение',
    decline:'Отклонить',
    start:'Начать игру',
    cancel:'Отменить',
  }[String(action || '')] || 'Открыть';
}

function renderError(){
  openSheet(`
    <span data-notifications-sheet data-notifications-owner="r12" hidden></span>
    <div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="notifications-empty error"><div>⚠️</div><strong>Не удалось открыть уведомления</strong><span>Попробуйте ещё раз.</span></div>
  `);
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
    if (!element.classList.contains('show')) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (Date.now() < closeGuardUntil || Date.now() < openGuardUntil) return;
    pressedToastItem = toastSnapshot(element);
    void openToastNotification();
  });

  element.addEventListener('keydown', event => {
    if (!element.classList.contains('show')) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      dismissToast();
    } else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      pressedToastItem = toastSnapshot(element);
      void openToastNotification();
    }
  });

  element.addEventListener('pointerdown', event => {
    if (!element.classList.contains('show')) return;
    pressedToastItem = toastSnapshot(element);
    toastPointer = { id:event.pointerId, x:event.clientX, y:event.clientY, dx:0, dy:0 };
    element.classList.add('dragging');
    element.setPointerCapture?.(event.pointerId);
  });

  element.addEventListener('pointermove', event => {
    if (!toastPointer || toastPointer.id !== event.pointerId) return;
    toastPointer.dx = event.clientX - toastPointer.x;
    toastPointer.dy = event.clientY - toastPointer.y;
    const distance = Math.max(Math.abs(toastPointer.dx), Math.abs(toastPointer.dy));
    element.style.transform = `translate3d(${toastPointer.dx}px,${toastPointer.dy}px,0)`;
    element.style.opacity = String(Math.max(.3, 1 - distance / 220));
  });

  const finish = (event, cancelled = false) => {
    if (!toastPointer || toastPointer.id !== event.pointerId) return;
    const distance = Math.max(Math.abs(toastPointer.dx), Math.abs(toastPointer.dy));
    toastPointer = null;
    element.classList.remove('dragging');
    element.releasePointerCapture?.(event.pointerId);
    if (!cancelled && distance >= 64) {
      pressedToastItem = null;
      dismissToast();
      return;
    }
    element.style.transform = '';
    element.style.opacity = '';
  };

  element.addEventListener('pointerup', event => finish(event));
  element.addEventListener('pointercancel', event => finish(event, true));
  return element;
}

function showToast(value){
  if (!canShowToast()) return false;
  const item = normalizeItem(value);
  if (!item.id) return false;
  const element = ensureToast();
  const tone = ['success','danger','warning','info'].includes(item.tone) ? item.tone : 'info';
  const message = notificationMessage(item);

  rememberLocalAuthority(item);
  upsert(item);
  toastItem = cloneItem(item);
  pressedToastItem = null;
  element.__mgwNotificationItem = cloneItem(item);
  window.clearTimeout(toastTimer);
  element.className = `notification-toast ${tone}`;
  element.style.transform = '';
  element.style.opacity = '';
  element.querySelector('.notification-toast-icon').textContent = notificationIcon(tone, item.type);
  element.querySelector('.notification-toast-copy strong').textContent = item.title || 'Уведомление';
  element.querySelector('.notification-toast-copy span').textContent = message;
  element.querySelector('.notification-toast-copy span').hidden = message === '';
  requestAnimationFrame(() => element.classList.add('show'));
  toastTimer = window.setTimeout(dismissToast, TOAST_MS);
  haptic(tone === 'danger' ? 'medium' : 'light');
  return true;
}

function toastSnapshot(element = document.getElementById('notificationToast')){
  const stored = element && typeof element.__mgwNotificationItem === 'object'
    ? element.__mgwNotificationItem
    : null;
  return cloneItem(stored || toastItem || newestItem());
}

function canShowToast(){
  if (!appReady || document.visibilityState !== 'visible') return false;
  if (Date.now() < announcementGuardUntil) return false;
  if (document.getElementById('sheetOverlay')?.classList.contains('active')) return false;
  const screen = String(document.querySelector('.screen.active')?.dataset.screen || '');
  return ['home','profile'].includes(screen);
}

function dismissToast(){
  window.clearTimeout(toastTimer);
  toastTimer = null;
  toastItem = null;
  pressedToastItem = null;
  toastPointer = null;
  const element = document.getElementById('notificationToast');
  if (!element) return;
  element.__mgwNotificationItem = null;
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
  if (!response.ok || !data || data.ok === false) {
    throw new Error(data?.error || `Ошибка уведомлений: ${response.status}`);
  }
  return data;
}

function currentItems(limit = MAX_ITEMS){
  return [...items.values()]
    .map(normalizeItem)
    .filter(item => item.id)
    .sort((a,b) => itemTime(b) - itemTime(a) || b.id.localeCompare(a.id))
    .slice(0, limit)
    .map(cloneItem);
}

function newestItem(){
  return currentItems(1)[0] || null;
}

function mergeNotificationItems(primary, fallback){
  const merged = new Map();
  for (const value of [...normalizeItems(fallback), ...normalizeItems(primary)]) {
    const identity = notificationIdentity(value) || value.id;
    if (!identity) continue;
    const previous = merged.get(identity) || {};
    merged.set(identity, normalizeItem({ ...previous, ...value }));
  }
  return [...merged.values()]
    .filter(item => item.id)
    .sort((a,b) => itemTime(b) - itemTime(a) || b.id.localeCompare(a.id))
    .slice(0, MAX_ITEMS);
}

function normalizeItems(values){
  return (Array.isArray(values) ? values : []).map(normalizeItem).filter(item => item.id);
}

function normalizeItem(value){
  if (!value || typeof value !== 'object') return {
    id:'', type:'', title:'', message:'', tone:'info', invite_token:'', invite_status:'', invite_is_owner:false,
    actions:[], created_at:'', read:false,
  };
  const item = {
    ...cloneItem(value),
    id:String(value.id || ''),
    type:String(value.type || ''),
    title:String(value.title || ''),
    message:String(value.message || ''),
    tone:String(value.tone || 'info'),
    invite_token:String(value.invite_token || ''),
    invite_status:String(value.invite_status || ''),
    invite_is_owner:Boolean(value.invite_is_owner),
    actions:Array.isArray(value.actions) ? value.actions.map(String) : [],
    created_at:String(value.created_at || ''),
    read:Boolean(value.read),
  };
  item.actions = completeInviteActions(item);
  return item;
}

function completeInviteActions(item){
  if (Array.isArray(item.actions) && item.actions.length) return item.actions;
  if (!item.invite_token) return [];

  const status = String(item.invite_status || '');
  const type = String(item.type || '');
  if (status === 'pending' && !item.invite_is_owner
      && ['invite_received','invite_rematch_received'].includes(type)) {
    return ['accept','decline'];
  }
  if (status === 'accepted') return item.invite_is_owner ? ['start','cancel'] : ['cancel'];
  return [];
}

function cloneItem(value){
  if (!value || typeof value !== 'object') return {};
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}

function itemTime(item){
  const parsed = Date.parse(String(item?.created_at || ''));
  return Number.isFinite(parsed) ? parsed : 0;
}

function nextUnannouncedItem(values){
  return normalizeItems(values).find(item => !item.read && !announcedIds.has(item.id)) || null;
}

function markVisibleReadLocally(){
  const visibleIds = new Set(visibleSheetItems().map(item => item.id));
  if (!visibleIds.size) return;
  for (const [id, item] of items.entries()) {
    if (visibleIds.has(id)) items.set(id, { ...item, read:true });
  }
  persistItems();
}

function setUnreadCount(value){
  const safe = Math.max(0, Math.trunc(Number(value || 0)));
  unreadHint = safe;
  const button = document.getElementById('notificationsOpen');
  if (!button) return;
  button.dataset.unread = safe > 99 ? '99+' : String(safe);
  button.classList.toggle('has-unread', safe > 0);
  button.setAttribute('aria-label', safe > 0 ? `Уведомления: ${safe} новых` : 'Уведомления');
}

function isNotificationsSheetOpen(){
  return Boolean(
    sheetState.open
      && document.getElementById('sheetOverlay')?.classList.contains('active')
      && document.querySelector('#sheet [data-notifications-owner="r12"]')
  );
}

function isCurrentSheet(generation){
  return generation === sheetState.generation && isNotificationsSheetOpen();
}

function rememberAnnouncedItems(values){
  for (const item of normalizeItems(values)) announcedIds.add(item.id);
  persistAnnouncedIds();
}

function rememberAnnouncedId(id){
  if (!id) return;
  announcedIds.add(String(id));
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
    const values = [...announcedIds].slice(-MAX_ANNOUNCED_IDS);
    announcedIds = new Set(values);
    localStorage.setItem(ANNOUNCED_STORAGE_KEY, JSON.stringify(values));
  } catch (error) {}
}

function hydrateItems(){
  if (cacheHydrated) return;
  cacheHydrated = true;
  try {
    const parsed = JSON.parse(localStorage.getItem(cacheKey()) || 'null');
    if (!parsed || Date.now() - Number(parsed.saved_at || 0) > CACHE_TTL_MS) return;
    for (const item of normalizeItems(parsed.items)) items.set(item.id, item);
  } catch (error) {}
}

function persistItems(){
  try {
    localStorage.setItem(cacheKey(), JSON.stringify({
      saved_at:Date.now(),
      items:currentItems(MAX_ITEMS),
    }));
  } catch (error) {}
}

function cacheKey(){
  let scope = String(getSessionId() || 'anonymous');
  try {
    const raw = new URLSearchParams(getInitData()).get('user');
    const user = raw ? JSON.parse(raw) : null;
    if (user?.id) scope = String(user.id);
  } catch (error) {}
  return `${LIVE_STORAGE_KEY_PREFIX}:${scope}`;
}

function notificationIcon(tone, type){
  if (String(type || '').startsWith('invite_')) return '🎮';
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

function formatDate(value){
  const date = new Date(value || '');
  if (Number.isNaN(date.getTime())) return '';
  return new Intl.DateTimeFormat('ru-RU', {
    day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit',
  }).format(date);
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#039;');
}
