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

const runtime = window.__MGW_V109_INVITE_SPEED__ ||= {
  initialized:false,
  requestPending:false,
  cancelTokens:new Set(),
  lastContext:null,
};

export function initV109InviteSpeed(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  // Window capture runs before every retained document/element owner. This keeps
  // the old v105 graph for everything else while giving these two slow actions
  // one deterministic optimistic owner.
  window.addEventListener('click', ownInviteClick, true);
}

function ownInviteClick(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;

  const cancel = origin.closest('[data-invite-action="cancel"]');
  if (cancel instanceof HTMLButtonElement) {
    const token = String(cancel.dataset.inviteToken || '');
    if (!token) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    void cancelInviteImmediately(token);
    return;
  }

  const opponent = origin.closest('[data-direct-opponent]');
  if (!(opponent instanceof HTMLButtonElement)) return;

  event.preventDefault();
  event.stopImmediatePropagation();

  const lock = currentV99PassiveLock();
  if (lock?.locked) {
    toast(String(lock.message || 'У вас уже идёт активная игра на другом устройстве. Продолжайте игру там.'));
    return;
  }

  const inviteeId = String(opponent.dataset.directOpponent || '');
  if (!inviteeId || runtime.requestPending) return;
  const opponentName = String(opponent.querySelector('strong')?.textContent || 'Игрок').trim() || 'Игрок';
  const context = readInviteContext();
  runtime.lastContext = context;
  window.__MGW_V109_LAST_INVITE_CONTEXT__ = context;
  void createDirectInviteImmediately(inviteeId, opponentName, context);
}

async function createDirectInviteImmediately(inviteeId, opponentName, context){
  runtime.requestPending = true;
  abortCompetingReads('v109-direct-invite');
  haptic('light');

  // The final-looking owner surface is painted before the network request.
  // A rejected request replaces it with an error, so server authority is kept.
  renderOptimisticOwnerSheet(context, opponentName);

  try {
    const result = await inviteRequest('create_direct', { ...context, inviteeId });
    rememberState(result);
    const invite = result?.invite || null;
    if (!invite?.token) throw new Error('Не удалось создать приглашение.');

    renderOwnerWaiting(invite);
    dispatchNotificationCount(result?.unread_count);
    document.dispatchEvent(new CustomEvent('mgw:v109-invite-created', {
      detail:{ invite, context },
    }));
  } catch (error) {
    renderInviteError(error?.message || 'Не удалось отправить приглашение.');
  } finally {
    runtime.requestPending = false;
  }
}

async function cancelInviteImmediately(token){
  if (runtime.cancelTokens.has(token)) return;
  runtime.cancelTokens.add(token);
  abortCompetingReads('v109-cancel-invite');
  haptic('light');

  const sheet = document.getElementById('sheet');
  const rollbackHtml = String(sheet?.innerHTML || '');
  closeSheet();
  sheet?.replaceChildren();

  try {
    const result = await inviteRequest('cancel', { token });
    rememberState(result);

    // No local "Приглашение отменено" toast: the person who pressed Cancel
    // already sees the immediate closed surface. The server notification remains
    // addressed only to the other participant.
    document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
    document.dispatchEvent(new CustomEvent('mgw:v109-invite-slot-free', {
      detail:{ context:runtime.lastContext || window.__MGW_V109_LAST_INVITE_CONTEXT__ || null },
    }));
    dispatchNotificationCount(result?.unread_count);
  } catch (error) {
    const overlayActive = document.getElementById('sheetOverlay')?.classList.contains('active');
    if (!overlayActive && rollbackHtml) openSheet(rollbackHtml);
    toast(error?.message || 'Не удалось отменить приглашение.');
  } finally {
    runtime.cancelTokens.delete(token);
  }
}

function renderOptimisticOwnerSheet(context, opponentName){
  openSheet(`
    <span data-v109-direct-pending hidden></span>
    <div class="sheet-head">
      <div><h2>Приглашение отправлено</h2><p>${escapeHtml(opponentName)} сразу увидит его в приложении.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${contextSummary(context)}
    <div class="small-note invite-status-note">Доставляем приглашение. Коины пока не списываются.</div>
  `);
}

function renderOwnerWaiting(invite){
  const token = String(invite?.token || '');
  openSheet(`
    <span data-invite-sheet data-invite-token="${escapeHtml(token)}" data-invite-state="pending:owner" hidden></span>
    <div class="sheet-head">
      <div><h2>${String(invite?.source || '') === 'rematch' ? 'Реванш предложен' : 'Приглашение отправлено'}</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">Ждём ответа игрока. Коины пока не списываются.</div>
    <button class="btn ghost full" data-invite-action="cancel" data-invite-token="${escapeHtml(token)}" type="button">Отменить приглашение</button>
  `);
}

function renderInviteError(message){
  openSheet(`
    <div class="sheet-head">
      <div><h2>Не удалось отправить приглашение</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="small-note">${escapeHtml(message)}</div>
    <button class="btn primary full" data-close-sheet type="button">Понятно</button>
  `);
}

function readInviteContext(){
  const retained = window.__MGW_V104_INVITE_CONTROLS__?.pickerContext;
  if (retained && typeof retained === 'object') {
    return normalizeContext(retained);
  }

  const gameType = String(state.selectedGame || 'tictactoe');
  const room = String(state.room || '') === 'gold' ? 'gold' : 'match';
  const boardSize = Number(document.querySelector('[data-invite-size].active')?.dataset.inviteSize || DEFAULT_SIZE[gameType] || 3);
  const fallbackBet = room === 'gold'
    ? Number(state.selectedBet || APP_CONFIG.goldBets?.[0] || 10)
    : Number(APP_CONFIG.matchBet || 10);
  const bet = Number(document.querySelector('[data-invite-bet].active')?.dataset.inviteBet || fallbackBet);
  return normalizeContext({ gameType, room, boardSize, bet });
}

function normalizeContext(value){
  const gameType = String(value?.gameType || 'tictactoe');
  const room = String(value?.room || '') === 'gold' ? 'gold' : 'match';
  return {
    gameType,
    room,
    boardSize:Number(value?.boardSize || DEFAULT_SIZE[gameType] || 3),
    bet:Number(value?.bet || (room === 'gold' ? APP_CONFIG.goldBets?.[0] : APP_CONFIG.matchBet) || 10),
  };
}

function contextSummary(context){
  return `
    <div class="topup-success">
      <div><span>Игра</span><strong>${escapeHtml(gameTitle(context.gameType))}</strong></div>
      <div><span>Комната</span><strong>${escapeHtml(roomLabel(context.room))}</strong></div>
      <div><span>Вариант</span><strong>${escapeHtml(contextVariant(context))}</strong></div>
      <div><span>Ставка</span><strong>${Number(context.bet || 0)} коинов</strong></div>
    </div>
  `;
}

function contextVariant(context){
  const type = String(context?.gameType || '');
  const size = Number(context?.boardSize || DEFAULT_SIZE[type] || 3);
  if (type === 'domino') return 'Классика 0–6';
  if (type === 'four_in_a_row') return `${size}×${Math.max(5, size - 1)}`;
  return `${size}×${size}`;
}

function inviteSummary(invite){
  const columns = Number(invite?.board_columns || invite?.board_size || 3);
  const rows = Number(invite?.board_rows || invite?.board_size || 3);
  const variant = String(invite?.game_type || '') === 'domino' ? 'Классика 0–6' : `${columns}×${rows}`;
  return `
    <div class="topup-success">
      <div><span>Игра</span><strong>${escapeHtml(invite?.game_title || gameTitle(invite?.game_type))}</strong></div>
      <div><span>Комната</span><strong>${escapeHtml(invite?.room_label || roomLabel(invite?.room))}</strong></div>
      <div><span>Вариант</span><strong>${escapeHtml(variant)}</strong></div>
      <div><span>Ставка</span><strong>${Number(invite?.bet || 0)} коинов</strong></div>
    </div>
  `;
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

function rememberState(result){
  if (result?.user) {
    state.user = result.user;
    renderBalances(state.user);
  }
  if (result?.session) state.session = result.session;
}

function dispatchNotificationCount(value){
  if (!Number.isFinite(Number(value))) return;
  document.dispatchEvent(new CustomEvent('mgw:notification-count', {
    detail:{ unreadCount:Number(value) },
  }));
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

function escapeHtml(value){
  return String(value ?? '').replace(/[&<>'"]/g, character => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;',
  }[character]));
}
