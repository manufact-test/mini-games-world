import { api } from './api/client.js?v=47';
import { openSheet } from './components/sheet.js?v=68';
import { haptic } from './telegram/telegram-app.js?v=27';
import { peekV101CachedJson } from './production-v101-speed-runtime.js?v=101';

const runtime = window.__MGW_V105_FAST_NOTIFICATIONS__ ||= {
  initialized:false,
  opening:false,
  latestItem:null,
};

export function initV105FastNotifications(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  document.addEventListener('mgw:notification-sync', event => {
    if (event.detail?.item?.id) runtime.latestItem = event.detail.item;
  });

  window.addEventListener('click', ownNotificationOpen, true);
}

function ownNotificationOpen(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;

  const toast = origin.closest('#notificationToast');
  const bell = origin.closest('#notificationsOpen');
  if (!toast && !bell) return;

  event.preventDefault();
  event.stopImmediatePropagation();
  dismissToast();
  void openFastNotifications();
}

async function openFastNotifications(){
  if (runtime.opening) return;
  runtime.opening = true;
  haptic('light');

  const cached = peekV101CachedJson('notifications', 60000);
  const cachedItems = Array.isArray(cached?.items) ? cached.items : [];
  const immediateItems = cachedItems.length
    ? cachedItems
    : (runtime.latestItem?.id ? [runtime.latestItem] : []);

  // Open immediately from the latest trusted snapshot. No pointer/swipe ownership
  // is installed here: retained v105 notification gestures remain untouched.
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
