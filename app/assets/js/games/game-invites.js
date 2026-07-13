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
let handledIncomingToken = '';
let invitePollTimer = null;
let invitePollBusy = false;

export function initGameInvites(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-invite-friend]');
    if (!button) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    openInviteSetup(String(button.dataset.inviteFriend || 'tictactoe'));
  });
}

export async function openIncomingInviteIfPresent(){
  const token = incomingToken();
  if (!token || token === handledIncomingToken || state.activeGame) return;
  handledIncomingToken = token;

  try {
    const result = await inviteRequest('resolve', { token });
    syncInviteState(result);
    if (result.game) {
      enterInviteGame(result);
      return;
    }
    showIncomingInvite(result.invite || {});
  } catch (error) {
    toast(error.message || 'Приглашение уже недоступно.');
  }
}

function openInviteSetup(gameType){
  clearInvitePolling();
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
      <div class="small-note">Ссылка действует 15 минут. Коины спишутся только после начала матча.</div>
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
    submit.textContent = 'Создаём ссылку…';

    try {
      const result = await inviteRequest('create', { gameType, room, bet, boardSize });
      syncInviteState(result);
      const invite = result.invite || {};
      showCreatedInvite(invite);
      shareInvite(invite);
    } catch (error) {
      toast(error.message || 'Не удалось создать приглашение.');
      submit.disabled = false;
      submit.textContent = 'Создать и отправить';
    }
  });
}

function showCreatedInvite(invite){
  clearInvitePolling();
  const shareUrl = String(invite.share_url || '');
  const pending = String(invite.status || 'pending') === 'pending';
  const statusNote = pending
    ? `Ссылка действует до ${escapeHtml(formatTime(invite.expires_at))}.`
    : `Статус: ${escapeHtml(invite.status_label || 'недоступно')}.`;

  openSheet(`
    <span data-invite-sheet data-created-invite="${escapeHtml(invite.token || '')}" hidden></span>
    <div class="sheet-head">
      <div><h2>${pending ? 'Приглашение готово' : 'Приглашение обновлено'}</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">${statusNote}</div>

    <div class="stack invite-actions">
      ${pending ? `
        <button class="btn primary full" data-share-invite type="button">Отправить в Telegram</button>
        <button class="btn ghost full" data-copy-invite type="button">Скопировать ссылку</button>
      ` : `
        <button class="btn primary full" data-close-sheet type="button">Понятно</button>
      `}
    </div>
  `);

  document.querySelector('[data-share-invite]')?.addEventListener('click', () => shareInvite(invite));
  document.querySelector('[data-copy-invite]')?.addEventListener('click', async () => {
    if (!shareUrl) return toast('Ссылка временно недоступна.');
    try {
      await navigator.clipboard.writeText(shareUrl);
      toast('Ссылка скопирована.');
    } catch (error) {
      window.prompt('Скопируйте ссылку:', shareUrl);
    }
  });

  if (pending) startInvitePolling(invite);
}

function shareInvite(invite){
  const shareUrl = String(invite.share_url || '');
  if (!shareUrl) return toast('Ссылка временно недоступна.');

  const text = String(invite.share_text || `🎮 Приглашение в Mini Games World\n\nСыграем в «${invite.game_title || 'игру'}»?`);
  const telegramShareUrl = `https://t.me/share/url?url=${encodeURIComponent(shareUrl)}&text=${encodeURIComponent(text)}`;
  const tg = getTelegram();

  try {
    if (tg?.openTelegramLink) tg.openTelegramLink(telegramShareUrl);
    else window.open(telegramShareUrl, '_blank', 'noopener,noreferrer');
  } catch (error) {
    window.open(telegramShareUrl, '_blank', 'noopener,noreferrer');
  }
}

function showIncomingInvite(invite){
  clearInvitePolling();
  const pending = String(invite.status || '') === 'pending';
  const isOwner = Boolean(invite.is_owner);
  const title = isOwner ? 'Ваше приглашение' : 'Вас приглашают сыграть';
  const statusNote = isOwner && pending
    ? '<div class="small-note invite-status-note">Ожидаем ответ друга.</div>'
    : (!pending ? `<div class="small-note invite-status-note">Статус: ${escapeHtml(invite.status_label || 'недоступно')}.</div>` : '');

  openSheet(`
    <span data-invite-sheet hidden></span>
    <div class="sheet-head">
      <div>
        <h2>${escapeHtml(title)}</h2>
        ${isOwner ? '' : `<p>От ${escapeHtml(invite.inviter_name || 'игрока')}</p>`}
      </div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    ${inviteSummary(invite)}
    ${statusNote}

    <div class="stack invite-actions">
      ${!isOwner && pending ? `
        <button class="btn primary full" data-accept-invite type="button">Принять приглашение</button>
        <button class="btn ghost full" data-decline-invite type="button">Отклонить приглашение</button>
      ` : `
        <button class="btn primary full" data-close-sheet type="button">Понятно</button>
      `}
    </div>
  `);

  document.querySelector('[data-accept-invite]')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;
    setInviteButtonsDisabled(true);
    button.textContent = 'Запускаем матч…';

    try {
      const result = await inviteRequest('accept', { token:String(invite.token || '') });
      syncInviteState(result);
      if (!result.game) throw new Error('Матч создан, но игровая комната ещё не готова.');
      enterInviteGame(result);
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
      const result = await inviteRequest('decline', { token:String(invite.token || '') });
      syncInviteState(result);
      showIncomingInvite(result.invite || invite);
    } catch (error) {
      toast(error.message || 'Не удалось отклонить приглашение.');
      setInviteButtonsDisabled(false);
      button.textContent = 'Отклонить приглашение';
    }
  });
}

function startInvitePolling(invite){
  const token = String(invite.token || '');
  if (!token) return;

  invitePollTimer = window.setInterval(async () => {
    const overlay = document.getElementById('sheetOverlay');
    const marker = document.querySelector(`[data-created-invite="${token}"]`);
    if (!overlay?.classList.contains('active') || !marker) {
      clearInvitePolling();
      return;
    }
    if (invitePollBusy || document.visibilityState !== 'visible') return;

    invitePollBusy = true;
    try {
      const result = await inviteRequest('resolve', { token });
      syncInviteState(result);
      if (result.game) {
        enterInviteGame(result);
        return;
      }

      const updated = result.invite || {};
      if (String(updated.status || 'pending') !== 'pending') {
        showCreatedInvite({ ...invite, ...updated });
      }
    } catch (error) {
      // A background status check must not interrupt the current screen.
    } finally {
      invitePollBusy = false;
    }
  }, Math.max(2000, Number(APP_CONFIG.searchIntervalMs || 2500)));
}

function clearInvitePolling(){
  if (invitePollTimer !== null) window.clearInterval(invitePollTimer);
  invitePollTimer = null;
  invitePollBusy = false;
}

function enterInviteGame(result){
  const game = result?.game;
  if (!game?.id) return;

  clearInvitePolling();
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

function setInviteButtonsDisabled(disabled){
  document.querySelectorAll('[data-accept-invite], [data-decline-invite]').forEach(button => {
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
