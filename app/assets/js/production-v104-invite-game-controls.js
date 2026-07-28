import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { openSheet, closeSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { renderBalances } from './ui.js?v=89';
import { getInitData, haptic } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { currentV99PassiveLock } from './production-v99-session-transport.js?v=99';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const DEFAULT_SIZE = {
  tictactoe:3,
  four_in_a_row:7,
  battleship:10,
  checkers:8,
  reversi:8,
  chess:8,
  go:9,
  domino:7,
};

const runtime = window.__MGW_V104_INVITE_CONTROLS__ ||= {
  initialized:false,
  lastGameType:'tictactoe',
  pickerContext:null,
  requestPending:false,
  lastLockToastAt:0,
};

export function initV104InviteGameControls(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  document.addEventListener('pointerdown', handlePointerDown, true);
  document.addEventListener('click', handleClick, true);
  document.addEventListener('mgw:v99-passive-lock-changed', updateInviteButtons);
  window.setTimeout(updateInviteButtons, 0);
}

function handlePointerDown(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;

  /* A prepared Telegram message is itself background work needed by the Share
   * click, so it must not be cancelled here. */
  if (origin.closest('#confirmLeaveGame, [data-direct-opponent], [data-invite-action]')) {
    abortCompetingReads('v104-game-control');
  }

  const invite = origin.closest('[data-invite-friend]');
  if (!invite) return;
  runtime.lastGameType = String(invite.dataset.inviteFriend || 'tictactoe');
  if (!currentV99PassiveLock()?.locked) return;
  event.preventDefault();
  event.stopImmediatePropagation();
}

function handleClick(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;

  const invite = origin.closest('[data-invite-friend]');
  if (invite) {
    runtime.lastGameType = String(invite.dataset.inviteFriend || 'tictactoe');
    if (currentV99PassiveLock()?.locked) {
      event.preventDefault();
      event.stopImmediatePropagation();
      showLockMessage();
    }
    return;
  }

  const share = origin.closest('[data-create-link-invite]');
  if (share && currentV99PassiveLock()?.locked) {
    event.preventDefault();
    event.stopImmediatePropagation();
    showLockMessage();
    return;
  }

  const picker = origin.closest('[data-open-player-picker]');
  if (picker) {
    if (currentV99PassiveLock()?.locked) {
      event.preventDefault();
      event.stopImmediatePropagation();
      showLockMessage();
      return;
    }
    runtime.pickerContext = readInviteContext();
    return;
  }

  const opponent = origin.closest('[data-direct-opponent]');
  if (!(opponent instanceof HTMLButtonElement)) return;
  event.preventDefault();
  event.stopImmediatePropagation();

  if (currentV99PassiveLock()?.locked) {
    showLockMessage();
    return;
  }

  const inviteeId = String(opponent.dataset.directOpponent || '');
  if (!inviteeId || runtime.requestPending) return;
  void createDirectInvite(inviteeId, runtime.pickerContext || readInviteContext());
}

function updateInviteButtons(){
  const locked = Boolean(currentV99PassiveLock()?.locked);
  document.querySelectorAll('[data-invite-friend], [data-open-player-picker], [data-create-link-invite]').forEach(button => {
    if (!(button instanceof HTMLButtonElement)) return;
    button.setAttribute('aria-disabled', locked ? 'true' : 'false');
    button.classList.toggle('mgw-session-locked', locked);
  });
}

function showLockMessage(){
  const now = Date.now();
  if (now - runtime.lastLockToastAt < 1600) return;
  runtime.lastLockToastAt = now;
  toast(String(currentV99PassiveLock()?.message || 'У вас уже идёт активная игра на другом устройстве. Продолжайте игру там.'));
}

async function createDirectInvite(inviteeId, context){
  runtime.requestPending = true;
  abortCompetingReads('v104-direct-invite');
  haptic('light');
  renderSendingSheet(context);

  try {
    const result = await inviteRequest('create_direct', { ...context, inviteeId });
    if (result?.user) {
      state.user = result.user;
      renderBalances(state.user);
    }
    if (result?.session) state.session = result.session;
    const inviteResult = result?.invite || null;
    if (!inviteResult?.token) throw new Error('Не удалось создать приглашение.');
    renderOwnerWaiting(inviteResult);
    if (Number.isFinite(Number(result?.unread_count))) {
      document.dispatchEvent(new CustomEvent('mgw:notification-count', {
        detail:{ unreadCount:Number(result.unread_count) },
      }));
    }
  } catch (error) {
    renderInviteError(context, error?.message || 'Не удалось отправить приглашение.');
  } finally {
    runtime.requestPending = false;
  }
}

function renderSendingSheet(context){
  openSheet(`
    <div class="sheet-head">
      <div><h2>Отправляем приглашение</h2><p>${escapeHtml(gameTitle(context.gameType))} · ${escapeHtml(roomLabel(context.room))}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="small-note">Приглашение уже создаётся. Окно обновится автоматически.</div>
  `);
}

function renderOwnerWaiting(inviteResult){
  const token = String(inviteResult?.token || '');
  openSheet(`
    <span data-invite-sheet data-invite-token="${escapeHtml(token)}" data-invite-state="pending:owner" hidden></span>
    <div class="sheet-head">
      <div><h2>Приглашение отправлено</h2><p>Игрок сразу увидит его в приложении.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(inviteResult)}
    <div class="small-note invite-status-note">Ждём ответа игрока. Коины пока не списываются.</div>
    <button class="btn ghost full" data-invite-action="cancel" data-invite-token="${escapeHtml(token)}" type="button">Отменить приглашение</button>
  `);
}

function renderInviteError(context, message){
  openSheet(`
    <div class="sheet-head">
      <div><h2>Не удалось отправить приглашение</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="small-note">${escapeHtml(message)}</div>
    <button class="btn primary full" data-v104-retry-invite type="button">Вернуться к приглашению</button>
  `);
  document.querySelector('[data-v104-retry-invite]')?.addEventListener('click', () => {
    closeSheet();
    const trigger = document.querySelector(`[data-invite-friend="${cssEscape(context.gameType)}"]`);
    if (trigger instanceof HTMLButtonElement) trigger.click();
  });
}

function readInviteContext(){
  const gameType = String(runtime.lastGameType || state.selectedGame || 'tictactoe');
  const room = String(state.room || '') === 'gold' ? 'gold' : 'match';
  const boardSize = Number(document.querySelector('[data-invite-size].active')?.dataset.inviteSize || DEFAULT_SIZE[gameType] || 3);
  const fallbackBet = room === 'gold'
    ? Number(state.selectedBet || APP_CONFIG.goldBets?.[0] || 10)
    : Number(APP_CONFIG.matchBet || 10);
  const bet = Number(document.querySelector('[data-invite-bet].active')?.dataset.inviteBet || fallbackBet);
  return { gameType, room, boardSize, bet };
}

async function inviteRequest(action, payload){
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
  if (!response.ok || !data || data.ok === false) throw new Error(data?.error || `Ошибка приглашения: ${response.status}`);
  return data;
}

function abortCompetingReads(reason){
  const speed = window.__MGW_V101_SPEED__;
  for (const set of [speed?.gamePollControllers, speed?.backgroundControllers]) {
    if (!set || typeof set[Symbol.iterator] !== 'function') continue;
    for (const controller of [...set]) {
      try { controller.abort(reason); } catch (error) {}
    }
    set.clear?.();
  }
}

function inviteSummary(inviteResult){
  const gameType = String(inviteResult?.game_type || 'tictactoe');
  const size = Number(inviteResult?.board_size || DEFAULT_SIZE[gameType] || 3);
  return `
    <div class="topup-success">
      <div><span>Игра</span><strong>${escapeHtml(inviteResult?.game_title || gameTitle(gameType))}</strong></div>
      <div><span>Комната</span><strong>${escapeHtml(inviteResult?.room_label || roomLabel(inviteResult?.room))}</strong></div>
      <div><span>Вариант</span><strong>${escapeHtml(gameType === 'domino' ? 'Классика 0–6' : `${size}×${size}`)}</strong></div>
      <div><span>Ставка</span><strong>${Number(inviteResult?.bet || 0)} коинов</strong></div>
    </div>
  `;
}

function gameTitle(type){
  return {
    tictactoe:'Крестики-нолики',
    four_in_a_row:'4 в ряд',
    battleship:'Морской бой',
    checkers:'Шашки',
    reversi:'Реверси',
    chess:'Шахматы',
    go:'Го',
    domino:'Домино',
  }[String(type || '')] || 'Игра';
}

function roomLabel(room){
  return String(room || '') === 'gold' ? 'Gold-комната' : 'Матч-комната';
}

function cssEscape(value){
  if (globalThis.CSS?.escape) return CSS.escape(String(value || ''));
  return String(value || '').replace(/[^a-zA-Z0-9_-]/g, '');
}

function escapeHtml(value){
  return String(value ?? '').replace(/[&<>'"]/g, character => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;',
  }[character]));
}
