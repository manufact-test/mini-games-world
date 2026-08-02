import { api } from './api/client.js?v=47';
import { openSheet } from './components/sheet.js?v=68';
import { haptic } from './telegram/telegram-app.js?v=27';
import { peekV101CachedJson } from './production-v101-speed-runtime.js?v=101';

const runtime = window.__MGW_V108_NOTIFICATIONS__ ||= {
  initialized:false,
  opening:false,
  latestItem:null,
  pointer:null,
  suppressClickUntil:0,
};

export function initV108Notifications(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  document.addEventListener('mgw:notification-sync', event => {
    if (event.detail?.item?.id) runtime.latestItem = event.detail.item;
  });

  window.addEventListener('click', ownNotificationOpen, true);
  window.addEventListener('pointerdown', ownToastPointerDown, true);
  window.addEventListener('pointermove', ownToastPointerMove, true);
  window.addEventListener('pointerup', ownToastPointerUp, true);
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
  void openFastNotifications(Boolean(bell));
}

function ownToastPointerDown(event){
  const origin = event.target;
  if (!(origin instanceof Element) || !origin.closest('#notificationToast.show')) return;
  event.stopImmediatePropagation();
  runtime.pointer = {
    id:event.pointerId,
    startX:event.clientX,
    startY:event.clientY,
    dx:0,
    dy:0,
  };
}

function ownToastPointerMove(event){
  if (!runtime.pointer || runtime.pointer.id !== event.pointerId) return;
  event.stopImmediatePropagation();
  runtime.pointer.dx = event.clientX - runtime.pointer.startX;
  runtime.pointer.dy = event.clientY - runtime.pointer.startY;
  if (Math.abs(runtime.pointer.dx) > 8 && Math.abs(runtime.pointer.dx) > Math.abs(runtime.pointer.dy)) {
    event.preventDefault();
  }
}

function ownToastPointerUp(event){
  if (!runtime.pointer || runtime.pointer.id !== event.pointerId) return;
  event.stopImmediatePropagation();
  const {dx, dy} = runtime.pointer;
  runtime.pointer = null;
  const horizontalSwipe = Math.abs(dx) >= 64 && Math.abs(dx) > Math.abs(dy) * 1.25;
  if (horizontalSwipe) {
    event.preventDefault();
    runtime.suppressClickUntil = Date.now() + 450;
    dismissToast();
  }
}

function ownToastPointerCancel(event){
  if (!runtime.pointer || runtime.pointer.id !== event.pointerId) return;
  event.stopImmediatePropagation();
  runtime.pointer = null;
}

async function openFastNotifications(fromBell){
  if (runtime.opening) return;
  runtime.opening = true;
  haptic('light');

  const cached = peekV101CachedJson('notifications', 60000);
  const cachedItems = Array.isArray(cached?.items) ? cached.items : [];
  const immediateItems = cachedItems.length
    ? cachedItems
    : (runtime.latestItem?.id ? [runtime.latestItem] : []);
  renderNotifications(immediateItems, immediateItems.length === 0);

  try {
    const result = await api.notifications(true);
    const items = Array.isArray(result?.items) ? result.items : [];
    renderNotifications(items, false);
    document.dispatchEvent(new CustomEvent('mgw:notification-count', {detail:{unreadCount:0}}));
  } catch (error) {
    if (!immediateItems.length) renderError();
  } finally {
    runtime.opening = false;
  }
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
  const tone = ['success','danger','info','warning'].includes(String(item?.tone || ''))
    ? String(item.tone)
    : 'info';
  const message = String(item?.message || '').trim();
  const actions = renderActions(item);
  return `
    <article class="notification-card ${tone}">
      <div class="notification-icon">${String(item?.type || '').startsWith('invite_') ? '🎮' : (tone === 'success' ? '✓' : (tone === 'warning' || tone === 'danger' ? '!' : 'i'))}</div>
      <div class="notification-copy">
        <div class="notification-head"><strong>${escapeHtml(item?.title || 'Уведомление')}</strong></div>
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

function renderError(){
  openSheet(`
    <div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="notifications-empty error"><div>⚠️</div><strong>Не удалось обновить уведомления</strong><span>Попробуйте ещё раз.</span></div>
  `);
}

function dismissToast(){
  runtime.pointer = null;
  const toast = document.getElementById('notificationToast');
  if (!toast) return;
  toast.classList.remove('show', 'dragging');
  toast.style.transform = '';
  toast.style.opacity = '';
}

function escapeHtml(value){
  return String(value ?? '').replace(/[&<>'"]/g, character => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;',
  }[character]));
}
