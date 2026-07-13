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
const INBOX_POLL_MS = 7000;

let initialized = false;
let refreshBusy = false;
let currentInvite = null;
let sheetObserver = null;
let sheetRefreshTimer = null;
let waitingTimer = null;
let lastAutoOpenedToken = loadAutoOpenedToken();

export function initInviteInboxActions(){
  if (initialized) return;
  initialized = true;

  const sheet = document.getElementById('sheet');
  if (sheet) {
    sheetObserver = new MutationObserver(() => scheduleNotificationEnhancement());
    sheetObserver.observe(sheet, { childList:true, subtree:true });
  }

  document.addEventListener('click', event => {
    const toastCard = event.target.closest('#notificationToast');
    if (toastCard) {
      event.preventDefault();
      event.stopImmediatePropagation();
      queueMicrotask(() => document.getElementById('notificationsOpen')?.click());
      return;
    }

    const button = event.target.closest('[data-invite-inbox-action]');
    if (!button) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    performInviteAction(String(button.dataset.inviteInboxAction || ''), button);
  }, true);

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') refreshInbox({ autoOpen:true });
  });

  document.addEventListener('mgw:app-ready', () => {
    refreshInbox({ autoOpen:true });
  }, { once:true });

  const tg = getTelegram();
  if (typeof tg?.onEvent === 'function') {
    try {
      tg.onEvent('activated', () => refreshInbox({ autoOpen:true }));
    } catch (error) {
      // Older Telegram clients may not support the activated event.
    }
  }

  window.setInterval(() => {
    if (document.visibilityState === 'visible' && !state.activeGame) {
      refreshInbox({ autoOpen:true });
    }
  }, INBOX_POLL_MS);

  window.setTimeout(() => refreshInbox({ autoOpen:true }), 900);
}

async function refreshInbox({ autoOpen = false } = {}){
  if (refreshBusy || state.activeGame) return null;
  refreshBusy = true;

  try {
    const result = await postJson(INBOX_URL, {});
    syncState(result);
    currentInvite = result?.invite || null;

    if (!currentInvite?.token) {
      removeInboxCard();
      return null;
    }

    rememberInvite(currentInvite);
    enhanceNotificationsSheet(currentInvite);

    if (autoOpen && shouldAutoOpen(currentInvite)) {
      markAutoOpened(currentInvite.token);
      if (String(currentInvite.status || '') === 'pending') {
        showIncomingInvite(currentInvite);
      } else if (String(currentInvite.status || '') === 'awaiting_start' && currentInvite.is_invitee) {
        showWaitingInvite(currentInvite);
      }
    }

    return currentInvite;
  } catch (error) {
    return null;
  } finally {
    refreshBusy = false;
  }
}

function scheduleNotificationEnhancement(){
  window.clearTimeout(sheetRefreshTimer);
  sheetRefreshTimer = window.setTimeout(() => {
    if (!isNotificationsSheetOpen()) return;
    if (currentInvite?.token) {
      enhanceNotificationsSheet(currentInvite);
    } else {
      refreshInbox({ autoOpen:false });
    }
  }, 80);
}

function enhanceNotificationsSheet(invite){
  if (!isNotificationsSheetOpen() || !invite?.token) return;

  removeInboxCard();
  const card = document.createElement('article');
  card.className = `notification-card ${inviteTone(invite)}`;
  card.dataset.inviteInboxCard = '1';
  card.innerHTML = inviteCardHtml(invite);

  const sheet = document.getElementById('sheet');
  const anchor = sheet?.querySelector('.notifications-list, .notifications-empty');
  if (anchor) anchor.insertAdjacentElement('beforebegin', card);
  else sheet?.appendChild(card);
}

function inviteCardHtml(invite){
  const status = String(invite.status || '');

  if (status === 'awaiting_start' && invite.is_owner) {
    return `
      <div class="notification-icon">✓</div>
      <div class="notification-copy">
        <div class="notification-head"><strong>Соперник согласен</strong></div>
        <p>${escapeHtml(invite.invitee_name || 'Игрок')} готов сыграть в «${escapeHtml(invite.game_title || 'игру')}».</p>
        <button class="btn primary full" data-invite-inbox-action="start" type="button">Начать игру</button>
      </div>
    `;
  }

  if (status === 'pending' && invite.is_invitee) {
    return `
      <div class="notification-icon">🎮</div>
      <div class="notification-copy">
        <div class="notification-head"><strong>Вас пригласили сыграть</strong></div>
        <p>${escapeHtml(invite.inviter_name || 'Игрок')} приглашает вас в «${escapeHtml(invite.game_title || 'игру')}».</p>
        <button class="btn primary full" data-invite-inbox-action="accept" type="button">Принять приглашение</button>
        <button class="btn ghost full" data-invite-inbox-action="decline" type="button">Отклонить</button>
      </div>
    `;
  }

  return `
    <div class="notification-icon">•</div>
    <div class="notification-copy">
      <div class="notification-head"><strong>Приглашение принято</strong></div>
      <p>Ждём, когда ${escapeHtml(invite.inviter_name || 'игрок')} запустит матч.</p>
      <button class="btn primary full" data-invite-inbox-action="wait" type="button">Открыть ожидание</button>
    </div>
  `;
}

async function performInviteAction(action, button){
  if (!currentInvite?.token || !action) return;

  if (action === 'wait') {
    showWaitingInvite(currentInvite);
    return;
  }

  const originalText = button.textContent;
  setActionButtonsDisabled(true);
  button.textContent = action === 'start'
    ? 'Запускаем…'
    : action === 'accept'
      ? 'Принимаем…'
      : 'Отклоняем…';

  try {
    const result = await postJson(INVITES_URL, {
      action,
      token:String(currentInvite.token || ''),
    });
    syncState(result);

    if (result?.game?.id) {
      enterGame(result.game);
      return;
    }

    currentInvite = result?.invite || currentInvite;
    if (action === 'accept') {
      rememberInvite(currentInvite);
      showWaitingInvite(currentInvite);
      startWaitingPolling();
      return;
    }

    if (action === 'decline' || action === 'cancel') {
      clearStoredInvite();
      currentInvite = null;
      closeSheet();
      toast(action === 'decline' ? 'Приглашение отклонено.' : 'Ожидание отменено.');
      return;
    }

    throw new Error('Матч пока не готов.');
  } catch (error) {
    toast(error.message || 'Не удалось выполнить действие.');
    setActionButtonsDisabled(false);
    button.textContent = originalText;
  }
}

function showIncomingInvite(invite){
  haptic('light');
  openSheet(`
    <div class="sheet-head">
      <div>
        <h2>Вас приглашают сыграть</h2>
        <p>От ${escapeHtml(invite.inviter_name || 'игрока')}</p>
      </div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="stack invite-actions">
      <button class="btn primary full" data-invite-inbox-action="accept" type="button">Принять приглашение</button>
      <button class="btn ghost full" data-invite-inbox-action="decline" type="button">Отклонить приглашение</button>
    </div>
  `);
}

function showWaitingInvite(invite){
  currentInvite = invite;
  rememberInvite(invite);
  haptic('light');
  openSheet(`
    <div class="sheet-head">
      <div>
        <h2>Ждём запуска матча</h2>
        <p>${escapeHtml(invite.inviter_name || 'Игрок')} должен подтвердить начало.</p>
      </div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">Ожидание до ${escapeHtml(formatTime(invite.start_deadline_at))}.</div>
    <button class="btn ghost full" data-invite-inbox-action="cancel" type="button">Отменить ожидание</button>
  `);
  startWaitingPolling();
}

function startWaitingPolling(){
  stopWaitingPolling();
  waitingTimer = window.setInterval(async () => {
    if (!currentInvite?.token || document.visibilityState !== 'visible' || state.activeGame) return;

    try {
      const result = await postJson(INVITES_URL, {
        action:'resolve',
        token:String(currentInvite.token || ''),
      });
      syncState(result);

      if (result?.game?.id) {
        enterGame(result.game);
        return;
      }

      currentInvite = result?.invite || currentInvite;
      const status = String(currentInvite.status || '');
      if (!['pending', 'awaiting_start', 'starting', 'started'].includes(status)) {
        stopWaitingPolling();
        clearStoredInvite();
        closeSheet();
        toast(currentInvite.status_label || 'Приглашение больше недоступно.');
      }
    } catch (error) {
      // Temporary polling errors must not close the waiting screen.
    }
  }, 3000);
}

function enterGame(game){
  if (!game?.id) return;
  stopWaitingPolling();
  clearStoredInvite();
  currentInvite = null;
  state.activeGame = game;
  closeSheet();
  showScreen('game');
  startGamePolling(game.id);
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

function rememberInvite(invite){
  const token = String(invite?.token || '');
  if (!token) return;
  try {
    localStorage.setItem(ACTIVE_INVITE_STORAGE_KEY, JSON.stringify({
      token,
      status:String(invite.status || 'pending'),
      role:invite.is_owner ? 'owner' : (invite.is_invitee ? 'invitee' : 'guest'),
    }));
  } catch (error) {
    // The invitation still works in memory if storage is unavailable.
  }
}

function clearStoredInvite(){
  try {
    localStorage.removeItem(ACTIVE_INVITE_STORAGE_KEY);
  } catch (error) {
    // No further cleanup is required.
  }
}

function shouldAutoOpen(invite){
  const token = String(invite?.token || '');
  if (!token || token === lastAutoOpenedToken) return false;
  if (!invite.is_invitee) return false;
  if (!['pending', 'awaiting_start'].includes(String(invite.status || ''))) return false;
  if (document.visibilityState !== 'visible' || state.activeGame) return false;

  const activeScreen = document.querySelector('.screen.active');
  if (String(activeScreen?.dataset.screen || '') !== 'home') return false;
  return !document.getElementById('sheetOverlay')?.classList.contains('active');
}

function markAutoOpened(token){
  lastAutoOpenedToken = String(token || '');
  try {
    sessionStorage.setItem(AUTO_OPEN_STORAGE_KEY, lastAutoOpenedToken);
  } catch (error) {
    // In-memory protection is enough for the current session.
  }
}

function loadAutoOpenedToken(){
  try {
    return String(sessionStorage.getItem(AUTO_OPEN_STORAGE_KEY) || '');
  } catch (error) {
    return '';
  }
}

function isNotificationsSheetOpen(){
  const overlay = document.getElementById('sheetOverlay');
  if (!overlay?.classList.contains('active')) return false;
  return String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').trim() === 'Уведомления';
}

function removeInboxCard(){
  document.querySelectorAll('[data-invite-inbox-card]').forEach(card => card.remove());
}

function setActionButtonsDisabled(disabled){
  document.querySelectorAll('[data-invite-inbox-action]').forEach(button => {
    button.disabled = disabled;
  });
}

function stopWaitingPolling(){
  if (waitingTimer !== null) window.clearInterval(waitingTimer);
  waitingTimer = null;
}

function inviteTone(invite){
  return String(invite.status || '') === 'pending' ? 'info' : 'success';
}

function inviteSummary(invite){
  return `
    <div class="topup-success">
      <div><span>Игра</span><strong>${escapeHtml(invite.game_title || 'Игра')}</strong></div>
      <div><span>Комната</span><strong>${escapeHtml(invite.room_label || 'Матч-комната')}</strong></div>
      <div><span>Вариант</span><strong>${escapeHtml(boardLabel(invite))}</strong></div>
      <div><span>Ставка</span><strong>${Number(invite.bet || 0)} коинов</strong></div>
    </div>
  `;
}

function boardLabel(invite){
  const gameType = String(invite.game_type || '');
  const size = Number(invite.board_size || 0);
  if (gameType === 'domino') return 'Классика 0–6';
  if (gameType === 'four_in_a_row') return `${size}×${Number(invite.board_rows || Math.max(5, size - 1))}`;
  return `${size}×${size}`;
}

function formatTime(value){
  const date = new Date(String(value || ''));
  if (Number.isNaN(date.getTime())) return '—';
  return date.toLocaleTimeString('ru-RU', { hour:'2-digit', minute:'2-digit' });
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
