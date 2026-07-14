import { state } from '../state.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=41';
import { getTelegram, getInitData, haptic } from '../telegram/telegram-app.js?v=27';
import { getSessionId } from '../session.js?v=27';
import { showScreen } from '../router.js?v=27';
import { startGamePolling } from '../screens/game-screen.js?v=74';
import { refreshNotificationBadge } from '../screens/notifications-screen.js?v=80';
import { renderBalances } from '../ui.js?v=27';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const INBOX_URL = `${window.location.origin}/bot/invite-inbox.php`;
const ACTIVE_INVITE_STORAGE_KEY = 'mgw_active_invite_v2';
const POLL_MS = 1800;

let initialized = false;
let pollTimer = null;
let pollBusy = false;
let surfaceTimer = null;
let sheetObserver = null;
let currentInvite = null;
let lastSignature = '';
let notificationsPrimed = false;
let shareReturnToken = '';
let shareReturnTimer = null;

export function initInviteLiveFlow(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('mgw:invite-adopt', event => {
    const result = event.detail?.result || event.detail || null;
    adoptResult(result, { announce:false, forceSurface:true });
  });

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-live-invite-action]');
    if (!button) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    performAction(String(button.dataset.liveInviteAction || ''), button);
  }, true);

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
      armShareReturnFromOpenSheet();
      return;
    }

    finishShareReturn();
    syncNow(true);
  });

  document.addEventListener('mgw:app-ready', async () => {
    await primeNotifications();
    await syncNow(false);
  }, { once:true });

  document.addEventListener('mgw:game-dismissed', () => {
    window.setTimeout(() => syncNow(true), 120);
  });

  const sheet = document.getElementById('sheet');
  if (sheet) {
    sheetObserver = new MutationObserver(() => {
      scheduleSurfaceSync();
      scheduleShareReturnFallback();
    });
    sheetObserver.observe(sheet, { childList:true, subtree:true });
  }

  const tg = getTelegram();
  if (typeof tg?.onEvent === 'function') {
    try {
      tg.onEvent('activated', () => {
        finishShareReturn();
        syncNow(true);
      });
    } catch (error) {
      // Older Telegram clients do not expose the activated event.
    }
  }

  pollTimer = window.setInterval(() => syncNow(true), POLL_MS);
  primeNotifications();
}

async function primeNotifications(){
  if (notificationsPrimed) return;
  notificationsPrimed = true;
  try {
    await refreshNotificationBadge(false);
  } catch (error) {
    // A later regular refresh will retry without showing old notifications.
  }
}

async function syncNow(announce){
  if (pollBusy || document.visibilityState !== 'visible') return null;
  if (String(state.activeGame?.status || '') === 'active') return null;

  pollBusy = true;
  try {
    const storedToken = loadStoredToken();
    let resolvedResult = null;
    let inboxResult = null;

    if (storedToken) {
      try {
        resolvedResult = await inviteRequest('resolve', { token:storedToken });
        syncState(resolvedResult);
        if (resolvedResult?.game?.id && String(resolvedResult.game.status || '') === 'active') {
          enterGame(resolvedResult);
          return resolvedResult;
        }
      } catch (error) {
        forgetStoredToken(storedToken);
      }
    }

    try {
      inboxResult = await inboxRequest();
      syncState(inboxResult);
    } catch (error) {
      inboxResult = null;
    }

    const invite = chooseInvite(resolvedResult?.invite, inboxResult?.invite);
    if (invite?.token) {
      adoptInvite(invite, { announce, forceSurface:false });
      return invite;
    }

    if (currentInvite && isTerminalStatus(currentInvite.status)) {
      clearInvite(String(currentInvite.token || ''));
    }

    scheduleSurfaceSync();
    return null;
  } finally {
    pollBusy = false;
  }
}

function chooseInvite(resolvedInvite, inboxInvite){
  const candidates = [resolvedInvite, inboxInvite].filter(item => item?.token);
  if (!candidates.length) return null;

  candidates.sort((left, right) => invitePriority(right) - invitePriority(left));
  return candidates[0];
}

function invitePriority(invite){
  const status = String(invite?.status || '');
  if (status === 'awaiting_start' && invite?.is_owner) return 100;
  if (status === 'pending' && invite?.is_invitee) return 90;
  if (status === 'awaiting_start' && invite?.is_invitee) return 80;
  if (status === 'pending' && invite?.is_owner) return 50;
  if (status === 'starting' || status === 'started') return 30;
  return 0;
}

function adoptResult(result, options = {}){
  syncState(result);
  if (result?.game?.id && String(result.game.status || '') === 'active') {
    enterGame(result);
    return;
  }

  if (result?.invite?.token) {
    adoptInvite(result.invite, options);
  }
}

function adoptInvite(invite, { announce = true, forceSurface = false } = {}){
  const token = String(invite?.token || '');
  if (!token) return;

  const previousSignature = lastSignature;
  currentInvite = { ...(currentInvite || {}), ...invite };
  lastSignature = inviteSignature(currentInvite);
  rememberInvite(currentInvite);

  if (announce && previousSignature !== lastSignature && !isIncomingDeepLink(token)) {
    primeNotifications().then(() => refreshNotificationBadge(true));
  }

  if (isTerminalStatus(currentInvite.status)) {
    forgetStoredToken(token);
  }

  if (forceSurface) syncSurfaces(true);
  else scheduleSurfaceSync();
}

function scheduleSurfaceSync(){
  window.clearTimeout(surfaceTimer);
  surfaceTimer = window.setTimeout(() => syncSurfaces(false), 50);
}

function syncSurfaces(force){
  if (!currentInvite?.token) return;

  if (isNotificationsSheetOpen()) {
    enhanceNotificationsSheet(currentInvite);
    return;
  }

  const marker = document.querySelector('#sheet [data-invite-sheet][data-invite-token]');
  const sheetToken = String(marker?.dataset.inviteToken || '');
  if (sheetToken !== String(currentInvite.token || '')) return;

  const status = String(currentInvite.status || '');
  if (status === 'awaiting_start' && currentInvite.is_owner) {
    renderOwnerReady(currentInvite, force);
    return;
  }

  if (status === 'awaiting_start' && currentInvite.is_invitee) {
    renderInviteeWaiting(currentInvite, force);
    return;
  }

  if (status === 'pending' && currentInvite.is_owner && shareReturnToken === sheetToken) {
    renderOwnerWaiting(currentInvite, force);
  }
}

function enhanceNotificationsSheet(invite){
  const status = String(invite.status || '');
  const actionable = (status === 'pending' && invite.is_invitee)
    || (status === 'awaiting_start' && (invite.is_owner || invite.is_invitee));
  if (!actionable) return;

  const title = status === 'pending'
    ? 'Вас пригласили сыграть'
    : (invite.is_owner ? 'Соперник согласен' : 'Приглашение принято');

  const cards = Array.from(document.querySelectorAll('#sheet .notification-card'));
  let card = document.querySelector('#sheet [data-invite-action-card]');
  if (!card) {
    card = cards.find(item => String(item.querySelector('.notification-head strong')?.textContent || '').trim() === title) || null;
  }
  if (!card) return;

  const signature = inviteSignature(invite);
  if (card.dataset.liveInviteSignature === signature && card.querySelector('[data-live-invite-actions]')) return;

  card.dataset.liveInviteSignature = signature;
  let copy = card.querySelector('.notification-copy');
  if (!copy) {
    card.innerHTML = notificationCardBody(invite);
    copy = card.querySelector('.notification-copy');
  }
  if (!copy) return;

  copy.querySelector('[data-live-invite-actions]')?.remove();
  copy.insertAdjacentHTML('beforeend', notificationActions(invite));
}

function notificationCardBody(invite){
  const status = String(invite.status || '');
  const title = status === 'pending'
    ? 'Вас пригласили сыграть'
    : (invite.is_owner ? 'Соперник согласен' : 'Приглашение принято');
  const message = status === 'pending'
    ? `${invite.inviter_name || 'Игрок'} приглашает вас в «${invite.game_title || 'игру'}».`
    : (invite.is_owner
      ? `${invite.invitee_name || 'Игрок'} готов сыграть в «${invite.game_title || 'игру'}».`
      : `Ждём, когда ${invite.inviter_name || 'игрок'} запустит матч.`);

  return `
    <div class="notification-icon">🎮</div>
    <div class="notification-copy">
      <div class="notification-head"><strong>${escapeHtml(title)}</strong></div>
      <p>${escapeHtml(message)}</p>
      ${notificationActions(invite)}
    </div>
  `;
}

function notificationActions(invite){
  const token = escapeHtml(invite.token || '');
  const status = String(invite.status || '');

  if (status === 'pending' && invite.is_invitee) {
    return `
      <div class="notification-actions invite-live-actions" data-live-invite-actions>
        <button class="btn primary full" data-live-invite-action="accept" data-invite-token="${token}" type="button">Принять приглашение</button>
        <button class="btn ghost full" data-live-invite-action="decline" data-invite-token="${token}" type="button">Отклонить</button>
      </div>
    `;
  }

  if (status === 'awaiting_start' && invite.is_owner) {
    return `
      <div class="notification-actions invite-live-actions" data-live-invite-actions>
        <button class="btn primary full" data-live-invite-action="start" data-invite-token="${token}" type="button">Начать игру</button>
        <button class="btn ghost full" data-live-invite-action="cancel" data-invite-token="${token}" type="button">Отменить</button>
      </div>
    `;
  }

  return `
    <div class="notification-actions invite-live-actions" data-live-invite-actions>
      <button class="btn primary full" data-live-invite-action="wait" data-invite-token="${token}" type="button">Открыть ожидание</button>
      <button class="btn ghost full" data-live-invite-action="cancel" data-invite-token="${token}" type="button">Отменить</button>
    </div>
  `;
}

async function performAction(action, button){
  const token = String(button?.dataset.inviteToken || currentInvite?.token || '');
  if (!token || !action) return;

  if (action === 'wait') {
    if (currentInvite) renderInviteeWaiting(currentInvite, true);
    return;
  }

  haptic('light');
  const originalText = button.textContent;
  disableLiveButtons(true);
  button.textContent = action === 'start'
    ? 'Запускаем…'
    : action === 'accept'
      ? 'Принимаем…'
      : action === 'decline'
        ? 'Отклоняем…'
        : 'Отменяем…';

  try {
    const result = await inviteRequest(action, { token });
    syncState(result);

    if (result?.game?.id && String(result.game.status || '') === 'active') {
      enterGame(result);
      return;
    }

    if (result?.invite?.token) {
      adoptInvite(result.invite, { announce:false, forceSurface:true });
    }

    if (action === 'accept' && result?.invite) {
      renderInviteeWaiting(result.invite, true);
      refreshNotificationBadge(false);
      return;
    }

    if (action === 'decline' || action === 'cancel') {
      clearInvite(token);
      closeSheet();
      refreshNotificationBadge(false);
      toast(action === 'decline' ? 'Приглашение отклонено.' : 'Приглашение отменено.');
      return;
    }

    disableLiveButtons(false);
  } catch (error) {
    toast(error.message || 'Не удалось выполнить действие.');
    disableLiveButtons(false);
    button.textContent = originalText;
  }
}

function renderOwnerReady(invite, force){
  const signature = `owner-ready:${inviteSignature(invite)}`;
  if (!force && currentSheetSignature() === signature) return;

  openSheet(`
    ${inviteMarker(invite, signature)}
    <div class="sheet-head">
      <div>
        <h2>Соперник согласен</h2>
        <p>${escapeHtml(invite.invitee_name || 'Игрок')} готов играть.</p>
      </div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">Запустите матч до ${escapeHtml(formatTime(invite.start_deadline_at))}. Коины спишутся после запуска.</div>
    <div class="stack invite-actions">
      <button class="btn primary full" data-live-invite-action="start" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Начать игру</button>
      <button class="btn ghost full" data-live-invite-action="cancel" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Отменить приглашение</button>
    </div>
  `);
}

function renderInviteeWaiting(invite, force){
  const signature = `invitee-wait:${inviteSignature(invite)}`;
  if (!force && currentSheetSignature() === signature) return;

  openSheet(`
    ${inviteMarker(invite, signature)}
    <div class="sheet-head">
      <div>
        <h2>Приглашение принято</h2>
        <p>Ждём запуска матча от ${escapeHtml(invite.inviter_name || 'игрока')}.</p>
      </div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">Ожидание до ${escapeHtml(formatTime(invite.start_deadline_at))}.</div>
    <button class="btn ghost full" data-live-invite-action="cancel" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Отменить ожидание</button>
  `);
}

function renderOwnerWaiting(invite, force){
  const signature = `owner-wait:${inviteSignature(invite)}`;
  if (!force && currentSheetSignature() === signature) return;

  openSheet(`
    ${inviteMarker(invite, signature)}
    <div class="sheet-head">
      <div><h2>Приглашение отправлено</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">Ждём ответа игрока. Коины пока не списываются.</div>
    <button class="btn ghost full" data-live-invite-action="cancel" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Отменить приглашение</button>
  `);
}

function armShareReturnFromOpenSheet(){
  const heading = String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').trim();
  if (heading !== 'Приглашение готово') return;
  const token = String(document.querySelector('#sheet [data-invite-sheet][data-invite-token]')?.dataset.inviteToken || '');
  if (token) shareReturnToken = token;
}

function scheduleShareReturnFallback(){
  window.clearTimeout(shareReturnTimer);
  const heading = String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').trim();
  if (heading !== 'Приглашение готово') return;
  const token = String(document.querySelector('#sheet [data-invite-sheet][data-invite-token]')?.dataset.inviteToken || '');
  if (!token || !document.querySelector('#sheet [data-share-invite]')) return;

  shareReturnTimer = window.setTimeout(() => {
    shareReturnToken = token;
    finishShareReturn();
  }, 1600);
}

function finishShareReturn(){
  if (!shareReturnToken || !currentInvite || String(currentInvite.token || '') !== shareReturnToken) return;
  if (String(currentInvite.status || '') !== 'pending' || !currentInvite.is_owner) return;
  renderOwnerWaiting(currentInvite, true);
}

function enterGame(result){
  const game = result?.game;
  if (!game?.id || String(game.status || '') !== 'active') return;

  const token = String(result?.invite?.token || currentInvite?.token || '');
  clearInvite(token);
  syncState(result);
  state.activeGame = game;
  closeSheet();
  showScreen('game');
  startGamePolling(game.id);
}

function syncState(result){
  if (result?.user) {
    state.user = result.user;
    renderBalances(state.user);
  }
  if (result?.session) state.session = result.session;
}

function disableLiveButtons(disabled){
  document.querySelectorAll('[data-live-invite-action]').forEach(button => {
    button.disabled = disabled;
  });
}

function rememberInvite(invite){
  try {
    localStorage.setItem(ACTIVE_INVITE_STORAGE_KEY, JSON.stringify({
      token:String(invite.token || ''),
      status:String(invite.status || 'pending'),
      role:invite.is_owner ? 'owner' : (invite.is_invitee ? 'invitee' : 'guest'),
    }));
  } catch (error) {
    // Current-session state remains sufficient when storage is unavailable.
  }
}

function loadStoredToken(){
  try {
    const value = JSON.parse(localStorage.getItem(ACTIVE_INVITE_STORAGE_KEY) || 'null');
    const token = String(value?.token || '');
    return /^[a-f0-9]{24}$/.test(token) ? token : '';
  } catch (error) {
    return '';
  }
}

function forgetStoredToken(token){
  try {
    const value = JSON.parse(localStorage.getItem(ACTIVE_INVITE_STORAGE_KEY) || 'null');
    if (!token || String(value?.token || '') === token) {
      localStorage.removeItem(ACTIVE_INVITE_STORAGE_KEY);
    }
  } catch (error) {
    // Nothing else is required.
  }
}

function clearInvite(token){
  if (!token || String(currentInvite?.token || '') === token) {
    currentInvite = null;
    lastSignature = '';
  }
  forgetStoredToken(token);
  document.querySelectorAll('[data-live-invite-actions]').forEach(node => node.remove());
}

function inviteSignature(invite){
  return [
    String(invite?.token || ''),
    String(invite?.status || ''),
    invite?.is_owner ? 'owner' : (invite?.is_invitee ? 'invitee' : 'guest'),
  ].join(':');
}

function currentSheetSignature(){
  return String(document.querySelector('#sheet [data-live-invite-status]')?.dataset.liveInviteStatus || '');
}

function inviteMarker(invite, signature){
  return `<span data-invite-sheet data-invite-token="${escapeHtml(invite.token || '')}" data-live-invite-status="${escapeHtml(signature)}" hidden></span>`;
}

function inviteSummary(invite){
  return `
    <div class="topup-success">
      <div><span>Игра</span><strong>${escapeHtml(invite.game_title || 'Игра')}</strong></div>
      <div><span>Комната</span><strong>${escapeHtml(invite.room_label || roomLabel(invite.room))}</strong></div>
      <div><span>Вариант</span><strong>${escapeHtml(boardLabel(invite))}</strong></div>
      <div><span>Ставка</span><strong>${Number(invite.bet || 0)} коинов</strong></div>
    </div>
  `;
}

function boardLabel(invite){
  const gameType = String(invite?.game_type || '');
  const size = Number(invite?.board_size || 0);
  if (gameType === 'domino') return 'Классика 0–6';
  if (gameType === 'four_in_a_row') return `${size}×${Number(invite?.board_rows || Math.max(5, size - 1))}`;
  return `${size}×${size}`;
}

function roomLabel(room){
  return String(room || '') === 'gold' ? 'Gold-комната' : 'Матч-комната';
}

function formatTime(value){
  const date = new Date(String(value || ''));
  if (Number.isNaN(date.getTime())) return '—';
  return date.toLocaleTimeString('ru-RU', { hour:'2-digit', minute:'2-digit' });
}

function isNotificationsSheetOpen(){
  const overlay = document.getElementById('sheetOverlay');
  if (!overlay?.classList.contains('active')) return false;
  return String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').trim() === 'Уведомления';
}

function isIncomingDeepLink(token){
  const startParam = String(getTelegram()?.initDataUnsafe?.start_param || '');
  const fromTelegram = startParam.startsWith('invite_') ? startParam.slice(7) : '';
  const fromQuery = new URLSearchParams(window.location.search).get('invite') || '';
  return String(fromTelegram || fromQuery).toLowerCase() === String(token || '').toLowerCase();
}

function isTerminalStatus(status){
  return ['declined', 'expired', 'timed_out', 'cancelled'].includes(String(status || ''));
}

async function inviteRequest(action, payload = {}){
  return postJson(INVITES_URL, { action, ...payload });
}

async function inboxRequest(){
  return postJson(INBOX_URL, {});
}

async function postJson(url, payload){
  const response = await fetch(url, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({
      initData:getInitData(),
      sessionId:getSessionId(),
      ...payload,
    }),
  });

  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) {
    if (response.status === 429) throw new Error('Слишком много запросов. Попробуйте чуть позже.');
    throw new Error(data?.error || 'Сервис приглашений временно недоступен.');
  }
  return data;
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
