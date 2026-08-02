import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { openSheet, closeSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { renderBalances } from './ui.js?v=89';
import { getInitData, haptic } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { currentV99PassiveLock } from './production-v99-session-transport.js?v=99';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const WATCH_URL = `${window.location.origin}/bot/invite-watch.php`;
const WATCH_INTERVAL_MS = 500;
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

const runtime = window.__MGW_V105_INVITE_LATENCY__ ||= {
  initialized:false,
  requestPending:false,
  requestSerial:0,
  cancelTokens:new Set(),
  watchTimer:null,
  watchBusy:false,
  announcedTokens:new Set(),
};

export function initV105InviteLatency(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  document.addEventListener('click', handleClick, true);
  document.addEventListener('mgw:app-ready', () => scheduleWatch(0), { once:true });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') scheduleWatch(0);
  });
  document.addEventListener('mgw:game-dismissed', () => scheduleWatch(0));
}

function handleClick(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;

  const cancel = origin.closest('[data-invite-action="cancel"]');
  if (cancel instanceof HTMLButtonElement) {
    event.preventDefault();
    event.stopImmediatePropagation();
    const token = String(cancel.dataset.inviteToken || '');
    if (token) void cancelInviteImmediately(token);
    return;
  }

  const opponent = origin.closest('[data-direct-opponent]');
  if (!(opponent instanceof HTMLButtonElement)) return;
  event.preventDefault();
  event.stopImmediatePropagation();

  if (currentV99PassiveLock()?.locked) {
    toast(String(currentV99PassiveLock()?.message || 'У вас уже идёт активная игра на другом устройстве. Продолжайте игру там.'));
    return;
  }

  const inviteeId = String(opponent.dataset.directOpponent || '');
  if (!inviteeId || runtime.requestPending) return;
  const opponentName = String(opponent.querySelector('strong')?.textContent || 'Игрок').trim() || 'Игрок';
  void createDirectInviteImmediately(inviteeId, opponentName, readInviteContext());
}

async function createDirectInviteImmediately(inviteeId, opponentName, context){
  runtime.requestPending = true;
  const requestId = String(++runtime.requestSerial);
  abortCompetingReads('v105-direct-invite');
  haptic('light');
  renderOptimisticOwnerSheet(context, opponentName, requestId);

  try {
    const result = await inviteRequest('create_direct', { ...context, inviteeId });
    rememberState(result);
    const invite = result?.invite || null;
    if (!invite?.token) throw new Error('Не удалось создать приглашение.');

    if (document.querySelector(`[data-v105-direct-request="${cssEscape(requestId)}"]`)) {
      renderOwnerWaiting(invite);
    }
    dispatchNotificationCount(result?.unread_count);
  } catch (error) {
    if (document.querySelector(`[data-v105-direct-request="${cssEscape(requestId)}"]`)) {
      renderInviteError(error?.message || 'Не удалось отправить приглашение.');
    } else {
      toast(error?.message || 'Не удалось отправить приглашение.');
    }
  } finally {
    runtime.requestPending = false;
    scheduleWatch(0);
  }
}

async function cancelInviteImmediately(token){
  if (runtime.cancelTokens.has(token)) return;
  runtime.cancelTokens.add(token);
  abortCompetingReads('v105-cancel-invite');
  haptic('light');

  const sheet = document.getElementById('sheet');
  const rollbackHtml = String(sheet?.innerHTML || '');
  closeSheet();
  sheet?.replaceChildren();

  try {
    const result = await inviteRequest('cancel', { token });
    rememberState(result);
    toast('Приглашение отменено.');
    document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
    dispatchNotificationCount(result?.unread_count);
  } catch (error) {
    const overlayActive = document.getElementById('sheetOverlay')?.classList.contains('active');
    if (!overlayActive && rollbackHtml) openSheet(rollbackHtml);
    toast(error?.message || 'Не удалось отменить приглашение.');
  } finally {
    runtime.cancelTokens.delete(token);
    scheduleWatch(0);
  }
}

function scheduleWatch(delay = WATCH_INTERVAL_MS){
  window.clearTimeout(runtime.watchTimer);
  runtime.watchTimer = window.setTimeout(async () => {
    await watchIncomingInvite();
    scheduleWatch(WATCH_INTERVAL_MS);
  }, Math.max(0, Number(delay || 0)));
}

async function watchIncomingInvite(){
  if (runtime.watchBusy || !canWatchInvites()) return;
  runtime.watchBusy = true;
  try {
    const response = await fetch(WATCH_URL, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      body:JSON.stringify({ initData:getInitData(), sessionId:getSessionId() }),
      priority:'low',
      mgwPrefetch:true,
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data || data.ok === false) return;

    const invite = data?.invite || null;
    const token = String(invite?.token || '');
    if (!token || runtime.announcedTokens.has(token)) return;
    if (!canWatchInvites()) return;

    runtime.announcedTokens.add(token);
    openIncomingInvite(invite);
    dispatchNotificationCount(data?.unread_count);
  } catch (error) {
    // The retained invite sync remains the fallback.
  } finally {
    runtime.watchBusy = false;
  }
}

function canWatchInvites(){
  if (document.visibilityState !== 'visible') return false;
  if (String(state.activeGame?.status || '') === 'active') return false;
  if (currentV99PassiveLock()?.locked) return false;
  const activeScreen = document.querySelector('.screen.active');
  if (String(activeScreen?.dataset.screen || '') !== 'home') return false;
  return !document.getElementById('sheetOverlay')?.classList.contains('active');
}

function renderOptimisticOwnerSheet(context, opponentName, requestId){
  openSheet(`
    <span data-v105-direct-request="${escapeHtml(requestId)}" hidden></span>
    <div class="sheet-head">
      <div><h2>Приглашение отправлено</h2><p>${escapeHtml(opponentName)} получит его в приложении.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${contextSummary(context)}
    <div class="small-note invite-status-note">Доставляем приглашение игроку…</div>
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

function openIncomingInvite(invite){
  const token = String(invite?.token || '');
  openSheet(`
    <span data-invite-sheet data-invite-token="${escapeHtml(token)}" data-invite-state="pending:invitee" hidden></span>
    <div class="sheet-head">
      <div><h2>Вас приглашают сыграть</h2><p>От ${escapeHtml(invite?.inviter_name || 'игрока')}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="stack invite-actions">
      <button class="btn primary full" data-invite-action="accept" data-invite-token="${escapeHtml(token)}" type="button">Принять приглашение</button>
      <button class="btn ghost full" data-invite-action="decline" data-invite-token="${escapeHtml(token)}" type="button">Отклонить</button>
    </div>
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
    const gameType = String(retained.gameType || 'tictactoe');
    const room = String(retained.room || '') === 'gold' ? 'gold' : 'match';
    const boardSize = Number(retained.boardSize || DEFAULT_SIZE[gameType] || 3);
    const bet = Number(retained.bet || (room === 'gold' ? APP_CONFIG.goldBets?.[0] : APP_CONFIG.matchBet) || 10);
    return { gameType, room, boardSize, bet };
  }

  const gameType = String(state.selectedGame || 'tictactoe');
  const room = String(state.room || '') === 'gold' ? 'gold' : 'match';
  const boardSize = Number(document.querySelector('[data-invite-size].active')?.dataset.inviteSize || DEFAULT_SIZE[gameType] || 3);
  const fallbackBet = room === 'gold'
    ? Number(state.selectedBet || APP_CONFIG.goldBets?.[0] || 10)
    : Number(APP_CONFIG.matchBet || 10);
  const bet = Number(document.querySelector('[data-invite-bet].active')?.dataset.inviteBet || fallbackBet);
  return { gameType, room, boardSize, bet };
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

function dispatchNotificationCount(unreadCount){
  if (!Number.isFinite(Number(unreadCount))) return;
  document.dispatchEvent(new CustomEvent('mgw:notification-count', {
    detail:{ unreadCount:Number(unreadCount) },
  }));
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
