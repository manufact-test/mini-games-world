import { openSheet, closeSheet } from '../components/sheet.js?v=1109';
import { haptic, getInitData } from '../telegram/telegram-app.js?v=27';
import { getSessionId } from '../session.js?v=27';
import { state } from '../state.js?v=27';
import { currentScreen, showScreen } from '../router.js?v=27';
import { openStoreOrders } from './store-orders.js?v=36';
import { t } from '@mgw/i18n';

const NOTIFICATIONS_URL = `${window.location.origin}/bot/notifications.php`;
const ANNOUNCED_STORAGE_KEY = 'mgw_announced_notifications_v7';
const LIVE_STORAGE_KEY_PREFIX = 'mgw_notification_live_items_v3';
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
let friendsModulePromise = null;
let notificationReadGeneration = 0;
let unreadHint = 0;
let items = new Map();
let localAuthority = new Map();
let consumedInviteTokens = new Set();
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
    if (!item.id || !isRetainedItem(item)) return;
    const inviteToken = String(item.invite_token || '');
    if (inviteToken && consumedInviteTokens.has(inviteToken)) return;
    if (Number.isFinite(unreadCount)) setUnreadCount(unreadCount);

    rememberLocalAuthority(item);
    upsert(item);

    if (isNotificationsSheetOpen()) {
      pinItem(item);
      renderNotifications(visibleSheetItems());
      rememberAnnouncedItem(item);
      return;
    }

    if (!announce || !appReady || announcedIds.has(announcementKey(item))) return;
    if (showToast(item)) rememberAnnouncedItem(item);
  });

  document.addEventListener('mgw:notification-remove', event => {
    removeInviteNotification(event.detail || {});
  });

  document.addEventListener('mgw:notification-consume-invite', event => {
    void consumeInviteNotification(event.detail || {});
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
  if (toast?.classList.contains('show')) pressedToastItem = toastSnapshot(toast);
}

function handleDocumentClick(event){
  const target = event.target instanceof Element ? event.target : null;
  if (!target) return;

  const bell = target.closest('#notificationsOpen');
  if (bell) {
    event.preventDefault();
    event.stopImmediatePropagation();
    if (notificationCenterBlockedByMatch()) return;
    void openNotificationsSheet({ seed:currentItems(), source:'bell' });
    return;
  }

  if (!isNotificationsSheetOpen()) return;

  const markAll = target.closest('[data-notifications-mark-all]');
  if (markAll) {
    event.preventDefault();
    event.stopImmediatePropagation();
    void markAllNotificationsRead();
    return;
  }

  const deleteButton = target.closest('[data-notification-delete]');
  if (deleteButton) {
    event.preventDefault();
    event.stopImmediatePropagation();
    void deleteNotification(String(deleteButton.dataset.notificationDelete || ''));
    return;
  }

  const openButton = target.closest('[data-notification-open]');
  if (openButton) {
    event.preventDefault();
    event.stopImmediatePropagation();
    void openNotificationDeepLink(String(openButton.dataset.notificationOpen || ''));
    return;
  }

  if (target.closest('[data-invite-action]')) return;
  const card = target.closest('[data-notification-id]');
  if (card && !target.closest('button')) {
    const id = String(card.dataset.notificationId || '');
    const item = itemById(id);
    if (item?.id && !item.read) void markOneNotificationRead(id);
  }
}

function handleSheetClosed(){
  if (!sheetState.open) return;
  sheetState.open = false;
  sheetState.generation += 1;
  sheetState.pinned.clear();
  armCloseGuard();
  dismissToast();
}

function armCloseGuard(){
  const until = Date.now() + CLOSE_GUARD_MS;
  closeGuardUntil = Math.max(closeGuardUntil, until);
  openGuardUntil = Math.max(openGuardUntil, until);
  announcementGuardUntil = Math.max(announcementGuardUntil, until);
}

async function openNotificationsSheet({ seed = [], source = 'bell' } = {}){
  if (notificationCenterBlockedByMatch()) return;
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
  } else {
    renderLoading();
  }

  haptic('light');
  dismissToast();
  if (source === 'toast') await waitForFirstSheetPaint(generation);
  await refreshOpenSheet(generation);
}

async function openToastNotification(){
  if (notificationCenterBlockedByMatch()) {
    pressedToastItem = null;
    dismissToast();
    return;
  }
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
  rememberAnnouncedItem(item);
  await openNotificationsSheet({ seed:[item], source:'toast' });
}

async function waitForFirstSheetPaint(generation){
  await new Promise(resolve => window.requestAnimationFrame(resolve));
  if (!isCurrentSheet(generation)) return;
  await new Promise(resolve => window.requestAnimationFrame(resolve));
}

async function refreshOpenSheet(generation){
  const read = beginNotificationRead();
  try {
    const result = await read.promise;
    if (!isLatestNotificationRead(read.generation)) return;

    const serverItems = normalizeItems(result?.items);
    mergeServerItems(serverItems);
    baselineLoaded = true;
    const unreadCount = Number(result?.unread_count || 0);

    if (!isCurrentSheet(generation)) {
      setUnreadCount(unreadCount);
      return;
    }

    const visible = visibleSheetItems();
    renderNotifications(visible);
    rememberAnnouncedItems(visible);
    setUnreadCount(unreadCount);
  } catch (error) {
    if (!isLatestNotificationRead(read.generation)) return;
    if (isCurrentSheet(generation) && !visibleSheetItems().length) renderError();
  }
}

async function refreshNotifications({ announce = false } = {}){
  if (refreshPromise) return refreshPromise;

  refreshPromise = (async () => {
    const read = beginNotificationRead();
    try {
      const result = await read.promise;
      if (!isLatestNotificationRead(read.generation)) return;

      const serverItems = normalizeItems(result?.items);
      mergeServerItems(serverItems);
      const unreadCount = Number(result?.unread_count || 0);

      if (isNotificationsSheetOpen()) {
        renderNotifications(visibleSheetItems());
        setUnreadCount(unreadCount);
        return;
      }

      setUnreadCount(unreadCount);
      if (!baselineLoaded || !announce || !appReady) {
        rememberAnnouncedItems(serverItems);
        baselineLoaded = true;
        return;
      }

      const next = nextUnannouncedItem(currentItems());
      if (next && showToast(next)) rememberAnnouncedItem(next);
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

async function markAllNotificationsRead(){
  const unread = currentItems().filter(item => !item.read);
  if (!unread.length) return;
  markAllReadLocally();
  setUnreadCount(0);
  if (isNotificationsSheetOpen()) renderNotifications(visibleSheetItems());

  try {
    const result = await rawNotifications(true);
    mergeServerItems(normalizeItems(result?.items));
    setUnreadCount(Number(result?.unread_count || 0));
    if (isNotificationsSheetOpen()) renderNotifications(visibleSheetItems());
  } catch (error) {
    void refreshNotifications({ announce:false });
  }
}

async function markOneNotificationRead(id){
  const item = itemById(id);
  if (!item?.id || item.read) return;
  setItemReadLocally(id);
  setUnreadCount(Math.max(0, unreadHint - 1));
  if (isNotificationsSheetOpen()) renderNotifications(visibleSheetItems());

  try {
    const result = await rawNotifications(false, { readNotificationId:id });
    mergeServerItems(normalizeItems(result?.items));
    setUnreadCount(Number(result?.unread_count || 0));
    if (isNotificationsSheetOpen()) renderNotifications(visibleSheetItems());
  } catch (error) {
    void refreshNotifications({ announce:false });
  }
}

async function deleteNotification(id){
  const item = itemById(id);
  if (!item?.id) return;
  const backup = cloneItem(item);
  removeItemById(id);
  if (!item.read) setUnreadCount(Math.max(0, unreadHint - 1));
  if (isNotificationsSheetOpen()) renderNotifications(visibleSheetItems());

  try {
    const result = await rawNotifications(false, { deleteNotificationId:id });
    mergeServerItems(normalizeItems(result?.items));
    setUnreadCount(Number(result?.unread_count || 0));
    if (isNotificationsSheetOpen()) renderNotifications(visibleSheetItems());
  } catch (error) {
    upsert(backup);
    if (isNotificationsSheetOpen()) renderNotifications(visibleSheetItems());
    void refreshNotifications({ announce:false });
  }
}

async function openNotificationDeepLink(id){
  if (notificationCenterBlockedByMatch()) return;
  const item = itemById(id);
  if (!item?.id || !item.deep_link) return;
  const deepLink = String(item.deep_link || '');
  if (deepLink === 'friends:requests') {
    if (!item.read) void markOneNotificationRead(id);
    try {
      const module = await loadFriendsModule();
      if (notificationCenterBlockedByMatch()) return;
      if (typeof module.initFriendsScreen === 'function') module.initFriendsScreen();
      closeSheet();
      document.dispatchEvent(new CustomEvent('mgw:open-friends', { detail:{ tab:'requests' } }));
    } catch (_) {
      // Keep the notification visible if its internal destination cannot load.
    }
    return;
  }

  if (!item.read) await markOneNotificationRead(id);
  if (notificationCenterBlockedByMatch()) return;
  closeSheet();
  if (deepLink === 'home') {
    showScreen('home');
    return;
  }
  if (deepLink === 'profile') {
    document.dispatchEvent(new CustomEvent('mgw:open-profile'));
    return;
  }
  if (deepLink === 'store') {
    showScreen('store');
    return;
  }
  if (deepLink === 'store:orders') {
    showScreen('store');
    queueMicrotask(() => void openStoreOrders());
  }
}

function loadFriendsModule(){
  if (!friendsModulePromise) {
    friendsModulePromise = import('./friends-screen-v110.js?v=5&mvp18=instant-route&optimistic-relations')
      .catch(error => {
        friendsModulePromise = null;
        throw error;
      });
  }
  return friendsModulePromise;
}

function notificationCenterBlockedByMatch(){
  if (currentScreen() === 'game') return true;
  const game = state.activeGame;
  const id = String(game?.id || '').trim();
  if (!id) return false;
  const status = String(game?.status || '').toLowerCase();
  return !['finished','cancelled','canceled','abandoned'].includes(status);
}

function mergeServerItems(serverItems){
  pruneLocalAuthority();
  const preserved = [...localAuthority.values()].map(entry => entry.item);
  const visibleServerItems = normalizeItems(serverItems).filter(item => {
    const token = String(item.invite_token || '');
    return (!token || !consumedInviteTokens.has(token)) && isRetainedItem(item);
  });
  const merged = mergeNotificationItems(preserved, visibleServerItems);
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
  if (!item.id || !isRetainedItem(item)) return;
  const existing = items.get(item.id) || findEquivalentItem(item) || {};
  const merged = mergeEquivalentNotification(existing, item);
  if (!merged.id) return;

  const identity = notificationIdentity(merged);
  if (identity) {
    for (const [id, candidate] of items.entries()) {
      if (id !== merged.id && notificationIdentity(candidate) === identity) items.delete(id);
    }
  }
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
  if (token && type.startsWith('invite_')) return `invite:${token}`;
  const eventId = String(item?.event_id || '');
  if (eventId) return `event:${eventId}`;
  return String(item?.id || '');
}

function announcementKey(item){
  return notificationIdentity(item) || String(item?.id || '');
}

function rememberLocalAuthority(value){
  const item = normalizeItem(value);
  if (!item.id) return;
  const key = notificationIdentity(item) || item.id;
  const existing = localAuthority.get(key)?.item || {};
  localAuthority.set(key, {
    item:mergeEquivalentNotification(existing, item),
    expiresAt:Date.now() + LOCAL_AUTHORITY_MS,
  });
}

function pruneLocalAuthority(){
  const now = Date.now();
  for (const [key, entry] of localAuthority.entries()) {
    if (!entry || Number(entry.expiresAt || 0) <= now || !isRetainedItem(entry.item)) localAuthority.delete(key);
  }
}

function pinItem(value){
  const item = normalizeItem(value);
  if (!item.id || !isRetainedItem(item)) return;
  const key = notificationIdentity(item) || item.id;
  const existing = sheetState.pinned.get(key) || {};
  sheetState.pinned.set(key, mergeEquivalentNotification(existing, item));
}

function visibleSheetItems(){
  const pinned = [...sheetState.pinned.values()];
  return mergeNotificationItems(pinned, currentItems()).filter(isRetainedItem);
}

function itemById(id){
  const direct = items.get(String(id || ''));
  if (direct) return normalizeItem(direct);
  return currentItems(MAX_ITEMS).find(item => item.id === String(id || '')) || null;
}

function setItemReadLocally(id){
  const targetId = String(id || '');
  for (const [key, item] of items.entries()) {
    if (String(item?.id || '') === targetId) items.set(key, { ...item, read:true });
  }
  for (const [key, item] of sheetState.pinned.entries()) {
    if (String(item?.id || '') === targetId) sheetState.pinned.set(key, { ...item, read:true });
  }
  persistItems();
}

function markAllReadLocally(){
  for (const [id, item] of items.entries()) items.set(id, { ...item, read:true });
  for (const [key, item] of sheetState.pinned.entries()) sheetState.pinned.set(key, { ...item, read:true });
  persistItems();
}

function removeItemById(id){
  const targetId = String(id || '');
  for (const [key, item] of items.entries()) {
    if (String(item?.id || '') === targetId) items.delete(key);
  }
  for (const [key, entry] of localAuthority.entries()) {
    if (String(entry?.item?.id || '') === targetId) localAuthority.delete(key);
  }
  for (const [key, item] of sheetState.pinned.entries()) {
    if (String(item?.id || '') === targetId) sheetState.pinned.delete(key);
  }
  if (String(toastItem?.id || '') === targetId || String(pressedToastItem?.id || '') === targetId) dismissToast();
  persistItems();
}

function removeInviteNotification(detail){
  const token = String(detail.inviteToken || detail.token || '');
  if (!token) return;

  const removedKeys = [];
  for (const [id, item] of items.entries()) {
    if (String(item?.invite_token || '') !== token) continue;
    removedKeys.push(announcementKey(item));
    items.delete(id);
  }
  for (const [key, entry] of localAuthority.entries()) {
    if (String(entry?.item?.invite_token || '') === token) localAuthority.delete(key);
  }
  for (const [key, item] of sheetState.pinned.entries()) {
    if (String(item?.invite_token || '') === token) sheetState.pinned.delete(key);
  }

  for (const key of removedKeys) announcedIds.add(key);
  if (removedKeys.length) persistAnnouncedIds();

  if (String(toastItem?.invite_token || '') === token
      || String(pressedToastItem?.invite_token || '') === token) dismissToast();

  announcementGuardUntil = Math.max(announcementGuardUntil, Date.now() + CLOSE_GUARD_MS);
  persistItems();
  const hasExactUnread = detail.unreadCount !== null && detail.unreadCount !== undefined;
  const exactUnread = hasExactUnread ? Number(detail.unreadCount) : Number.NaN;
  setUnreadCount(Number.isFinite(exactUnread) ? exactUnread : Math.max(0, unreadHint - 1));

  if (isNotificationsSheetOpen()) renderNotifications(visibleSheetItems());
}

async function consumeInviteNotification(detail){
  const token = String(detail.inviteToken || detail.token || '');
  if (!token) return;

  consumedInviteTokens.add(token);
  while (consumedInviteTokens.size > MAX_ANNOUNCED_IDS) {
    consumedInviteTokens.delete(consumedInviteTokens.values().next().value);
  }
  removeInviteNotification(detail);
  try {
    const result = await rawNotifications(false, { consumeInviteToken:token });
    const unreadCount = Number(result?.unread_count);
    removeInviteNotification({
      inviteToken:token,
      unreadCount:Number.isFinite(unreadCount) ? Math.max(0, unreadCount) : null,
    });
  } catch (error) {
    // Keep this token consumed in the current document; normal refreshes cannot reinsert it.
  }
}

function renderSheetHead(values = []){
  const hasUnread = normalizeItems(values).some(item => !item.read);
  return `
    <div class="sheet-head notification-center-head">
      <div><h2>${escapeHtml(t('notifications.title'))}</h2></div>
      <div class="notification-center-head-actions">
        <button class="btn ghost notification-mark-all" data-notifications-mark-all type="button"${hasUnread ? '' : ' hidden'}>${escapeHtml(t('notifications.mark_all'))}</button>
        <button class="close" data-close-sheet type="button">×</button>
      </div>
    </div>
  `;
}

function syncSheetHead(values){
  const button = document.querySelector('#sheet [data-notifications-mark-all]');
  if (button instanceof HTMLButtonElement) button.hidden = !normalizeItems(values).some(item => !item.read);
}

function renderLoading(){
  openSheet(`
    <span data-notifications-sheet data-notifications-owner="r13" hidden></span>
    ${renderSheetHead([])}
    <div class="notifications-loading"><div>🔔</div><strong>${escapeHtml(t('notifications.loading'))}</strong></div>
  `);
}

function renderNotifications(values){
  const safe = normalizeItems(values).filter(isRetainedItem);
  const existingList = isNotificationsSheetOpen()
    ? document.querySelector('#sheet .notifications-list')
    : null;

  if (existingList && safe.length) {
    const scrollTop = existingList.scrollTop;
    existingList.innerHTML = safe.map(renderNotification).join('');
    existingList.scrollTop = scrollTop;
    syncSheetHead(safe);
    return;
  }

  const body = safe.length
    ? `<div class="notifications-list">${safe.map(renderNotification).join('')}</div>`
    : `<div class="notifications-empty"><div>🔔</div><strong>${escapeHtml(t('notifications.empty'))}</strong></div>`;

  openSheet(`
    <span data-notifications-sheet data-notifications-owner="r13" hidden></span>
    ${renderSheetHead(safe)}
    ${body}
  `);
}

function renderNotification(item){
  const tone = semanticTone(item);
  const message = notificationMessage(item);
  const unread = !item.read;
  return `
    <article class="notification-card ${tone}${unread ? ' unread' : ''}" data-notification-id="${escapeHtml(item.id)}" data-notification-event-id="${escapeHtml(item.event_id)}" data-notification-type="${escapeHtml(item.type)}" data-notification-invite-token="${escapeHtml(item.invite_token)}">
      <div class="notification-icon">${notificationIcon(tone, item.type)}</div>
      <div class="notification-copy">
        <div class="notification-head">
          <strong>${unread ? '<span class="notification-unread-dot" aria-hidden="true"></span>' : ''}${escapeHtml(item.title || t('notifications.item_fallback'))}</strong>
          <span>${escapeHtml(formatDate(item.created_at))}</span>
        </div>
        ${message ? `<p>${escapeHtml(message)}</p>` : ''}
        ${renderInviteActions(item)}
        ${renderV2Actions(item)}
      </div>
    </article>
  `;
}

function renderV2Actions(item){
  if (item.type === 'friend_request' && item.deep_link === 'friends:requests') {
    return `<div class="notification-card-actions"><button class="btn primary full" data-notification-open="${escapeHtml(item.id)}" type="button">Добавить в друзья</button></div>`;
  }
  const buttons = [];
  if (item.deep_link) {
    buttons.push(`<button class="btn ghost" data-notification-open="${escapeHtml(item.id)}" type="button">${escapeHtml(t('notifications.open'))}</button>`);
  }
  buttons.push(`<button class="btn ghost danger" data-notification-delete="${escapeHtml(item.id)}" type="button">${escapeHtml(t('notifications.delete'))}</button>`);
  return `<div class="notification-card-actions">${buttons.join('')}</div>`;
}

function renderInviteActions(item){
  const token = String(item.invite_token || '');
  const actions = Array.isArray(item.actions) ? item.actions : [];
  if (!token || !actions.length) return '';
  const snapshot = inviteActionSnapshot(item);
  const snapshotAttribute = snapshot
    ? ` data-invite-snapshot="${escapeHtml(JSON.stringify(snapshot))}"`
    : '';
  return `<div class="notification-actions invite-actions">${actions.map(action => {
    const primary = action === 'accept' || action === 'start';
    return `<button class="btn ${primary ? 'primary' : 'ghost'} full" data-invite-action="${escapeHtml(action)}" data-invite-token="${escapeHtml(token)}"${snapshotAttribute} type="button">${escapeHtml(actionLabel(action))}</button>`;
  }).join('')}</div>`;
}

function inviteActionSnapshot(item){
  const snapshot = item?.invite_snapshot && typeof item.invite_snapshot === 'object'
    ? cloneItem(item.invite_snapshot)
    : null;
  if (!snapshot || String(snapshot.token || '') !== String(item?.invite_token || '')) return null;
  return snapshot;
}

function actionLabel(action){
  return {
    accept:'Принять приглашение',
    decline:'Отклонить',
    start:'Начать игру',
    cancel:'Отменить',
  }[String(action || '')] || t('notifications.open');
}

function renderError(){
  openSheet(`
    <span data-notifications-sheet data-notifications-owner="r13" hidden></span>
    ${renderSheetHead([])}
    <div class="notifications-empty error"><div>⚠️</div><strong>${escapeHtml(t('notifications.load_error'))}</strong><span>${escapeHtml(t('notifications.try_again'))}</span></div>
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
  element.setAttribute('aria-label', t('notifications.open_center'));
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
  if (!item.id || !isRetainedItem(item)) return false;
  const element = ensureToast();
  const tone = semanticTone(item);
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
  element.querySelector('.notification-toast-copy strong').textContent = item.title || t('notifications.item_fallback');
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
  if (notificationCenterBlockedByMatch()) return false;
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

function beginNotificationRead(){
  const generation = ++notificationReadGeneration;
  return {
    generation,
    promise:rawNotifications(false),
  };
}

function isLatestNotificationRead(generation){
  return Number(generation) === notificationReadGeneration;
}

async function rawNotifications(markRead, options = {}){
  const speed = window.__MGW_V101_SPEED__;
  const fetcher = typeof speed?.rawFetch === 'function' ? speed.rawFetch : window.fetch.bind(window);
  const response = await fetcher(NOTIFICATIONS_URL, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({
      initData:getInitData(),
      sessionId:getSessionId(),
      markRead:Boolean(markRead),
      readNotificationId:String(options.readNotificationId || ''),
      deleteNotificationId:String(options.deleteNotificationId || ''),
      consumeInviteToken:String(options.consumeInviteToken || ''),
    }),
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
    .filter(item => item.id && isRetainedItem(item))
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
    if (!identity || !isRetainedItem(value)) continue;
    const previous = merged.get(identity) || {};
    merged.set(identity, mergeEquivalentNotification(previous, value));
  }
  return [...merged.values()]
    .filter(item => item.id && isRetainedItem(item))
    .sort((a,b) => itemTime(b) - itemTime(a) || b.id.localeCompare(a.id))
    .slice(0, MAX_ITEMS);
}

function mergeEquivalentNotification(previousValue, incomingValue){
  const previous = normalizeItem(previousValue);
  const incoming = normalizeItem(incomingValue);
  if (!previous.id) return incoming;
  if (!incoming.id) return previous;

  const sameInvite = previous.invite_token
    && previous.invite_token === incoming.invite_token
    && String(previous.type || '').startsWith('invite_')
    && String(incoming.type || '').startsWith('invite_');
  if (sameInvite && isTerminalInviteNotification(previous) && !isTerminalInviteNotification(incoming)) {
    return normalizeItem({ ...incoming, ...previous });
  }
  return normalizeItem({ ...previous, ...incoming });
}

function isTerminalInviteNotification(item){
  const status = String(item?.invite_status || '');
  const type = String(item?.type || '');
  return ['cancelled','canceled','declined','expired','timed_out'].includes(status)
    || ['invite_cancelled','invite_declined','invite_expired','invite_timed_out'].includes(type);
}

function normalizeItems(values){
  return (Array.isArray(values) ? values : []).map(normalizeItem).filter(item => item.id && isRetainedItem(item));
}

function semanticTone(value){
  const type = String(value?.type || '');
  if (['invite_accepted','invite_started'].includes(type)) return 'success';
  if (type === 'invite_declined') return 'danger';
  if (['invite_received','invite_rematch_received','invite_cancelled','invite_expired','invite_timed_out'].includes(type)) return 'info';
  const tone = String(value?.tone || 'info');
  return ['success','danger','info'].includes(tone) ? tone : 'info';
}

function normalizeItem(value){
  if (!value || typeof value !== 'object') return {
    id:'', event_id:'', type:'', title:'', message:'', tone:'info', invite_token:'', invite_status:'', invite_is_owner:false,
    actions:[], created_at:'', expires_at:'', deep_link:'', active:true, read:false,
  };
  const item = {
    ...cloneItem(value),
    id:String(value.id || ''),
    event_id:String(value.event_id || value.event_key || value.id || ''),
    type:String(value.type || ''),
    title:String(value.title || ''),
    message:String(value.message || ''),
    tone:semanticTone(value),
    invite_token:String(value.invite_token || ''),
    invite_status:String(value.invite_status || ''),
    invite_is_owner:Boolean(value.invite_is_owner),
    invite_snapshot:value.invite_snapshot && typeof value.invite_snapshot === 'object'
      ? cloneItem(value.invite_snapshot)
      : null,
    actions:Array.isArray(value.actions) ? value.actions.map(String) : [],
    created_at:String(value.created_at || ''),
    expires_at:String(value.expires_at || ''),
    deep_link:safeDeepLink(value.deep_link),
    active:value.active !== false,
    read:Boolean(value.read),
  };
  item.actions = completeInviteActions(item);
  return item;
}

function safeDeepLink(value){
  const link = String(value || '');
  return ['home','profile','store','store:orders','friends:requests'].includes(link) ? link : '';
}

function isRetainedItem(item){
  if (!item || item.active === false) return false;
  const expiresAt = String(item.expires_at || '');
  if (!expiresAt) return true;
  const timestamp = Date.parse(expiresAt);
  return !Number.isFinite(timestamp) || timestamp > Date.now();
}

function completeInviteActions(item){
  if (isTerminalInviteNotification(item)) return [];
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
  return normalizeItems(values).find(item => !item.read && !announcedIds.has(announcementKey(item))) || null;
}

function setUnreadCount(value){
  const safe = Math.max(0, Math.trunc(Number(value || 0)));
  unreadHint = safe;
  const button = document.getElementById('notificationsOpen');
  if (!button) return;
  button.dataset.unread = safe > 99 ? '99+' : String(safe);
  button.classList.toggle('has-unread', safe > 0);
  button.setAttribute('aria-label', safe > 0
    ? t('notifications.unread_count', { count:safe })
    : t('notifications.title'));
}

function isNotificationsSheetOpen(){
  return Boolean(
    sheetState.open
      && document.getElementById('sheetOverlay')?.classList.contains('active')
      && document.querySelector('#sheet [data-notifications-owner="r13"]')
  );
}

function isCurrentSheet(generation){
  return generation === sheetState.generation && isNotificationsSheetOpen();
}

function rememberAnnouncedItems(values){
  for (const item of normalizeItems(values)) announcedIds.add(announcementKey(item));
  persistAnnouncedIds();
}

function rememberAnnouncedItem(item){
  const key = announcementKey(normalizeItem(item));
  if (!key) return;
  announcedIds.add(key);
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
    for (const item of normalizeItems(parsed.items)) upsert(item);
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
  if (type === 'friend_request' || type === 'friend_accepted') return '👥';
  if (tone === 'success') return '✓';
  return tone === 'danger' ? '!' : 'i';
}

function notificationMessage(item){
  let message = String(item?.message || '').trim();
  if (!message) return terminalNotificationFallback(item);
  if (item?.type === 'friend_request' && !message.includes('Откройте заявку')) {
    message += ' Откройте заявку, чтобы посмотреть профиль и принять или отклонить её.';
  }
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

function terminalNotificationFallback(item){
  const status = String(item?.invite_status || '');
  if (status === 'cancelled' || status === 'canceled') {
    return item?.invite_is_owner
      ? 'Вы отменили своё приглашение.'
      : 'Вы отменили участие в матче.';
  }
  if (status === 'declined') return 'Вы отклонили приглашение.';
  return '';
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
