import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { openSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { getTelegram, getInitData, haptic } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { inviteContextKey } from './production-v101-speed-models.js?v=101';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const CALLBACK_TIMEOUT_MS = 90000;
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

const runtime = window.__MGW_V102_SHARE__ ||= {
  initialized:false,
  sequence:0,
  lastGameType:'tictactoe',
  warm:null,
  active:null,
};

export function initV102ShareController(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  document.addEventListener('pointerdown', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;

    if (origin.closest('[data-open-player-picker]')) {
      cancelWarmPreparation();
      return;
    }

    const trigger = origin.closest('[data-invite-friend]');
    if (!trigger) return;
    runtime.lastGameType = String(trigger.dataset.inviteFriend || state.selectedGame || 'tictactoe');
    void warmContextSafely(defaultContext(runtime.lastGameType));
  }, true);

  document.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;

    const inviteTrigger = origin.closest('[data-invite-friend]');
    if (inviteTrigger) {
      runtime.lastGameType = String(inviteTrigger.dataset.inviteFriend || state.selectedGame || 'tictactoe');
      window.setTimeout(() => void warmContextSafely(readInviteContext()), 0);
      return;
    }

    if (origin.closest('[data-invite-size], [data-invite-bet]')) {
      window.setTimeout(() => void warmContextSafely(readInviteContext()), 0);
      return;
    }

    const button = origin.closest('[data-create-link-invite]');
    if (!(button instanceof HTMLButtonElement)) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    startShare(button);
  }, true);

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') restoreActiveShareSurface();
  });

  const telegram = getTelegram();
  if (typeof telegram?.onEvent === 'function') {
    try { telegram.onEvent('activated', restoreActiveShareSurface); } catch (error) {}
  }
}

async function startShare(button){
  if (runtime.active?.opening || runtime.active?.nativePending) return;
  const context = readInviteContext();
  const key = inviteContextKey(context);
  haptic('light');
  button.setAttribute('aria-busy', 'true');
  button.classList.add('mgw-share-preparing');

  const attempt = {
    id:++runtime.sequence,
    opening:true,
    nativePending:false,
    button,
    context,
    key,
    draft:null,
    timeout:null,
    surface:captureShareSurface(),
  };
  runtime.active = attempt;

  try {
    const draft = await obtainDraft(context, key);
    if (runtime.active?.id !== attempt.id) return;
    if (!draft?.token) throw new Error('Не удалось подготовить приглашение.');

    attempt.draft = draft;
    attempt.opening = false;
    resetButton(attempt);

    const telegram = getTelegram();
    const preparedId = String(draft.prepared_message_id || '');
    if (preparedId && typeof telegram?.shareMessage === 'function') {
      openPreparedMessage(telegram, preparedId, attempt);
      return;
    }

    openFallbackShare(draft);
    runtime.active = null;
  } catch (error) {
    if (runtime.active?.id === attempt.id) runtime.active = null;
    resetButton(attempt);
    restoreShareSurface(attempt.surface);
    toast(error?.message || 'Не удалось подготовить приглашение.');
    void warmContextSafely(context);
  }
}

function warmContextSafely(context){
  return warmContext(context).catch(() => null);
}

function warmContext(context){
  const normalized = normalizeContext(context);
  const key = inviteContextKey(normalized);
  if (runtime.warm?.key === key && ['loading','ready'].includes(runtime.warm.status)) return runtime.warm.promise;

  const previous = runtime.warm;
  const entry = {
    id:++runtime.sequence,
    key,
    context:normalized,
    status:'loading',
    draft:null,
    controller:new AbortController(),
    promise:null,
  };
  runtime.warm = entry;

  entry.promise = inviteRequest('create_link_draft', normalized, {
    prefetch:true,
    signal:entry.controller.signal,
  })
    .then(result => {
      const draft = result?.invite || null;
      if (!draft?.token) throw new Error('Не удалось подготовить приглашение.');
      entry.draft = draft;
      entry.status = 'ready';
      if (runtime.warm?.id !== entry.id) discardDraft(draft);
      return draft;
    })
    .catch(error => {
      entry.status = 'failed';
      if (runtime.warm?.id === entry.id) runtime.warm = null;
      throw error;
    });

  if (previous?.status === 'ready' && previous.draft?.token) discardDraft(previous.draft);
  else if (previous?.status === 'loading') previous.controller?.abort('superseded-share-context');
  return entry.promise;
}

function cancelWarmPreparation(){
  const warm = runtime.warm;
  runtime.warm = null;
  if (!warm) return;
  if (warm.status === 'loading') warm.controller?.abort('direct-player-picker');
  else if (warm.status === 'ready' && warm.draft?.token) discardDraft(warm.draft);
}

async function obtainDraft(context, key){
  const warm = runtime.warm;
  if (warm?.key === key) {
    if (warm.status === 'ready' && warm.draft?.token) {
      runtime.warm = null;
      return warm.draft;
    }
    if (warm.status === 'loading' && warm.promise) {
      const draft = await warm.promise;
      if (runtime.warm?.id === warm.id) runtime.warm = null;
      return draft;
    }
  }

  const result = await inviteRequest('create_link_draft', context);
  return result?.invite || null;
}

function openPreparedMessage(telegram, preparedId, attempt){
  let settled = false;
  attempt.nativePending = true;
  restoreShareSurface(attempt.surface);

  const finish = sent => {
    if (settled) return;
    settled = true;
    attempt.nativePending = false;
    window.clearTimeout(attempt.timeout);
    restoreShareSurface(attempt.surface);
    if (runtime.active?.id === attempt.id) runtime.active = null;

    const token = String(attempt.draft?.token || '');
    if (!token) return;

    if (sent === true) {
      renderOwnerWaiting(attempt.draft);
      inviteRequest('confirm_shared', { token })
        .then(result => {
          const invite = result?.invite || attempt.draft;
          if (openSheetInviteToken() === token) renderOwnerWaiting(invite);
          dispatchNotificationCount(result?.unread_count);
        })
        .catch(() => null);
      return;
    }

    discardDraft(attempt.draft);
  };

  attempt.timeout = window.setTimeout(() => {
    if (settled) return;
    settled = true;
    attempt.nativePending = false;
    restoreShareSurface(attempt.surface);
    if (runtime.active?.id === attempt.id) runtime.active = null;
  }, CALLBACK_TIMEOUT_MS);

  try {
    telegram.shareMessage(preparedId, result => finish(Boolean(result)));
  } catch (error) {
    window.clearTimeout(attempt.timeout);
    attempt.nativePending = false;
    restoreShareSurface(attempt.surface);
    openFallbackShare(attempt.draft);
    runtime.active = null;
  }
}

function captureShareSurface(){
  const overlay = document.getElementById('sheetOverlay');
  const sheet = document.getElementById('sheet');
  return {
    html:String(sheet?.innerHTML || ''),
    wasActive:Boolean(overlay?.classList.contains('active')),
  };
}

function restoreActiveShareSurface(){
  const attempt = runtime.active;
  if (!attempt?.nativePending) return;
  restoreShareSurface(attempt.surface);
}

function restoreShareSurface(surface){
  if (!surface?.wasActive) return;
  const overlay = document.getElementById('sheetOverlay');
  const sheet = document.getElementById('sheet');
  if (!overlay || !sheet) return;

  if (!String(sheet.innerHTML || '').trim() && surface.html) openSheet(surface.html);
  else overlay.classList.add('active');

  document.documentElement.style.backgroundColor = '#090c14';
  document.body.style.backgroundColor = '#090c14';
  void sheet.offsetHeight;
}

function readInviteContext(){
  const activeSize = document.querySelector('[data-invite-size].active');
  const activeBet = document.querySelector('[data-invite-bet].active');
  const gameType = String(runtime.lastGameType || state.selectedGame || 'tictactoe');
  const room = String(state.room || '') === 'gold' ? 'gold' : 'match';
  const boardSize = Number(activeSize?.dataset.inviteSize || defaultSize(gameType));
  const fallbackBet = room === 'gold'
    ? Number(state.selectedBet || APP_CONFIG.goldBets?.[0] || 10)
    : Number(APP_CONFIG.matchBet || 10);
  const bet = Number(activeBet?.dataset.inviteBet || fallbackBet);
  return normalizeContext({ gameType, room, boardSize, bet });
}

function defaultContext(gameType){
  const room = String(state.room || '') === 'gold' ? 'gold' : 'match';
  return normalizeContext({
    gameType,
    room,
    boardSize:defaultSize(gameType),
    bet:room === 'gold' ? Number(state.selectedBet || APP_CONFIG.goldBets?.[0] || 10) : Number(APP_CONFIG.matchBet || 10),
  });
}

function normalizeContext(value){
  const gameType = String(value?.gameType || 'tictactoe');
  const room = String(value?.room || '') === 'gold' ? 'gold' : 'match';
  return {
    gameType,
    room,
    boardSize:Number(value?.boardSize || defaultSize(gameType)),
    bet:Number(value?.bet || (room === 'gold' ? APP_CONFIG.goldBets?.[0] : APP_CONFIG.matchBet) || 10),
  };
}

function defaultSize(gameType){
  return Number(DEFAULT_SIZES[String(gameType || '')] || 3);
}

async function inviteRequest(action, payload = {}, options = {}){
  const response = await fetch(INVITES_URL, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({
      initData:getInitData(),
      sessionId:getSessionId(),
      action,
      ...payload,
    }),
    signal:options.signal,
    mgwPrefetch:Boolean(options.prefetch),
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) throw new Error(data?.error || `Ошибка приглашения: ${response.status}`);
  return data;
}

function discardDraft(invite){
  const token = String(invite?.token || '');
  if (!token) return;
  inviteRequest('discard_draft', { token }).catch(() => null);
}

function openFallbackShare(invite){
  const shareUrl = String(invite?.share_url || '');
  if (!shareUrl) return toast('Ссылка временно недоступна.');
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
  const variant = String(invite?.game_type || '') === 'domino' ? 'Классика 0–6' : `${size}×${size}`;
  return `
    <div class="topup-success">
      <div><span>Игра</span><strong>${escapeHtml(invite?.game_title || 'Игра')}</strong></div>
      <div><span>Комната</span><strong>${escapeHtml(invite?.room_label || room)}</strong></div>
      <div><span>Вариант</span><strong>${escapeHtml(invite?.board_label || variant)}</strong></div>
      <div><span>Ставка</span><strong>${Number(invite?.bet || 0)} коинов</strong></div>
    </div>
  `;
}

function openSheetInviteToken(){
  return String(document.querySelector('#sheet [data-invite-sheet][data-invite-token]')?.dataset.inviteToken || '');
}

function dispatchNotificationCount(value){
  if (!Number.isFinite(Number(value))) return;
  document.dispatchEvent(new CustomEvent('mgw:notification-count', { detail:{ unreadCount:Number(value) } }));
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
