import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { openSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { getTelegram, getInitData, haptic } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const CALLBACK_TIMEOUT_MS = 90000;
let initialized = false;
let attemptSequence = 0;
let activeAttempt = null;
let lastGameType = 'tictactoe';

export function initV100ShareController(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;

    const inviteTrigger = origin.closest('[data-invite-friend]');
    if (inviteTrigger) {
      lastGameType = String(inviteTrigger.dataset.inviteFriend || state.selectedGame || 'tictactoe');
    }

    const button = origin.closest('[data-create-link-invite]');
    if (!(button instanceof HTMLButtonElement)) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    startShare(button);
  }, true);
}

async function startShare(button){
  if (activeAttempt?.preparing) return;

  const attempt = {
    id:++attemptSequence,
    preparing:true,
    button,
    draft:null,
    timeout:null,
  };
  activeAttempt = attempt;
  haptic('light');
  button.setAttribute('aria-busy', 'true');
  button.classList.add('mgw-share-preparing');

  try {
    const context = readInviteContext();
    const result = await inviteRequest('create_link_draft', context);
    if (activeAttempt?.id !== attempt.id) return;

    const draft = result?.invite || null;
    const token = String(draft?.token || '');
    if (!token) throw new Error('Не удалось подготовить приглашение.');
    attempt.draft = draft;
    attempt.preparing = false;
    resetButton(attempt);

    const tg = getTelegram();
    const preparedId = String(draft?.prepared_message_id || '');
    if (preparedId && typeof tg?.shareMessage === 'function') {
      openPreparedMessage(tg, preparedId, attempt);
      return;
    }

    openFallbackShare(draft);
    activeAttempt = null;
  } catch (error) {
    if (activeAttempt?.id === attempt.id) activeAttempt = null;
    resetButton(attempt);
    toast(error?.message || 'Не удалось подготовить приглашение.');
  }
}

function openPreparedMessage(tg, preparedId, attempt){
  let settled = false;
  const finish = async sent => {
    if (settled) return;
    settled = true;
    window.clearTimeout(attempt.timeout);
    if (activeAttempt?.id === attempt.id) activeAttempt = null;

    const token = String(attempt.draft?.token || '');
    if (!token) return;

    if (sent === true) {
      renderOwnerWaiting(attempt.draft);
      try {
        const confirmed = await inviteRequest('confirm_shared', { token });
        const invite = confirmed?.invite || attempt.draft;
        renderOwnerWaiting(invite);
        dispatchNotificationCount(confirmed?.unread_count);
      } catch (error) {
        // The recipient can still activate the prepared link. Keep the waiting state.
      }
      return;
    }

    if (sent === false) {
      await inviteRequest('discard_draft', { token }).catch(() => null);
    }
  };

  attempt.timeout = window.setTimeout(() => {
    if (settled) return;
    settled = true;
    if (activeAttempt?.id === attempt.id) activeAttempt = null;
  }, CALLBACK_TIMEOUT_MS);

  try {
    tg.shareMessage(preparedId, result => finish(Boolean(result)));
  } catch (error) {
    window.clearTimeout(attempt.timeout);
    openFallbackShare(attempt.draft);
    activeAttempt = null;
  }
}

function readInviteContext(){
  const activeSize = document.querySelector('[data-invite-size].active');
  const activeBet = document.querySelector('[data-invite-bet].active');
  const room = String(state.room || '') === 'gold' ? 'gold' : 'match';
  const gameType = String(lastGameType || state.selectedGame || 'tictactoe');
  const boardSize = Number(activeSize?.dataset.inviteSize || state.selectedBoardSize || 3);
  const fallbackBet = room === 'gold'
    ? Number(state.selectedBet || APP_CONFIG.goldBets?.[0] || 10)
    : Number(APP_CONFIG.matchBet || 10);
  const bet = Number(activeBet?.dataset.inviteBet || fallbackBet);
  return { gameType, room, boardSize, bet };
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
    throw new Error(data?.error || `Ошибка приглашения: ${response.status}`);
  }
  return data;
}

function openFallbackShare(invite){
  const shareUrl = String(invite?.share_url || '');
  if (!shareUrl) return toast('Ссылка временно недоступна.');
  const text = String(invite?.share_text || '').replace(shareUrl, '').trim();
  const url = `https://t.me/share/url?url=${encodeURIComponent(shareUrl)}&text=${encodeURIComponent(text)}`;
  const tg = getTelegram();
  try {
    if (typeof tg?.openTelegramLink === 'function') tg.openTelegramLink(url);
    else window.open(url, '_blank', 'noopener,noreferrer');
  } catch (error) {
    window.open(url, '_blank', 'noopener,noreferrer');
  }
}

function renderOwnerWaiting(invite){
  const token = String(invite?.token || '');
  if (!token) return;
  openSheet(`
    <span data-invite-sheet data-invite-token="${escapeHtml(token)}" data-invite-state="pending:owner" hidden></span>
    <div class="sheet-head">
      <div><h2>Приглашение отправлено</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">Ждём ответа игрока. Коины пока не списываются.</div>
    <button class="btn ghost full" data-invite-action="cancel" data-invite-token="${escapeHtml(token)}" type="button">Отменить приглашение</button>
  `);
}

function inviteSummary(invite){
  const room = String(invite?.room || '') === 'gold' ? 'Gold-комната' : 'Матч-комната';
  const size = Number(invite?.board_size || 0);
  const variant = size > 0 ? `${size}×${size}` : 'Стандартный';
  return `
    <div class="topup-success">
      <div><span>Игра</span><strong>${escapeHtml(invite?.game_title || 'Игра')}</strong></div>
      <div><span>Комната</span><strong>${escapeHtml(invite?.room_label || room)}</strong></div>
      <div><span>Вариант</span><strong>${escapeHtml(invite?.board_label || variant)}</strong></div>
      <div><span>Ставка</span><strong>${Number(invite?.bet || 0)} коинов</strong></div>
    </div>
  `;
}

function dispatchNotificationCount(value){
  if (!Number.isFinite(Number(value))) return;
  document.dispatchEvent(new CustomEvent('mgw:notification-count', {
    detail:{ unreadCount:Number(value) },
  }));
}

function resetButton(attempt){
  const button = attempt?.button;
  if (!(button instanceof HTMLButtonElement)) return;
  button.removeAttribute('aria-busy');
  button.classList.remove('mgw-share-preparing');
}

function escapeHtml(value){
  return String(value ?? '').replace(/[&<>'"]/g, character => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;',
  }[character]));
}
