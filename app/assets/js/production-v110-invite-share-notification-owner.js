import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { getTelegram, getInitData, haptic } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { toast } from './components/toast.js?v=1109';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const ANNOUNCED_STORAGE_KEY = 'mgw_announced_notifications_v5';
const MAX_ANNOUNCED_IDS = 300;
const DEFAULT_SIZES = {
  tictactoe:3,
  four_in_a_row:7,
  battleship:10,
  checkers:8,
  reversi:8,
  chess:8,
  go:9,
  domino:7,
};

const runtime = window.__MGW_V110_INVITE_SHARE_NOTIFICATION_OWNER__ ||= {
  initialized:false,
  sequence:0,
  warm:null,
  serial:Promise.resolve(),
  sharing:false,
  lastGameType:'tictactoe',
};

initV110InviteShareNotificationOwner();

export function initV110InviteShareNotificationOwner(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  window.addEventListener('pointerdown', rememberShareIntent, true);
  window.addEventListener('click', ownInviteShareClick, true);
  window.addEventListener('mgw:notification-sync', suppressAlreadyPresentedInviteToast, true);
}

function rememberShareIntent(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;

  const invite = origin.closest('[data-invite-friend]');
  if (invite) {
    const gameType = String(invite.dataset.inviteFriend || state.selectedGame || 'tictactoe');
    runtime.lastGameType = gameType;
    void warmDraft(defaultContext(gameType)).catch(() => null);
    return;
  }

  if (origin.closest('[data-invite-size], [data-invite-bet]')) {
    window.setTimeout(() => void warmDraft(readSetupContext()).catch(() => null), 0);
  }
}

function ownInviteShareClick(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;

  const invite = origin.closest('[data-invite-friend]');
  if (invite) {
    const gameType = String(invite.dataset.inviteFriend || state.selectedGame || 'tictactoe');
    runtime.lastGameType = gameType;
    void warmDraft(defaultContext(gameType)).catch(() => null);
    return;
  }

  if (origin.closest('[data-invite-size], [data-invite-bet]')) {
    window.setTimeout(() => void warmDraft(readSetupContext()).catch(() => null), 0);
    return;
  }

  const button = origin.closest('[data-create-link-invite]');
  if (!(button instanceof HTMLButtonElement)) return;

  event.preventDefault();
  event.stopImmediatePropagation();
  void sharePreparedLink(button, readSetupContext());
}

async function sharePreparedLink(button, context){
  if (runtime.sharing || button.disabled) return;
  runtime.sharing = true;
  haptic('light');

  const originalText = String(button.textContent || 'Поделиться ссылкой');
  button.disabled = true;
  button.textContent = 'Открываем Telegram…';

  try {
    const draft = await preparedDraft(context);
    button.disabled = false;
    button.textContent = originalText;
    openTelegramShare(draft);
  } catch (error) {
    button.disabled = false;
    button.textContent = originalText;
    toast(error?.message || 'Не удалось подготовить приглашение.');
  } finally {
    runtime.sharing = false;
  }
}

function warmDraft(context){
  const normalized = normalizeContext(context);
  const key = contextKey(normalized);
  if (runtime.warm?.key === key && runtime.warm.promise) return runtime.warm.promise;

  const entry = {
    id:++runtime.sequence,
    key,
    context:normalized,
    draft:null,
    promise:null,
  };
  runtime.warm = entry;

  entry.promise = runtime.serial = runtime.serial
    .catch(() => null)
    .then(async () => {
      if (runtime.warm?.id !== entry.id) return null;
      const result = await inviteRequest('create_link_draft', {
        ...normalized,
        prepareMessage:false,
      });
      const draft = result?.invite || null;
      if (!draft?.token || !draft?.share_url) throw new Error('Не удалось подготовить ссылку.');
      if (runtime.warm?.id !== entry.id) return null;
      entry.draft = draft;
      return draft;
    })
    .catch(error => {
      if (runtime.warm?.id === entry.id) runtime.warm = null;
      throw error;
    });

  return entry.promise;
}

async function preparedDraft(context){
  const normalized = normalizeContext(context);
  const key = contextKey(normalized);
  let draft = runtime.warm?.key === key
    ? await runtime.warm.promise
    : await warmDraft(normalized);

  if (draft?.token && draft?.share_url) return draft;
  runtime.warm = null;
  draft = await warmDraft(normalized);
  if (!draft?.token || !draft?.share_url) throw new Error('Не удалось подготовить ссылку.');
  return draft;
}

function openTelegramShare(invite){
  const shareUrl = String(invite?.share_url || '');
  if (!shareUrl) throw new Error('Ссылка временно недоступна.');

  const text = String(invite?.share_text || '').replace(shareUrl, '').trim();
  const url = `https://t.me/share/url?url=${encodeURIComponent(shareUrl)}&text=${encodeURIComponent(text)}`;
  const telegram = getTelegram();

  try {
    if (typeof telegram?.openTelegramLink === 'function') telegram.openTelegramLink(url);
    else window.open(url, '_blank', 'noopener,noreferrer');
  } catch (error) {
    window.open(url, '_blank', 'noopener,noreferrer');
  }
}

function suppressAlreadyPresentedInviteToast(event){
  const item = event.detail?.item || null;
  const token = String(item?.invite_token || '');
  const id = String(item?.id || '');
  if (!token || !id || !String(item?.type || '').startsWith('invite_')) return;

  const overlay = document.getElementById('sheetOverlay');
  const marker = document.querySelector('#sheet [data-invite-sheet][data-invite-token]');
  if (!overlay?.classList.contains('active') || String(marker?.dataset.inviteToken || '') !== token) return;

  event.stopImmediatePropagation();
  rememberAnnouncedId(id);
  document.dispatchEvent(new CustomEvent('mgw:notification-count', {
    detail:{ unreadCount:Number(event.detail?.unreadCount || 0) },
  }));
  window.queueMicrotask(() => {
    document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
  });
}

function rememberAnnouncedId(id){
  try {
    const parsed = JSON.parse(localStorage.getItem(ANNOUNCED_STORAGE_KEY) || '[]');
    const ids = Array.isArray(parsed) ? parsed.map(String).filter(Boolean) : [];
    const next = ids.filter(value => value !== id);
    next.push(id);
    localStorage.setItem(ANNOUNCED_STORAGE_KEY, JSON.stringify(next.slice(-MAX_ANNOUNCED_IDS)));
  } catch (error) {}
}

function readSetupContext(){
  const gameType = String(runtime.lastGameType || state.selectedGame || 'tictactoe');
  const room = state.room === 'gold' ? 'gold' : 'match';
  const boardSize = Number(
    document.querySelector('[data-invite-size].active')?.dataset.inviteSize
      || DEFAULT_SIZES[gameType]
      || 3
  );
  const bet = Number(
    document.querySelector('[data-invite-bet].active')?.dataset.inviteBet
      || (room === 'gold' ? state.selectedBet || APP_CONFIG.goldBets?.[0] : APP_CONFIG.matchBet)
      || 10
  );
  return normalizeContext({ gameType, room, boardSize, bet });
}

function defaultContext(gameType){
  const room = state.room === 'gold' ? 'gold' : 'match';
  return normalizeContext({
    gameType,
    room,
    boardSize:DEFAULT_SIZES[gameType] || 3,
    bet:room === 'gold'
      ? Number(state.selectedBet || APP_CONFIG.goldBets?.[0] || 10)
      : Number(APP_CONFIG.matchBet || 10),
  });
}

function normalizeContext(value){
  const gameType = String(value?.gameType || 'tictactoe');
  const room = String(value?.room || '') === 'gold' ? 'gold' : 'match';
  return {
    gameType,
    room,
    boardSize:Number(value?.boardSize || DEFAULT_SIZES[gameType] || 3),
    bet:Number(value?.bet || (room === 'gold' ? APP_CONFIG.goldBets?.[0] : APP_CONFIG.matchBet) || 10),
  };
}

function contextKey(value){
  const context = normalizeContext(value);
  return `${context.gameType}|${context.room}|${context.boardSize}|${context.bet}`;
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
    priority:'high',
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) {
    if (response.status === 429) throw new Error('Связь перегружена. Попробуйте ещё раз через несколько секунд.');
    throw new Error(data?.error || 'Сервис приглашений временно недоступен.');
  }
  return data;
}
