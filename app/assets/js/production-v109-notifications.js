import { openSheet } from './components/sheet.js?v=68';
import { haptic, getInitData } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { peekV101CachedJson } from './production-v101-speed-runtime.js?v=101';

const NOTIFICATIONS_URL = `${window.location.origin}/bot/notifications.php`;
const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const RETRY_DELAYS_MS = [0, 160, 420, 850];

const runtime = window.__MGW_V109_NOTIFICATIONS__ ||= {
  initialized:false,
  opening:false,
  refreshing:false,
  items:new Map(),
  pointer:null,
  suppressClickUntil:0,
};

export function initV109Notifications(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  document.addEventListener('mgw:notification-sync', event => {
    upsert(event.detail?.item || null);
    if (isNotificationsSheetOpen()) renderNotifications(currentItems(), false);
  });

  document.addEventListener('mgw:v101-cache-updated', event => {
    if (String(event.detail?.id || '') !== 'notifications') return;
    mergeItems(event.detail?.data?.items || []);
  });

  document.addEventListener('mgw:notifications-refresh', () => {
    void refreshSilently(isNotificationsSheetOpen());
  });

  window.addEventListener('click', ownNotificationOpen, true);
  window.addEventListener('pointerdown', ownToastPointerDown, true);
  window.addEventListener('pointermove', ownToastPointerMove, { capture:true, passive:false });
  window.addEventListener('pointerup', ownToastPointerUp, { capture:true, passive:false });
  window.addEventListener('pointercancel', ownToastPointerCancel, true);
}

function ownNotificationOpen(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const toast = origin.closest('#notificationToast');
  const bell = origin.closest('#notificationsOpen');
  if (!toast && !bell) return;

  event.preventDefault();
  event.stopImmediatePropagation();
  if (toast && Date.now() < runtime.suppressClickUntil) return;

  dismissToast();
  void openAuthoritativeNotifications();
}

function ownToastPointerDown(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const toast = origin.closest('#notificationToast.show');
  if (!(toast instanceof HTMLElement)) return;

  event.stopImmediatePropagation();
  runtime.pointer = {
    id:event.pointerId,
    toast,
    startX:event.clientX,
    startY:event.clientY,
    dx:0,
    dy:0,
  };
  resetToastStyles(toast);
  toast.style.touchAction = 'none';
  try { toast.setPointerCapture?.(event.pointerId); } catch (error) {}
}

function ownToastPointerMove(event){
  const pointer = runtime.pointer;
  if (!pointer || pointer.id !== event.pointerId) return;

  event.stopImmediatePropagation();
  pointer.dx = event.clientX - pointer.startX;
  pointer.dy = event.clientY - pointer.startY;

  const horizontal = Math.abs(pointer.dx) > 8 && Math.abs(pointer.dx) > Math.abs(pointer.dy) * 1.15;
  const upward = pointer.dy < -8 && Math.abs(pointer.dy) > Math.abs(pointer.dx) * 1.15;
  if (horizontal || upward) event.preventDefault();
  resetToastStyles(pointer.toast);
}

function ownToastPointerUp(event){
  const pointer = runtime.pointer;
  if (!pointer || pointer.id !== event.pointerId) return;

  event.stopImmediatePropagation();
  const {dx, dy, toast} = pointer;
  runtime.pointer = null;
  try { toast.releasePointerCapture?.(event.pointerId); } catch (error) {}
  toast.style.touchAction = '';
  resetToastStyles(toast);

  const horizontalSwipe = Math.abs(dx) >= 64 && Math.abs(dx) > Math.abs(dy) * 1.15;
  const upwardSwipe = dy <= -64 && Math.abs(dy) > Math.abs(dx) * 1.15;
  if (!horizontalSwipe && !upwardSwipe) return;

  event.preventDefault();
  runtime.suppressClickUntil = Date.now() + 500;
  dismissToast();
}

function ownToastPointerCancel(event){
  const pointer = runtime.pointer;
  if (!pointer || pointer.id !== event.pointerId) return;
  event.stopImmediatePropagation();
  runtime.pointer = null;
  try { pointer.toast.releasePointerCapture?.(event.pointerId); } catch (error) {}
  pointer.toast.style.touchAction = '';
  resetToastStyles(pointer.toast);
}

async function openAuthoritativeNotifications(){
  if (runtime.opening) return;
  runtime.opening = true;
  haptic('light');

  const cached = peekV101CachedJson('notifications', 15000);
  mergeItems(cached?.items || []);
  const immediate = currentItems();
  renderNotifications(immediate, immediate.length === 0);

  try {
    let rendered = false;
    for (let index = 0; index < RETRY_DELAYS_MS.length; index++) {
      const delay = RETRY_DELAYS_MS[index];
      if (index > 0) await wait(delay - RETRY_DELAYS_MS[index - 1]);

      const snapshot = await readAuthoritativeSnapshot();
      reconcileSnapshot(snapshot);
      const items = currentItems();
      const unread = Math.max(snapshot.unread, visibleUnreadBadge());

      if (items.length > 0) {
        renderNotifications(items, false);
        rendered = true;
        break;
      }
      if (index === RETRY_DELAYS_MS.length - 1 || unread <= 0) {
        renderNotifications([], false);
        rendered = true;
        break;
      }
    }

    if (!rendered) renderNotifications(currentItems(), false);
    document.dispatchEvent(new CustomEvent('mgw:notification-count', { detail:{ unreadCount:0 } }));

    // Marking read never blocks or replaces the visible list.
    void rawPost(NOTIFICATIONS_URL, { markRead:true }).catch(() => null);
  } catch (error) {
    if (!currentItems().length) renderError();
  } finally {
    runtime.opening = false;
  }
}

async function refreshSilently(render){
  if (runtime.refreshing) return;
  runtime.refreshing = true;
  try {
    const snapshot = await readAuthoritativeSnapshot();
    reconcileSnapshot(snapshot);
    if (render && isNotificationsSheetOpen()) renderNotifications(currentItems(), false);
    document.dispatchEvent(new CustomEvent('mgw:notification-count', {
      detail:{ unreadCount:snapshot.unread },
    }));
  } catch (error) {
    // Existing data stays visible.
  } finally {
    runtime.refreshing = false;
  }
}

async function readAuthoritativeSnapshot(){
  const [notifications, invites] = await Promise.all([
    rawPost(NOTIFICATIONS_URL, { markRead:false }).catch(() => null),
    rawPost(INVITES_URL, { action:'sync', token:'' }).catch(() => null),
  ]);

  if (!notifications && !invites) throw new Error('Notification refresh failed');
  return {
    notificationItems:Array.isArray(notifications?.items) ? notifications.items : null,
    inviteItems:Array.isArray(invites?.invite_events) ? invites.invite_events : [],
    unread:Math.max(
      Number(notifications?.unread_count || 0),
      Number(invites?.unread_count || 0),
    ),
  };
}

function reconcileSnapshot(snapshot){
  if (Array.isArray(snapshot.notificationItems)) replaceItems(snapshot.notificationItems);
  mergeItems(snapshot.inviteItems);
}

async function rawPost(url, payload){
  const speed = window.__MGW_V101_SPEED__;
  const fetcher = typeof speed?.rawFetch === 'function' ? speed.rawFetch : window.fetch.bind(window);
  const response = await fetcher(url, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({
      initData:getInitData(),
      sessionId:getSessionId(),
      ...payload,
    }),
    priority:'high',
    cache:'no-store',
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) throw new Error(data?.error || `Ошибка уведомлений: ${response.status}`);
  return data;
}

function replaceItems(items){
  runtime.items = new Map();
  mergeItems(items);
}

function mergeItems(items){
  for (const item of Array.isArray(items) ? items : []) upsert(item);
}

function upsert(item){
  const id = String(item?.id || '');
  if (!id) return;
  const previous = runtime.items.get(id) || {};
  runtime.items.set(id, enrichInviteActions({ ...previous, ...item }));
  trimItems();
}

function enrichInviteActions(item){
  if (Array.isArray(item?.actions) && item.actions.length) return item;
  const type = String(item?.type || '');
  const token = String(item?.invite_token || '');
  if (!token) return item;
  if (type === 'invite_received' || type === 'invite_rematch_received') return { ...item, actions:['accept','decline'] };
  if (type === 'invite_accepted') return { ...item, actions:['start','cancel'] };
  return item;
}

function currentItems(){
  return [...runtime.items.values()]
    .sort((a, b) => timestamp(b?.created_at) - timestamp(a?.created_at))
    .slice(0, 30);
}

function trimItems(){
  if (runtime.items.size <= 80) return;
  const newest = [...runtime.items.values()]
    .sort((a, b) => timestamp(b?.created_at) - timestamp(a?.created_at))
    .slice(0, 60);
  runtime.items = new Map(newest.map(item => [String(item.id), item]));
}

function timestamp(value){
  const parsed = Date.parse(String(value || ''));
  return Number.isFinite(parsed) ? parsed : 0;
}

function visibleUnreadBadge(){
  const value = String(document.getElementById('notificationsOpen')?.dataset.unread || '0');
  return Number.parseInt(value, 10) || 0;
}

function renderNotifications(items, loading){
  const body = items.length
    ? `<div class="notifications-list">${items.map(renderNotification).join('')}</div>`
    : (loading
      ? '<div class="notifications-loading"><div>🔔</div><strong>Обновляем уведомления…</strong></div>'
      : '<div class="notifications-empty"><div>🔔</div><strong>Пока уведомлений нет</strong></div>');

  openSheet(`
    <div class="sheet-head">
      <div><h2>Уведомления</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${body}
  `);
}

function renderNotification(item){
  const tone = ['success','danger','info','warning'].includes(String(item?.tone || '')) ? String(item.tone) : 'info';
  const message = notificationMessage(item);
  const actions = renderActions(item);
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

function renderActions(item){
  const actions = Array.isArray(item?.actions) ? item.actions : [];
  const token = String(item?.invite_token || '');
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

function notificationIcon(tone, type = ''){
  if (String(type).startsWith('invite_')) return '🎮';
  if (tone === 'success') return '✓';
  if (tone === 'danger' || tone === 'warning') return '!';
  return 'i';
}

function notificationMessage(item){
  let message = String(item?.message || '').trim();
  if (!message) return '';
  const fragments = [
    /\s*Баланс уже обновлён\.?/giu,
    /\s*Баланс не изменён\.?/giu,
    /\s*Баланс:\s*-?[\d\s]+\s*→\s*-?[\d\s]+\.?/giu,
    /\s*Статус (?:уже )?обновлён[^.]*\.?/giu,
    /\s*Проверьте статус возврата[^.]*\.?/giu,
    /\s*Статус и возврат можно проверить[^.]*\.?/giu,
    /\s*Возвращено\s*\+\s*[\d\s]+\s*Gold\.?/giu,
    /\s*Откройте Mini App[^.]*\.?/giu,
  ];
  for (const pattern of fragments) message = message.replace(pattern, ' ');
  return message.replace(/\s+/g, ' ').replace(/\s+([.,!?])/g, '$1').replace(/\.{2,}/g, '.').trim();
}

function formatDate(value){
  const date = new Date(String(value || ''));
  if (Number.isNaN(date.getTime())) return '';
  return new Intl.DateTimeFormat('ru-RU', {
    day:'2-digit',
    month:'2-digit',
    hour:'2-digit',
    minute:'2-digit',
  }).format(date);
}

function renderError(){
  openSheet(`
    <div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="notifications-empty error"><div>⚠️</div><strong>Не удалось обновить уведомления</strong><span>Попробуйте ещё раз.</span></div>
  `);
}

function isNotificationsSheetOpen(){
  const overlay = document.getElementById('sheetOverlay');
  if (!overlay?.classList.contains('active')) return false;
  return String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').trim() === 'Уведомления';
}

function dismissToast(){
  runtime.pointer = null;
  const toast = document.getElementById('notificationToast');
  if (!toast) return;
  toast.classList.remove('show', 'dragging');
  toast.style.touchAction = '';
  resetToastStyles(toast);
}

function resetToastStyles(toast){
  toast.style.transform = '';
  toast.style.opacity = '';
}

function wait(ms){
  return new Promise(resolve => window.setTimeout(resolve, Math.max(0, Number(ms || 0))));
}

function escapeHtml(value){
  return String(value ?? '').replace(/[&<>'"]/g, character => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;',
  }[character]));
}
