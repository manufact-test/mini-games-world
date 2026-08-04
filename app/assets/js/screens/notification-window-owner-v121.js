import { api } from '../api/client.js?v=47';
import { openSheet } from '../components/sheet.js?v=68';
import { haptic } from '../telegram/telegram-app.js?v=27';

const EMPTY_CONFIRM_DELAY_MS = 180;
const CACHE_TTL_MS = 15000;
const TAP_MOVE_TOLERANCE_PX = 14;
const TAP_MAX_DURATION_MS = 1400;
const CLICK_SUPPRESSION_MS = 700;

let generation = 0;
let latestItems = [];
let latestItemsAt = 0;
let initialized = false;
let activePointer = null;
let suppressClickUntil = 0;
let suppressClickTarget = '';

initNotificationWindowOwner();

function initNotificationWindowOwner(){
  if (initialized) return;
  initialized = true;

  window.addEventListener('pointerdown', handlePointerDown, true);
  window.addEventListener('pointerup', handlePointerUp, true);
  window.addEventListener('pointercancel', handlePointerCancel, true);
  window.addEventListener('click', handleClickFallback, true);
  window.addEventListener('keydown', handleKeyboardOpen, true);

  document.addEventListener('mgw:notification-sync', event => {
    const item = event.detail?.item || null;
    if (item?.id) rememberItems([item]);
    if (!isNotificationsSheetOpen()) return;
    event.stopImmediatePropagation();
    if (freshItems().length) renderNotifications(freshItems());
    openNotificationsSheet({ hapticFeedback:false, keepVisible:true });
  }, true);

  document.addEventListener('mgw:notifications-refresh', event => {
    if (!isNotificationsSheetOpen()) return;
    event.stopImmediatePropagation();
    openNotificationsSheet({ hapticFeedback:false, keepVisible:true });
  }, true);

  document.addEventListener('mgw:notification-remove', event => {
    const inviteToken = String(event.detail?.inviteToken || '');
    if (!inviteToken) return;
    latestItems = latestItems.filter(item => String(item?.invite_token || '') !== inviteToken);
    latestItemsAt = Date.now();
    generation += 1;
  }, true);

  document.addEventListener('mgw:sheet-closed', invalidateOpenRequest);
  installCloseObserver();
}

function handlePointerDown(event){
  const trigger = notificationTrigger(event.target);
  if (!trigger || !isPrimaryPointer(event)) return;
  activePointer = {
    pointerId:event.pointerId,
    trigger:trigger.id,
    startX:Number(event.clientX || 0),
    startY:Number(event.clientY || 0),
    startedAt:performance.now(),
  };
}

function handlePointerUp(event){
  const pointer = activePointer;
  activePointer = null;
  if (!pointer || pointer.pointerId !== event.pointerId || !isPrimaryPointer(event)) return;
  const trigger = notificationTrigger(event.target);
  if (!trigger || trigger.id !== pointer.trigger) return;
  const dx = Number(event.clientX || 0) - pointer.startX;
  const dy = Number(event.clientY || 0) - pointer.startY;
  const duration = performance.now() - pointer.startedAt;
  if (Math.hypot(dx, dy) > TAP_MOVE_TOLERANCE_PX || duration > TAP_MAX_DURATION_MS) return;
  event.preventDefault();
  event.stopImmediatePropagation();
  suppressClickUntil = Date.now() + CLICK_SUPPRESSION_MS;
  suppressClickTarget = pointer.trigger;
  openFromUserInput();
}

function handlePointerCancel(event){
  if (activePointer?.pointerId === event.pointerId) activePointer = null;
}

function handleClickFallback(event){
  const trigger = notificationTrigger(event.target);
  if (!trigger) return;
  event.preventDefault();
  event.stopImmediatePropagation();
  if (Date.now() <= suppressClickUntil && trigger.id === suppressClickTarget) {
    suppressClickUntil = 0;
    suppressClickTarget = '';
    return;
  }
  openFromUserInput();
}

function handleKeyboardOpen(event){
  const trigger = notificationTrigger(event.target);
  if (!trigger || (event.key !== 'Enter' && event.key !== ' ')) return;
  event.preventDefault();
  event.stopImmediatePropagation();
  openFromUserInput();
}

function openFromUserInput(){
  dismissVisibleToast();
  openNotificationsSheet();
}

function notificationTrigger(target){
  const element = target instanceof Element ? target.closest('#notificationsOpen, #notificationToast') : null;
  return element instanceof HTMLElement ? element : null;
}

function isPrimaryPointer(event){
  if (event.isPrimary === false) return false;
  if (event.pointerType === 'mouse' && Number(event.button) !== 0) return false;
  return true;
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
      if (!canApply(requestGeneration)) return;
      result = await api.notifications(true);
      items = normalizeItems(result?.items);
    }
    if (!items.length && freshItems().length) items = freshItems();
    if (items.length) rememberItems(items);
    if (!canApply(requestGeneration)) return;
    setUnreadCount(0);
    renderNotifications(items);
  } catch (error) {
    if (!canApply(requestGeneration)) return;
    const cachedItems = freshItems();
    if (cachedItems.length) renderNotifications(cachedItems);
    else renderError();
  }
}

function canApply(requestGeneration){
  return requestGeneration === generation && isNotificationsSheetOpen();
}

function invalidateOpenRequest(){ generation += 1; }

function installCloseObserver(){
  const overlay = document.getElementById('sheetOverlay');
  if (!overlay) {
    document.addEventListener('DOMContentLoaded', installCloseObserver, { once:true });
    return;
  }
  let wasActive = overlay.classList.contains('active');
  new MutationObserver(() => {
    const active = overlay.classList.contains('active');
    if (wasActive && !active) invalidateOpenRequest();
    wasActive = active;
  }).observe(overlay, { attributes:true, attributeFilter:['class'] });
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

function freshItems(){ return Date.now() - latestItemsAt <= CACHE_TTL_MS ? latestItems : []; }

function renderLoading(){
  openSheet(`<div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="notifications-loading"><div>🔔</div><strong>Загружаем…</strong></div>`);
}

function renderNotifications(items){
  const body = items.length
    ? `<div class="notifications-list">${items.map(renderNotification).join('')}</div>`
    : `<div class="notifications-empty"><div>🔔</div><strong>Пока уведомлений нет</strong></div>`;
  openSheet(`<div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div>${body}`);
}

function renderNotification(item){
  const tone = ['success', 'danger', 'info', 'warning'].includes(String(item?.tone || '')) ? String(item.tone) : 'info';
  const message = notificationMessage(item);
  return `<article class="notification-card ${tone}"><div class="notification-icon">${notificationIcon(tone, item?.type)}</div><div class="notification-copy"><div class="notification-head"><strong>${escapeHtml(item?.title || 'Уведомление')}</strong><span>${escapeHtml(formatDate(item?.created_at))}</span></div>${message ? `<p>${escapeHtml(message)}</p>` : ''}${renderInviteActions(item)}</div></article>`;
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

function actionLabel(action){ return { accept:'Принять приглашение', decline:'Отклонить', start:'Начать игру', cancel:'Отменить' }[String(action || '')] || 'Открыть'; }

function renderError(){
  openSheet(`<div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="notifications-empty error"><div>⚠️</div><strong>Не удалось открыть уведомления</strong><span>Попробуйте ещё раз.</span></div>`);
}

function dismissVisibleToast(){
  const toast = document.getElementById('notificationToast');
  toast?.classList.remove('show', 'dragging');
  if (toast instanceof HTMLElement) {
    toast.style.transform = '';
    toast.style.opacity = '';
  }
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
    && String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').trim() === 'Уведомления';
}

function normalizeItems(items){ return Array.isArray(items) ? items.filter(item => item && typeof item === 'object') : []; }
function notificationIcon(tone, type = ''){ if (String(type).startsWith('invite_')) return '🎮'; if (tone === 'success') return '✓'; if (tone === 'danger' || tone === 'warning') return '!'; return 'i'; }
function notificationMessage(item){
  let message = String(item?.message || '').trim();
  if (!message) return '';
  const technicalFragments = [/\s*Баланс уже обновлён\.?/giu,/\s*Баланс не изменён\.?/giu,/\s*Баланс:\s*-?[\d\s]+\s*→\s*-?[\d\s]+\.?/giu,/\s*Статус (?:уже )?обновлён[^.]*\.?/giu,/\s*Проверьте статус возврата[^.]*\.?/giu,/\s*Статус и возврат можно проверить[^.]*\.?/giu,/\s*Возвращено\s*\+\s*[\d\s]+\s*Gold\.?/giu,/\s*Откройте Mini App[^.]*\.?/giu];
  for (const pattern of technicalFragments) message = message.replace(pattern, ' ');
  return message.replace(/\s+/g, ' ').replace(/\s+([.,!?])/g, '$1').replace(/\.{2,}/g, '.').trim();
}
function formatDate(value){ const date = new Date(value || ''); if (Number.isNaN(date.getTime())) return ''; return new Intl.DateTimeFormat('ru-RU', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' }).format(date); }
function dateValue(value){ const time = new Date(value || '').getTime(); return Number.isFinite(time) ? time : 0; }
function delay(ms){ return new Promise(resolve => window.setTimeout(resolve, ms)); }
function escapeHtml(value){ return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;'); }
