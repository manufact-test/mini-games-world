import { api } from '../api/client.js?v=47';
import { openSheet } from '../components/sheet.js?v=68';
import { haptic } from '../telegram/telegram-app.js?v=27';

const EMPTY_CONFIRM_DELAY_MS = 120;
const CACHE_TTL_MS = 15000;
let generation = 0;
let latestItems = [];
let latestItemsAt = 0;
let initialized = false;

initDesktopNotificationOpenOwner();

function initDesktopNotificationOpenOwner(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('click', event => {
    if (!isDesktopSurface()) return;
    const target = event.target instanceof Element ? event.target : null;
    if (!target?.closest('#notificationsOpen, #notificationToast')) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    openNotificationsSheet();
  }, true);

  document.addEventListener('mgw:notification-sync', event => {
    if (!isDesktopSurface()) return;
    const item = event.detail?.item || null;
    if (item?.id) rememberItems([item]);
    if (!isNotificationsSheetOpen()) return;

    event.stopImmediatePropagation();
    if (latestItems.length) renderNotifications(latestItems);
    openNotificationsSheet({ hapticFeedback:false, keepVisible:true });
  });

  document.addEventListener('mgw:notifications-refresh', event => {
    if (!isDesktopSurface() || !isNotificationsSheetOpen()) return;
    event.stopImmediatePropagation();
    openNotificationsSheet({ hapticFeedback:false, keepVisible:true });
  });
}

async function openNotificationsSheet({ hapticFeedback = true, keepVisible = false } = {}){
  const requestGeneration = ++generation;
  if (hapticFeedback) haptic('light');

  const cached = freshItems();
  if (cached.length) renderNotifications(cached);
  else if (!keepVisible || !isNotificationsSheetOpen()) renderLoading();

  try {
    let result = await api.notifications(true);
    let items = normalizeItems(result?.items);

    if (!items.length) {
      await delay(EMPTY_CONFIRM_DELAY_MS);
      if (requestGeneration !== generation || !isNotificationsSheetOpen()) return;
      result = await api.notifications(true);
      items = normalizeItems(result?.items);
    }

    if (!items.length && freshItems().length) items = freshItems();
    if (items.length) rememberItems(items);

    if (requestGeneration !== generation || !isNotificationsSheetOpen()) return;
    setUnreadCount(0);
    renderNotifications(items);
  } catch (error) {
    if (requestGeneration !== generation || !isNotificationsSheetOpen()) return;
    if (freshItems().length) renderNotifications(freshItems());
    else renderError();
  }
}

function rememberItems(items){
  const byId = new Map(latestItems.map(item => [String(item?.id || ''), item]));
  for (const item of normalizeItems(items)) {
    const id = String(item?.id || '');
    if (!id) continue;
    byId.set(id, { ...byId.get(id), ...item, read:true });
  }
  latestItems = Array.from(byId.values())
    .sort((a, b) => dateValue(b?.created_at) - dateValue(a?.created_at))
    .slice(0, 30);
  latestItemsAt = Date.now();
}

function freshItems(){
  return Date.now() - latestItemsAt <= CACHE_TTL_MS ? latestItems : [];
}

function renderLoading(){
  openSheet(`
    <div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="notifications-loading"><div>🔔</div><strong>Загружаем…</strong></div>
  `);
}

function renderNotifications(items){
  const body = items.length
    ? `<div class="notifications-list">${items.map(renderNotification).join('')}</div>`
    : `<div class="notifications-empty"><div>🔔</div><strong>Пока уведомлений нет</strong></div>`;
  openSheet(`
    <div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    ${body}
  `);
}

function renderNotification(item){
  const tone = ['success', 'danger', 'info', 'warning'].includes(String(item?.tone || ''))
    ? String(item.tone)
    : 'info';
  const message = String(item?.message || '').replace(/\s+/g, ' ').trim();
  return `
    <article class="notification-card ${tone}">
      <div class="notification-icon">${notificationIcon(tone, item?.type)}</div>
      <div class="notification-copy">
        <div class="notification-head"><strong>${escapeHtml(item?.title || 'Уведомление')}</strong><span>${escapeHtml(formatDate(item?.created_at))}</span></div>
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
  return { accept:'Принять приглашение', decline:'Отклонить', start:'Начать игру', cancel:'Отменить' }[String(action || '')] || 'Открыть';
}

function renderError(){
  openSheet(`
    <div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="notifications-empty error"><div>⚠️</div><strong>Не удалось открыть уведомления</strong><span>Попробуйте ещё раз.</span></div>
  `);
}

function setUnreadCount(count){
  const button = document.getElementById('notificationsOpen');
  if (!button) return;
  const safeCount = Math.max(0, Math.trunc(Number(count || 0)));
  button.dataset.unread = String(safeCount);
  button.classList.toggle('has-unread', safeCount > 0);
  button.setAttribute('aria-label', safeCount > 0 ? `Уведомления: ${safeCount} новых` : 'Уведомления');
}

function isDesktopSurface(){
  return !window.matchMedia('(max-width: 760px)').matches
    && !(navigator.maxTouchPoints > 0 && window.innerWidth < 900);
}

function isNotificationsSheetOpen(){
  return document.getElementById('sheetOverlay')?.classList.contains('active')
    && String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').trim() === 'Уведомления';
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

function formatDate(value){
  const date = new Date(value || '');
  if (Number.isNaN(date.getTime())) return '';
  return new Intl.DateTimeFormat('ru-RU', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' }).format(date);
}

function dateValue(value){
  const time = new Date(value || '').getTime();
  return Number.isFinite(time) ? time : 0;
}

function delay(ms){
  return new Promise(resolve => window.setTimeout(resolve, ms));
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
