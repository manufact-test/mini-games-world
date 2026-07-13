import { state } from '../state.js?v=27';
import { APP_CONFIG } from '../config.js?v=38';
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
const AUTO_OPEN_STORAGE_KEY = 'mgw_invite_auto_opened_v2';
const INVITE_POLL_MS = 1500;

const GAME_OPTIONS = {
  tictactoe: { title:'Крестики-нолики', sizes:[3,5,9], defaultSize:3 },
  four_in_a_row: { title:'4 в ряд', sizes:[6,7,8], defaultSize:7 },
  battleship: { title:'Морской бой', sizes:[10], defaultSize:10 },
  checkers: { title:'Шашки', sizes:[8], defaultSize:8 },
  reversi: { title:'Реверси', sizes:[6,8,10], defaultSize:8 },
  chess: { title:'Шахматы', sizes:[8], defaultSize:8 },
  go: { title:'Го', sizes:[9,13], defaultSize:9 },
  domino: { title:'Домино', sizes:[7], defaultSize:7 },
};

let initialized = false;
let pollTimer = null;
let pollBusy = false;
let sheetObserver = null;
let sheetEnhanceTimer = null;
let activeInvite = loadStoredInvite();
let activeInviteData = null;
let autoOpenedToken = loadAutoOpenedToken();

export function initGameInvites(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('click', event => {
    const actionButton = event.target.closest('[data-invite-action]');
    if (actionButton) {
      event.preventDefault();
      event.stopImmediatePropagation();
      performInviteAction(String(actionButton.dataset.inviteAction || ''), actionButton);
      return;
    }

    const target = event.target.closest('button, [role="button"]');
    if (!target) return;

    if (hasReadyCheck() && isGameLaunchControl(target)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      toast('Сначала запустите или отмените подтверждённое приглашение.');
      openStoredInvite();
      return;
    }

    const inviteButton = target.closest('[data-invite-friend]');
    if (!inviteButton) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    openInviteSetup(String(inviteButton.dataset.inviteFriend || 'tictactoe'));
  }, true);

  const sheet = document.getElementById('sheet');
  if (sheet) {
    sheetObserver = new MutationObserver(scheduleNotificationEnhancement);
    sheetObserver.observe(sheet, { childList:true, subtree:true });
  }

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') pollInvitesNow();
  });

  document.addEventListener('mgw:app-ready', () => pollInvitesNow(), { once:true });
  document.addEventListener('mgw:game-dismissed', () => clearActiveInvite());

  const tg = getTelegram();
  if (typeof tg?.onEvent === 'function') {
    try {
      tg.onEvent('activated', () => pollInvitesNow());
    } catch (error) {
      // Older Telegram clients may not expose the activated event.
    }
  }

  startInvitePolling();
}

export async function openIncomingInviteIfPresent(){
  if (state.activeGame) return;

  const directToken = incomingToken();
  if (directToken) {
    try {
      const result = await inviteRequest('resolve', { token:directToken });
      handleInviteResult(result, { forceOpen:true });
    } catch (error) {
      toast(error.message || 'Приглашение уже недоступно.');
    }
    return;
  }

  await pollInvitesNow();
}

function openInviteSetup(gameType){
  if (hasReadyCheck()) return openStoredInvite();

  const option = GAME_OPTIONS[gameType] || GAME_OPTIONS.tictactoe;
  const room = state.room === 'gold' ? 'gold' : 'match';
  let boardSize = option.defaultSize;
  let bet = room === 'gold' && APP_CONFIG.goldBets.includes(Number(state.selectedBet))
    ? Number(state.selectedBet)
    : (room === 'gold' ? APP_CONFIG.goldBets[0] : APP_CONFIG.matchBet);

  haptic('light');
  openSheet(`
    <span data-invite-sheet hidden></span>
    <div class="sheet-head">
      <div>
        <h2>Пригласить в «${escapeHtml(option.title)}»</h2>
        <p>${room === 'gold' ? 'Gold-комната' : 'Матч-комната'}. Выберите условия для друга.</p>
      </div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="setup-scroll">
      <div class="small-note">Ссылка действует 15 минут. Коины спишутся только после запуска матча.</div>
      <div class="section-title"><h2>Вариант игры</h2></div>
      <div class="choice-grid field-size-grid" data-invite-sizes>
        ${option.sizes.map(size => `
          <button class="choice ${size === boardSize ? 'active' : ''}" data-invite-size="${size}" type="button">
            ${escapeHtml(boardLabel(gameType, size))}
          </button>
        `).join('')}
      </div>
      <div class="section-title"><h2>Стоимость участия</h2></div>
      <div class="choice-grid ${room === 'gold' ? '' : 'single-choice'}" data-invite-bets>
        ${(room === 'gold' ? APP_CONFIG.goldBets : [APP_CONFIG.matchBet]).map(value => `
          <button class="choice ${value === bet ? 'active' : ''} ${room === 'gold' ? 'gold' : ''}" data-invite-bet="${value}" type="button">
            ${value} коинов
          </button>
        `).join('')}
      </div>
    </div>

    <button class="btn ${room === 'gold' ? 'gold' : 'primary'} full setup-start-btn" data-create-invite type="button">
      Создать и отправить
    </button>
  `);

  document.querySelectorAll('[data-invite-size]').forEach(button => button.addEventListener('click', () => {
    boardSize = Number(button.dataset.inviteSize || option.defaultSize);
    document.querySelectorAll('[data-invite-size]').forEach(item => item.classList.toggle('active', item === button));
  }));

  document.querySelectorAll('[data-invite-bet]').forEach(button => button.addEventListener('click', () => {
    bet = Number(button.dataset.inviteBet || APP_CONFIG.matchBet);
    document.querySelectorAll('[data-invite-bet]').forEach(item => item.classList.toggle('active', item === button));
  }));

  document.querySelector('[data-create-invite]')?.addEventListener('click', async event => {
    const submit = event.currentTarget;
    if (!(submit instanceof HTMLButtonElement) || submit.disabled) return;

    submit.disabled = true;
    submit.textContent = 'Создаём приглашение…';

    try {
      const result = await inviteRequest('create', { gameType, room, bet, boardSize });
      syncInviteState(result);
      activeInviteData = result.invite || {};
      rememberInvite(activeInviteData);
      showCreatedInvite(activeInviteData);
      await shareInvite(activeInviteData);
    } catch (error) {
      toast(error.message || 'Не удалось создать приглашение.');
      submit.disabled = false;
      submit.textContent = 'Создать и отправить';
    }
  });
}

function showCreatedInvite(invite){
  activeInviteData = { ...(activeInviteData || {}), ...invite };
  rememberInvite(activeInviteData);
  const shareUrl = String(activeInviteData.share_url || '');

  openSheet(`
    ${inviteMarker(activeInviteData)}
    <div class="sheet-head">
      <div><h2>Приглашение готово</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    ${inviteSummary(activeInviteData)}
    <div class="small-note invite-status-note">Ссылка действует до ${escapeHtml(formatTime(activeInviteData.expires_at))}.</div>

    <div class="stack invite-actions">
      ${shareUrl ? `
        <button class="btn primary full" data-share-invite type="button">Отправить в Telegram</button>
        <button class="btn ghost full" data-copy-invite type="button">Скопировать ссылку</button>
      ` : ''}
      <button class="btn ghost full" data-invite-action="cancel" type="button">Отменить приглашение</button>
    </div>
  `);

  document.querySelector('[data-share-invite]')?.addEventListener('click', () => shareInvite(activeInviteData));
  document.querySelector('[data-copy-invite]')?.addEventListener('click', async () => {
    if (!shareUrl) return toast('Ссылка временно недоступна.');
    try {
      await navigator.clipboard.writeText(shareUrl);
      toast('Ссылка скопирована.');
    } catch (error) {
      window.prompt('Скопируйте ссылку:', shareUrl);
    }
  });
}

async function shareInvite(invite){
  const tg = getTelegram();
  const preparedId = String(invite.prepared_message_id || '');

  if (preparedId && typeof tg?.shareMessage === 'function') {
    try {
      const sent = await new Promise(resolve => {
        tg.shareMessage(preparedId, result => resolve(Boolean(result)));
      });
      if (sent) toast('Приглашение отправлено.');
      return;
    } catch (error) {
      // Older Telegram clients fall back to the classic share link below.
    }
  }

  const shareUrl = String(invite.share_url || '');
  if (!shareUrl) return toast('Ссылка временно недоступна.');
  const text = String(invite.share_text || `🎮 Приглашение в Mini Games World\n\n${shareUrl}`);
  const telegramShareUrl = `https://t.me/share/url?url=${encodeURIComponent(shareUrl)}&text=${encodeURIComponent(text.replace(shareUrl, '').trim())}`;

  try {
    if (tg?.openTelegramLink) tg.openTelegramLink(telegramShareUrl);
    else window.open(telegramShareUrl, '_blank', 'noopener,noreferrer');
  } catch (error) {
    window.open(telegramShareUrl, '_blank', 'noopener,noreferrer');
  }
}

function showIncomingInvite(invite){
  activeInviteData = { ...(activeInviteData || {}), ...invite };
  rememberInvite(activeInviteData);
  markAutoOpened(activeInviteData.token);

  openSheet(`
    ${inviteMarker(activeInviteData)}
    <div class="sheet-head">
      <div>
        <h2>Вас приглашают сыграть</h2>
        <p>От ${escapeHtml(activeInviteData.inviter_name || 'игрока')}</p>
      </div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    ${inviteSummary(activeInviteData)}

    <div class="stack invite-actions">
      <button class="btn primary full" data-invite-action="accept" type="button">Принять приглашение</button>
      <button class="btn ghost full" data-invite-action="decline" type="button">Отклонить приглашение</button>
    </div>
  `);
}

function showOwnerReady(invite){
  activeInviteData = { ...(activeInviteData || {}), ...invite };
  rememberInvite(activeInviteData);

  openSheet(`
    ${inviteMarker(activeInviteData)}
    <div class="sheet-head">
      <div>
        <h2>Соперник согласен</h2>
        <p>${escapeHtml(activeInviteData.invitee_name || 'Игрок')} готов играть.</p>
      </div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    ${inviteSummary(activeInviteData)}
    <div class="small-note invite-status-note">Запустите матч до ${escapeHtml(formatTime(activeInviteData.start_deadline_at))}. Коины спишутся после запуска.</div>

    <div class="stack invite-actions">
      <button class="btn primary full" data-invite-action="start" type="button">Начать игру</button>
      <button class="btn ghost full" data-invite-action="cancel" type="button">Отменить приглашение</button>
    </div>
  `);
}

function showInviteeWaiting(invite){
  activeInviteData = { ...(activeInviteData || {}), ...invite };
  rememberInvite(activeInviteData);

  openSheet(`
    ${inviteMarker(activeInviteData)}
    <div class="sheet-head">
      <div>
        <h2>Ждём запуска матча</h2>
        <p>${escapeHtml(activeInviteData.inviter_name || 'Игрок')} должен подтвердить начало.</p>
      </div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    ${inviteSummary(activeInviteData)}
    <div class="small-note invite-status-note">Ожидание до ${escapeHtml(formatTime(activeInviteData.start_deadline_at))}.</div>
    <button class="btn ghost full" data-invite-action="cancel" type="button">Отменить ожидание</button>
  `);
}

function showTerminalInvite(invite){
  activeInviteData = { ...(invite || {}) };

  openSheet(`
    ${inviteMarker(activeInviteData)}
    <div class="sheet-head">
      <div><h2>${escapeHtml(activeInviteData.status_label || 'Приглашение закрыто')}</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    ${inviteSummary(activeInviteData)}
    <div class="small-note invite-status-note">Это приглашение больше нельзя использовать.</div>
    <button class="btn primary full" data-close-sheet type="button">Понятно</button>
  `);
}

async function performInviteAction(action, button){
  if (!activeInviteData?.token || !action) {
    await pollInvitesNow();
  }
  if (!activeInviteData?.token || !action) return;

  if (action === 'wait') {
    showInviteeWaiting(activeInviteData);
    return;
  }

  const originalText = button.textContent;
  setInviteButtonsDisabled(true);
  button.textContent = action === 'start'
    ? 'Запускаем…'
    : action === 'accept'
      ? 'Принимаем…'
      : action === 'decline'
        ? 'Отклоняем…'
        : 'Отменяем…';

  try {
    const result = await inviteRequest(action, { token:String(activeInviteData.token || '') });
    handleInviteResult(result, { forceOpen:action === 'accept' });

    if (action === 'decline' || action === 'cancel') {
      closeSheet();
      toast(action === 'decline' ? 'Приглашение отклонено.' : 'Приглашение отменено.');
    }
  } catch (error) {
    toast(error.message || 'Не удалось выполнить действие.');
    setInviteButtonsDisabled(false);
    button.textContent = originalText;
  }
}

function handleInviteResult(result, { forceOpen = false } = {}){
  syncInviteState(result);

  if (result?.game?.id && String(result.game.status || '') === 'active') {
    enterInviteGame(result);
    return;
  }

  const invite = result?.invite;
  if (!invite?.token) {
    clearActiveInvite();
    enhanceNotificationSheet();
    return;
  }

  const previousToken = String(activeInvite?.token || '');
  const previousStatus = previousToken === String(invite.token || '')
    ? String(activeInvite?.status || '')
    : '';

  activeInviteData = { ...(activeInviteData || {}), ...invite };
  rememberInvite(activeInviteData);

  const status = String(activeInviteData.status || 'pending');
  const statusChanged = previousStatus !== '' && previousStatus !== status;
  const isNewInvite = previousToken !== String(activeInviteData.token || '');

  if (statusChanged || isNewInvite) {
    refreshNotificationBadge(true);
  }

  enhanceNotificationSheet();

  if (status === 'pending') {
    if (forceOpen && !activeInviteData.is_owner) {
      showIncomingInvite(activeInviteData);
    } else if (forceOpen && activeInviteData.is_owner) {
      showCreatedInvite(activeInviteData);
    } else if (shouldAutoOpenIncoming(activeInviteData)) {
      showIncomingInvite(activeInviteData);
    }
    return;
  }

  if (status === 'awaiting_start') {
    const sheetOpen = isInviteSheetOpen(String(activeInviteData.token || ''));
    if (forceOpen || sheetOpen) {
      if (activeInviteData.is_owner) showOwnerReady(activeInviteData);
      else showInviteeWaiting(activeInviteData);
    }
    return;
  }

  if (status === 'starting' || status === 'started') {
    // The active game is returned by the same endpoint as soon as it exists.
    // Finished games are deliberately ignored by the backend.
    return;
  }

  const terminalInvite = { ...activeInviteData };
  const sheetOpen = isInviteSheetOpen(String(terminalInvite.token || ''));
  clearActiveInvite();
  refreshNotificationBadge(true);
  if (forceOpen || sheetOpen) showTerminalInvite(terminalInvite);
}

function startInvitePolling(){
  if (pollTimer !== null) return;
  pollTimer = window.setInterval(() => pollInvitesNow(), INVITE_POLL_MS);
}

async function pollInvitesNow(){
  if (pollBusy || document.visibilityState !== 'visible' || state.activeGame) return null;
  pollBusy = true;

  try {
    if (activeInvite?.token) {
      const result = await inviteRequest('resolve', { token:activeInvite.token });
      handleInviteResult(result, { forceOpen:false });
      return result?.invite || null;
    }

    const result = await inboxRequest();
    if (result?.invite) {
      handleInviteResult(result, { forceOpen:false });
      return result.invite;
    }

    enhanceNotificationSheet();
    return null;
  } catch (error) {
    // Temporary background errors must not destroy a still-valid invitation.
    return null;
  } finally {
    pollBusy = false;
  }
}

async function openStoredInvite(){
  if (!activeInvite?.token) {
    await pollInvitesNow();
  }
  if (!activeInvite?.token) return;

  try {
    const result = await inviteRequest('resolve', { token:activeInvite.token });
    handleInviteResult(result, { forceOpen:true });
  } catch (error) {
    clearActiveInvite();
    toast(error.message || 'Приглашение уже недоступно.');
  }
}

function enterInviteGame(result){
  const game = result?.game;
  if (!game?.id || String(game.status || '') !== 'active') return;

  clearActiveInvite();
  syncInviteState(result);
  state.activeGame = game;
  closeSheet();
  showScreen('game');
  startGamePolling(game.id);
}

function scheduleNotificationEnhancement(){
  window.clearTimeout(sheetEnhanceTimer);
  sheetEnhanceTimer = window.setTimeout(enhanceNotificationSheet, 60);
}

function enhanceNotificationSheet(){
  if (!isNotificationsSheetOpen()) return;

  document.querySelectorAll('[data-invite-action-card]').forEach(card => card.remove());
  if (!activeInviteData?.token) return;

  const status = String(activeInviteData.status || '');
  const actionable = (status === 'pending' && activeInviteData.is_invitee)
    || (status === 'awaiting_start' && (activeInviteData.is_owner || activeInviteData.is_invitee));
  if (!actionable) return;

  const duplicateTitle = status === 'pending'
    ? 'Вас пригласили сыграть'
    : (activeInviteData.is_owner ? 'Соперник согласен' : 'Приглашение принято');

  document.querySelectorAll('.notification-card:not([data-invite-action-card])').forEach(card => {
    const title = String(card.querySelector('.notification-head strong')?.textContent || '').trim();
    if (title === duplicateTitle) card.remove();
  });

  const card = document.createElement('article');
  card.className = `notification-card ${status === 'pending' ? 'info' : 'success'}`;
  card.dataset.inviteActionCard = '1';
  card.innerHTML = notificationActionCard(activeInviteData);

  const sheet = document.getElementById('sheet');
  const anchor = sheet?.querySelector('.notifications-list, .notifications-empty');
  if (anchor) anchor.insertAdjacentElement('beforebegin', card);
  else sheet?.appendChild(card);
}

function notificationActionCard(invite){
  const status = String(invite.status || '');

  if (status === 'awaiting_start' && invite.is_owner) {
    return `
      <div class="notification-icon">✓</div>
      <div class="notification-copy">
        <div class="notification-head"><strong>Соперник согласен</strong></div>
        <p>${escapeHtml(invite.invitee_name || 'Игрок')} готов сыграть в «${escapeHtml(invite.game_title || 'игру')}».</p>
        <div class="notification-actions">
          <button class="btn primary full" data-invite-action="start" type="button">Начать игру</button>
          <button class="btn ghost full" data-invite-action="cancel" type="button">Отменить</button>
        </div>
      </div>
    `;
  }

  if (status === 'pending' && invite.is_invitee) {
    return `
      <div class="notification-icon">🎮</div>
      <div class="notification-copy">
        <div class="notification-head"><strong>Вас пригласили сыграть</strong></div>
        <p>${escapeHtml(invite.inviter_name || 'Игрок')} приглашает вас в «${escapeHtml(invite.game_title || 'игру')}».</p>
        <div class="notification-actions">
          <button class="btn primary full" data-invite-action="accept" type="button">Принять приглашение</button>
          <button class="btn ghost full" data-invite-action="decline" type="button">Отклонить</button>
        </div>
      </div>
    `;
  }

  return `
    <div class="notification-icon">✓</div>
    <div class="notification-copy">
      <div class="notification-head"><strong>Приглашение принято</strong></div>
      <p>Ждём, когда ${escapeHtml(invite.inviter_name || 'игрок')} запустит матч.</p>
      <div class="notification-actions">
        <button class="btn primary full" data-invite-action="wait" type="button">Открыть ожидание</button>
        <button class="btn ghost full" data-invite-action="cancel" type="button">Отменить</button>
      </div>
    </div>
  `;
}

function syncInviteState(result){
  if (result?.user) {
    state.user = result.user;
    renderBalances(state.user);
  }
  if (result?.session) state.session = result.session;
}

function rememberInvite(invite){
  const token = String(invite?.token || '');
  if (!token) return;

  activeInvite = {
    token,
    status:String(invite.status || 'pending'),
    role:invite.is_owner ? 'owner' : (invite.is_invitee ? 'invitee' : 'guest'),
  };

  try {
    localStorage.setItem(ACTIVE_INVITE_STORAGE_KEY, JSON.stringify(activeInvite));
  } catch (error) {
    // In-memory state remains enough for the current Mini App session.
  }
}

function loadStoredInvite(){
  try {
    const value = JSON.parse(localStorage.getItem(ACTIVE_INVITE_STORAGE_KEY) || 'null');
    if (value && /^[a-f0-9]{24}$/.test(String(value.token || ''))) return value;
  } catch (error) {
    // Ignore malformed or unavailable local storage.
  }
  return null;
}

function clearActiveInvite(){
  activeInvite = null;
  activeInviteData = null;
  document.querySelectorAll('[data-invite-action-card]').forEach(card => card.remove());
  try {
    localStorage.removeItem(ACTIVE_INVITE_STORAGE_KEY);
  } catch (error) {
    // Nothing else is required when local storage is unavailable.
  }
}

function shouldAutoOpenIncoming(invite){
  const token = String(invite?.token || '');
  if (!token || token === autoOpenedToken) return false;
  if (!invite.is_invitee || String(invite.status || '') !== 'pending') return false;
  if (document.visibilityState !== 'visible' || state.activeGame) return false;

  const activeScreen = document.querySelector('.screen.active');
  if (String(activeScreen?.dataset.screen || '') !== 'home') return false;
  return !document.getElementById('sheetOverlay')?.classList.contains('active');
}

function markAutoOpened(token){
  autoOpenedToken = String(token || '');
  try {
    sessionStorage.setItem(AUTO_OPEN_STORAGE_KEY, autoOpenedToken);
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

function hasReadyCheck(){
  return String(activeInvite?.status || '') === 'awaiting_start';
}

function isGameLaunchControl(target){
  const id = String(target?.id || '');
  return id === 'startSearchBtn'
    || id.startsWith('play')
    || Boolean(target?.closest?.('[data-invite-friend]'));
}

function isInviteSheetOpen(token){
  const overlay = document.getElementById('sheetOverlay');
  if (!overlay?.classList.contains('active')) return false;
  return Boolean(document.querySelector(`[data-invite-sheet][data-invite-token="${token}"]`));
}

function isNotificationsSheetOpen(){
  const overlay = document.getElementById('sheetOverlay');
  if (!overlay?.classList.contains('active')) return false;
  return String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').trim() === 'Уведомления';
}

function inviteMarker(invite){
  return `<span data-invite-sheet data-invite-token="${escapeHtml(invite.token || '')}" hidden></span>`;
}

function setInviteButtonsDisabled(disabled){
  document.querySelectorAll('[data-invite-action]').forEach(button => {
    button.disabled = disabled;
  });
}

function inviteSummary(invite){
  return `
    <div class="topup-success">
      <div><span>Игра</span><strong>${escapeHtml(invite.game_title || 'Игра')}</strong></div>
      <div><span>Комната</span><strong>${escapeHtml(invite.room_label || roomLabel(invite.room))}</strong></div>
      <div><span>Вариант</span><strong>${escapeHtml(inviteBoardLabel(invite))}</strong></div>
      <div><span>Ставка</span><strong>${Number(invite.bet || 0)} коинов</strong></div>
    </div>
  `;
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

function incomingToken(){
  const startParam = String(getTelegram()?.initDataUnsafe?.start_param || '');
  const fromTelegram = startParam.startsWith('invite_') ? startParam.slice(7) : '';
  const fromQuery = new URLSearchParams(window.location.search).get('invite') || '';
  const token = String(fromTelegram || fromQuery).toLowerCase();
  return /^[a-f0-9]{24}$/.test(token) ? token : '';
}

function boardLabel(gameType, size){
  if (gameType === 'four_in_a_row') return `${size}×${size - 1}${size === 7 ? ' · классика' : ''}`;
  if (gameType === 'domino') return 'Классика 0–6';
  return `${size}×${size}`;
}

function inviteBoardLabel(invite){
  return boardLabel(String(invite.game_type || ''), Number(invite.board_size || 0));
}

function roomLabel(room){
  return String(room || '') === 'gold' ? 'Gold-комната' : 'Матч-комната';
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
