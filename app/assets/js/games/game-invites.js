import { state } from '../state.js?v=27';
import { APP_CONFIG } from '../config.js?v=38';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=41';
import { getTelegram, getInitData, haptic } from '../telegram/telegram-app.js?v=27';
import { getSessionId } from '../session.js?v=27';
import { showScreen } from '../router.js?v=27';
import { startGamePolling } from '../screens/game-screen.js?v=74';
import { renderBalances } from '../ui.js?v=27';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const ACTIVE_INVITE_STORAGE_KEY = 'mgw_active_invite_v2';
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
let invitePollTimer = null;
let invitePollBusy = false;
let activeInvite = loadStoredInvite();
let activeInviteData = null;

export function initGameInvites(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('click', event => {
    const target = event.target.closest('button, [role="button"]');
    if (!target) return;

    if (target.id === 'notificationsOpen' && hasReadyCheck()) {
      event.preventDefault();
      event.stopImmediatePropagation();
      openStoredInvite();
      return;
    }

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

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && activeInvite?.token) startInvitePolling();
  });
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

  try {
    if (activeInvite?.token) {
      const result = await inviteRequest('resolve', { token:activeInvite.token });
      handleInviteResult(result, { forceOpen:false });
      return;
    }

    const result = await inviteRequest('active');
    if (result?.invite) handleInviteResult(result, { forceOpen:false });
  } catch (error) {
    clearActiveInvite();
  }
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
      startInvitePolling();
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
  const canShare = shareUrl !== '';

  openSheet(`
    ${inviteMarker(activeInviteData)}
    <div class="sheet-head">
      <div><h2>Приглашение готово</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    ${inviteSummary(activeInviteData)}
    <div class="small-note invite-status-note">Ссылка действует до ${escapeHtml(formatTime(activeInviteData.expires_at))}.</div>

    <div class="stack invite-actions">
      ${canShare ? `
        <button class="btn primary full" data-share-invite type="button">Отправить в Telegram</button>
        <button class="btn ghost full" data-copy-invite type="button">Скопировать ссылку</button>
      ` : ''}
      <button class="btn ghost full" data-cancel-invite type="button">Отменить приглашение</button>
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
  bindCancelButton(activeInviteData);
}

async function shareInvite(invite){
  const tg = getTelegram();
  const preparedId = String(invite.prepared_message_id || '');

  if (preparedId && typeof tg?.shareMessage === 'function') {
    try {
      await new Promise(resolve => {
        tg.shareMessage(preparedId, sent => {
          if (sent) toast('Приглашение отправлено.');
          resolve(Boolean(sent));
        });
      });
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
      <button class="btn primary full" data-accept-invite type="button">Принять приглашение</button>
      <button class="btn ghost full" data-decline-invite type="button">Отклонить приглашение</button>
    </div>
  `);

  document.querySelector('[data-accept-invite]')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;
    setInviteButtonsDisabled(true);
    button.textContent = 'Принимаем…';

    try {
      const result = await inviteRequest('accept', { token:String(activeInviteData.token || '') });
      handleInviteResult(result, { forceOpen:true });
    } catch (error) {
      toast(error.message || 'Не удалось принять приглашение.');
      setInviteButtonsDisabled(false);
      button.textContent = 'Принять приглашение';
    }
  });

  document.querySelector('[data-decline-invite]')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;
    setInviteButtonsDisabled(true);
    button.textContent = 'Отклоняем…';

    try {
      const result = await inviteRequest('decline', { token:String(activeInviteData.token || '') });
      handleInviteResult(result, { forceOpen:true });
    } catch (error) {
      toast(error.message || 'Не удалось отклонить приглашение.');
      setInviteButtonsDisabled(false);
      button.textContent = 'Отклонить приглашение';
    }
  });
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
      <button class="btn primary full" data-start-invite type="button">Начать матч</button>
      <button class="btn ghost full" data-cancel-invite type="button">Отменить приглашение</button>
    </div>
  `);

  document.querySelector('[data-start-invite]')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;
    setInviteButtonsDisabled(true);
    button.textContent = 'Запускаем матч…';

    try {
      const result = await inviteRequest('start', { token:String(activeInviteData.token || '') });
      handleInviteResult(result, { forceOpen:true });
    } catch (error) {
      toast(error.message || 'Не удалось запустить матч.');
      setInviteButtonsDisabled(false);
      button.textContent = 'Начать матч';
    }
  });

  bindCancelButton(activeInviteData);
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
    <div class="small-note invite-status-note">Ожидание до ${escapeHtml(formatTime(activeInviteData.start_deadline_at))}. Если матч не запустят, приглашение закроется автоматически.</div>

    <div class="stack invite-actions">
      <button class="btn ghost full" data-cancel-invite type="button">Отменить ожидание</button>
    </div>
  `);

  bindCancelButton(activeInviteData);
}

function showTerminalInvite(invite){
  activeInviteData = { ...(activeInviteData || {}), ...invite };

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

function bindCancelButton(invite){
  document.querySelector('[data-cancel-invite]')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;
    setInviteButtonsDisabled(true);
    button.textContent = 'Отменяем…';

    try {
      const result = await inviteRequest('cancel', { token:String(invite.token || '') });
      handleInviteResult(result, { forceOpen:true });
    } catch (error) {
      toast(error.message || 'Не удалось отменить приглашение.');
      setInviteButtonsDisabled(false);
      button.textContent = invite.is_invitee ? 'Отменить ожидание' : 'Отменить приглашение';
    }
  });
}

function handleInviteResult(result, { forceOpen = false } = {}){
  syncInviteState(result);
  if (result?.game?.id) {
    enterInviteGame(result);
    return;
  }

  const invite = result?.invite;
  if (!invite?.token) {
    clearActiveInvite();
    return;
  }

  const previousStatus = String(activeInvite?.status || '');
  activeInviteData = { ...(activeInviteData || {}), ...invite };
  rememberInvite(activeInviteData);
  startInvitePolling();

  const status = String(activeInviteData.status || 'pending');
  const sheetOpen = isInviteSheetOpen(String(activeInviteData.token || ''));

  if (status === 'pending') {
    if (forceOpen) {
      if (activeInviteData.is_owner) showCreatedInvite(activeInviteData);
      else showIncomingInvite(activeInviteData);
    }
    return;
  }

  if (status === 'awaiting_start') {
    if (forceOpen || sheetOpen) {
      if (activeInviteData.is_owner) showOwnerReady(activeInviteData);
      else showInviteeWaiting(activeInviteData);
    } else if (activeInviteData.is_owner && previousStatus === 'pending') {
      toast('Соперник согласен. Откройте колокольчик и запустите матч.');
    }
    return;
  }

  if (status === 'started') {
    toast('Матч запускается…');
    return;
  }

  clearActiveInvite();
  if (forceOpen || sheetOpen) showTerminalInvite(activeInviteData);
}

function startInvitePolling(){
  if (!activeInvite?.token || state.activeGame) return;
  if (invitePollTimer !== null) return;

  invitePollTimer = window.setInterval(async () => {
    if (invitePollBusy || document.visibilityState !== 'visible' || state.activeGame || !activeInvite?.token) return;
    invitePollBusy = true;

    try {
      const result = await inviteRequest('resolve', { token:activeInvite.token });
      handleInviteResult(result, { forceOpen:false });
    } catch (error) {
      clearActiveInvite();
    } finally {
      invitePollBusy = false;
    }
  }, Math.max(3000, Number(APP_CONFIG.searchIntervalMs || 3500)));
}

function clearInvitePolling(){
  if (invitePollTimer !== null) window.clearInterval(invitePollTimer);
  invitePollTimer = null;
  invitePollBusy = false;
}

async function openStoredInvite(){
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
  if (!game?.id) return;

  clearActiveInvite();
  syncInviteState(result);
  state.activeGame = game;
  closeSheet();
  showScreen('game');
  startGamePolling(game.id);
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
    // The current in-memory invitation remains usable when storage is unavailable.
  }
}

function loadStoredInvite(){
  try {
    const value = JSON.parse(localStorage.getItem(ACTIVE_INVITE_STORAGE_KEY) || 'null');
    if (value && /^[a-f0-9]{24}$/.test(String(value.token || ''))) return value;
  } catch (error) {
    // Ignore a malformed or unavailable local storage value.
  }
  return null;
}

function clearActiveInvite(){
  clearInvitePolling();
  activeInvite = null;
  activeInviteData = null;
  try {
    localStorage.removeItem(ACTIVE_INVITE_STORAGE_KEY);
  } catch (error) {
    // Nothing else is required when local storage is unavailable.
  }
}

function hasReadyCheck(){
  return String(activeInvite?.status || '') === 'awaiting_start';
}

function isGameLaunchControl(target){
  const id = String(target?.id || '');
  return id === 'startSearchBtn' || id.startsWith('play') || Boolean(target?.closest?.('[data-invite-friend]'));
}

function isInviteSheetOpen(token){
  const overlay = document.getElementById('sheetOverlay');
  if (!overlay?.classList.contains('active')) return false;
  return Boolean(document.querySelector(`[data-invite-sheet][data-invite-token="${token}"]`));
}

function inviteMarker(invite){
  return `<span data-invite-sheet data-invite-token="${escapeHtml(invite.token || '')}" hidden></span>`;
}

function setInviteButtonsDisabled(disabled){
  document.querySelectorAll('[data-accept-invite], [data-decline-invite], [data-start-invite], [data-cancel-invite]').forEach(button => {
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
  const response = await fetch(INVITES_URL, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({
      initData:getInitData(),
      sessionId:getSessionId(),
      action,
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
