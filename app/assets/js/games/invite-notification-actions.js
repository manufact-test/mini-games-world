import { state } from '../state.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=41';
import { getTelegram, getInitData, haptic } from '../telegram/telegram-app.js?v=27';
import { getSessionId } from '../session.js?v=27';
import { showScreen } from '../router.js?v=27';
import { startGamePolling } from '../screens/game-screen.js?v=74';
import { renderBalances } from '../ui.js?v=27';

const INBOX_URL = `${window.location.origin}/bot/invite-inbox.php`;
const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const ACTIVE_INVITE_STORAGE_KEY = 'mgw_active_invite_v2';
const AUTO_OPEN_STORAGE_KEY = 'mgw_invite_auto_opened_v1';

let initialized = false;
let refreshBusy = false;
let activeInvite = null;
let waitingTimer = null;
let enhanceTimer = null;
let autoOpenedToken = loadAutoOpenedToken();

export function initInviteNotificationActions(){
  if (initialized) return;
  initialized = true;

  const sheet = document.getElementById('sheet');
  if (sheet) {
    const observer = new MutationObserver(() => {
      window.clearTimeout(enhanceTimer);
      enhanceTimer = window.setTimeout(enhanceNotifications, 70);
    });
    observer.observe(sheet, { childList:true, subtree:true });
  }

  document.addEventListener('click', event => {
    if (event.target.closest('#notificationToast')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      queueMicrotask(() => document.getElementById('notificationsOpen')?.click());
      return;
    }

    const button = event.target.closest('[data-invite-notification-action]');
    if (!button) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    handleAction(String(button.dataset.inviteNotificationAction || ''), button);
  }, true);

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') refreshInvite(true);
  });

  document.addEventListener('mgw:app-ready', () => refreshInvite(true), { once:true });

  const tg = getTelegram();
  if (typeof tg?.onEvent === 'function') {
    try {
      tg.onEvent('activated', () => refreshInvite(true));
    } catch (error) {
      // Older Telegram versions do not expose this event.
    }
  }

  window.setInterval(() => {
    if (document.visibilityState === 'visible' && !state.activeGame) refreshInvite(true);
  }, 7000);

  window.setTimeout(() => refreshInvite(true), 900);
}

async function refreshInvite(autoOpen = false){
  if (refreshBusy || state.activeGame) return;
  refreshBusy = true;

  try {
    const result = await request(INBOX_URL, {});
    syncState(result);
    activeInvite = result?.invite || null;

    if (!activeInvite?.token) {
      removeActionCard();
      return;
    }

    rememberInvite(activeInvite);
    enhanceNotifications();

    if (autoOpen && shouldAutoOpen(activeInvite)) {
      autoOpenedToken = String(activeInvite.token);
      saveAutoOpenedToken(autoOpenedToken);
      showIncoming(activeInvite);
    }
  } catch (error) {
    // A temporary inbox error must not interrupt the current screen.
  } finally {
    refreshBusy = false;
  }
}

function enhanceNotifications(){
  if (!isNotificationsOpen()) return;
  if (!activeInvite?.token) {
    refreshInvite(false);
    return;
  }

  const signature = [
    activeInvite.token,
    activeInvite.status,
    activeInvite.is_owner ? 'owner' : 'invitee',
  ].join(':');
  const existing = document.querySelector('[data-invite-notification-card]');
  if (existing?.dataset.inviteSignature === signature) return;

  removeActionCard();
  const card = document.createElement('article');
  card.className = `notification-card ${activeInvite.status === 'pending' ? 'info' : 'success'}`;
  card.dataset.inviteNotificationCard = '1';
  card.dataset.inviteSignature = signature;
  card.innerHTML = actionCard(activeInvite);

  const sheet = document.getElementById('sheet');
  const anchor = sheet?.querySelector('.notifications-list, .notifications-empty');
  if (anchor) anchor.insertAdjacentElement('beforebegin', card);
}

function actionCard(invite){
  if (invite.status === 'awaiting_start' && invite.is_owner) {
    return `
      <div class="notification-icon">✓</div>
      <div class="notification-copy">
        <div class="notification-head"><strong>Соперник согласен</strong></div>
        <p>${escapeHtml(invite.invitee_name || 'Игрок')} готов сыграть в «${escapeHtml(invite.game_title || 'игру')}».</p>
        <button class="btn primary full" data-invite-notification-action="start" type="button">Начать игру</button>
      </div>`;
  }

  if (invite.status === 'pending' && invite.is_invitee) {
    return `
      <div class="notification-icon">🎮</div>
      <div class="notification-copy">
        <div class="notification-head"><strong>Вас пригласили сыграть</strong></div>
        <p>${escapeHtml(invite.inviter_name || 'Игрок')} приглашает вас в «${escapeHtml(invite.game_title || 'игру')}».</p>
        <button class="btn primary full" data-invite-notification-action="accept" type="button">Принять приглашение</button>
        <button class="btn ghost full" data-invite-notification-action="decline" type="button">Отклонить</button>
      </div>`;
  }

  return `
    <div class="notification-icon">•</div>
    <div class="notification-copy">
      <div class="notification-head"><strong>Приглашение принято</strong></div>
      <p>Ждём, когда ${escapeHtml(invite.inviter_name || 'игрок')} запустит матч.</p>
      <button class="btn primary full" data-invite-notification-action="wait" type="button">Открыть ожидание</button>
    </div>`;
}

async function handleAction(action, button){
  if (!activeInvite?.token || !action) return;
  if (action === 'wait') return showWaiting(activeInvite);

  const oldText = button.textContent;
  setButtonsDisabled(true);
  button.textContent = action === 'start' ? 'Запускаем…' : action === 'accept' ? 'Принимаем…' : 'Отклоняем…';

  try {
    const result = await request(INVITES_URL, {
      action,
      token:String(activeInvite.token),
    });
    syncState(result);

    if (result?.game?.id) return enterGame(result.game);

    activeInvite = result?.invite || activeInvite;
    if (action === 'accept') {
      rememberInvite(activeInvite);
      showWaiting(activeInvite);
      return;
    }

    if (action === 'decline' || action === 'cancel') {
      clearInviteStorage();
      activeInvite = null;
      closeSheet();
      toast(action === 'decline' ? 'Приглашение отклонено.' : 'Ожидание отменено.');
      return;
    }

    throw new Error('Матч пока не готов.');
  } catch (error) {
    toast(error.message || 'Не удалось выполнить действие.');
    setButtonsDisabled(false);
    button.textContent = oldText;
  }
}

function showIncoming(invite){
  haptic('light');
  openSheet(`
    <div class="sheet-head">
      <div><h2>Вас приглашают сыграть</h2><p>От ${escapeHtml(invite.inviter_name || 'игрока')}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${summary(invite)}
    <div class="stack invite-actions">
      <button class="btn primary full" data-invite-notification-action="accept" type="button">Принять приглашение</button>
      <button class="btn ghost full" data-invite-notification-action="decline" type="button">Отклонить приглашение</button>
    </div>`);
}

function showWaiting(invite){
  activeInvite = invite;
  rememberInvite(invite);
  openSheet(`
    <div class="sheet-head">
      <div><h2>Ждём запуска матча</h2><p>${escapeHtml(invite.inviter_name || 'Игрок')} должен подтвердить начало.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${summary(invite)}
    <div class="small-note invite-status-note">Ожидание до ${escapeHtml(formatTime(invite.start_deadline_at))}.</div>
    <button class="btn ghost full" data-invite-notification-action="cancel" type="button">Отменить ожидание</button>`);
  startWaitingPoll();
}

function startWaitingPoll(){
  stopWaitingPoll();
  waitingTimer = window.setInterval(async () => {
    if (!activeInvite?.token || document.visibilityState !== 'visible' || state.activeGame) return;

    try {
      const result = await request(INVITES_URL, {
        action:'resolve',
        token:String(activeInvite.token),
      });
      syncState(result);
      if (result?.game?.id) return enterGame(result.game);

      activeInvite = result?.invite || activeInvite;
      if (!['pending', 'awaiting_start', 'starting', 'started'].includes(String(activeInvite.status || ''))) {
        stopWaitingPoll();
        clearInviteStorage();
        closeSheet();
        toast(activeInvite.status_label || 'Приглашение больше недоступно.');
      }
    } catch (error) {
      // Keep waiting after a temporary network error.
    }
  }, 3000);
}

function enterGame(game){
  stopWaitingPoll();
  clearInviteStorage();
  activeInvite = null;
  state.activeGame = game;
  closeSheet();
  showScreen('game');
  startGamePolling(game.id);
}

async function request(url, payload){
  const response = await fetch(url, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({ initData:getInitData(), sessionId:getSessionId(), ...payload }),
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) {
    throw new Error(data?.error || 'Сервис приглашений временно недоступен.');
  }
  return data;
}

function syncState(result){
  if (result?.user) {
    state.user = result.user;
    renderBalances(state.user);
  }
  if (result?.session) state.session = result.session;
}

function summary(invite){
  return `<div class="topup-success">
    <div><span>Игра</span><strong>${escapeHtml(invite.game_title || 'Игра')}</strong></div>
    <div><span>Комната</span><strong>${escapeHtml(invite.room_label || 'Матч-комната')}</strong></div>
    <div><span>Вариант</span><strong>${escapeHtml(boardLabel(invite))}</strong></div>
    <div><span>Ставка</span><strong>${Number(invite.bet || 0)} коинов</strong></div>
  </div>`;
}

function shouldAutoOpen(invite){
  if (!invite?.is_invitee || invite.status !== 'pending') return false;
  if (String(invite.token || '') === autoOpenedToken) return false;
  if (document.visibilityState !== 'visible' || state.activeGame) return false;
  const screen = document.querySelector('.screen.active');
  if (String(screen?.dataset.screen || '') !== 'home') return false;
  return !document.getElementById('sheetOverlay')?.classList.contains('active');
}

function isNotificationsOpen(){
  if (!document.getElementById('sheetOverlay')?.classList.contains('active')) return false;
  return String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').trim() === 'Уведомления';
}

function rememberInvite(invite){
  try {
    localStorage.setItem(ACTIVE_INVITE_STORAGE_KEY, JSON.stringify({
      token:String(invite.token || ''),
      status:String(invite.status || 'pending'),
      role:invite.is_owner ? 'owner' : 'invitee',
    }));
  } catch (error) {
    // The current in-memory invite remains available.
  }
}

function clearInviteStorage(){
  try { localStorage.removeItem(ACTIVE_INVITE_STORAGE_KEY); } catch (error) {}
}

function loadAutoOpenedToken(){
  try { return String(sessionStorage.getItem(AUTO_OPEN_STORAGE_KEY) || ''); } catch (error) { return ''; }
}

function saveAutoOpenedToken(token){
  try { sessionStorage.setItem(AUTO_OPEN_STORAGE_KEY, token); } catch (error) {}
}

function removeActionCard(){
  document.querySelectorAll('[data-invite-notification-card]').forEach(card => card.remove());
}

function setButtonsDisabled(disabled){
  document.querySelectorAll('[data-invite-notification-action]').forEach(button => { button.disabled = disabled; });
}

function stopWaitingPoll(){
  if (waitingTimer !== null) window.clearInterval(waitingTimer);
  waitingTimer = null;
}

function boardLabel(invite){
  const size = Number(invite.board_size || 0);
  if (invite.game_type === 'domino') return 'Классика 0–6';
  if (invite.game_type === 'four_in_a_row') return `${size}×${Number(invite.board_rows || Math.max(5, size - 1))}`;
  return `${size}×${size}`;
}

function formatTime(value){
  const date = new Date(String(value || ''));
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleTimeString('ru-RU', { hour:'2-digit', minute:'2-digit' });
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
