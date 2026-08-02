import { state } from './state.js?v=27';
import { openSheet, closeSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { showScreen } from './router.js?v=27';
import { renderBalances } from './ui.js?v=89';
import { getTelegram, getInitData, haptic } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { currentV99PassiveLock } from './production-v99-session-transport.js?v=99';
import { enterGame } from './screens/game-screen-v102-safe.js?v=102';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const runtime = window.__MGW_V107_INVITES__ ||= {
  initialized:false,
  pending:new Set(),
  actionBusy:false,
  appReady:false,
  lastLaunchToken:'',
  syncBusy:false,
  syncGeneration:0,
};

export function initV107InviteActions(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  window.addEventListener('click', ownInviteAction, true);
  document.addEventListener('mgw:app-ready', () => {
    runtime.appReady = true;
    runtime.lastLaunchToken = readLaunchToken();
  }, { once:true });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') window.setTimeout(() => refreshActivatedInvite(false), 0);
  });

  const tg = getTelegram();
  if (typeof tg?.onEvent === 'function') {
    try { tg.onEvent('activated', () => window.setTimeout(() => refreshActivatedInvite(true), 0)); } catch (error) {}
  }
}

function ownInviteAction(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const button = origin.closest('[data-invite-action]');
  if (!(button instanceof HTMLButtonElement)) return;

  const action = String(button.dataset.inviteAction || '');
  if (!['accept','start','decline','cancel'].includes(action)) return;
  const token = String(button.dataset.inviteToken || '');
  if (!token) return;

  event.preventDefault();
  event.stopImmediatePropagation();

  const lock = currentV99PassiveLock();
  if (lock?.locked && ['accept','start'].includes(action)) {
    toast(String(lock.message || 'У вас уже идёт активная игра на другом устройстве. Продолжайте игру там.'));
    return;
  }

  const requestKey = `${action}:${token}`;
  if (runtime.pending.has(requestKey)) return;
  runtime.pending.add(requestKey);
  void performImmediateAction(action, token, requestKey);
}

async function performImmediateAction(action, token, requestKey){
  haptic('light');
  runtime.actionBusy = true;
  runtime.syncGeneration++;
  abortCompetingReads(`v107-invite-${action}`);

  const sheet = document.getElementById('sheet');
  const rollbackHtml = String(sheet?.innerHTML || '');
  const previousScreen = String(document.querySelector('.screen.active')?.dataset.screen || 'home');
  const summary = String(sheet?.querySelector('.topup-success')?.outerHTML || '');

  if (action === 'accept') renderAcceptedImmediately(token, summary);
  else if (action === 'start') showPendingGameLaunch(summary);
  else {
    closeSheet();
    sheet?.replaceChildren();
  }

  try {
    const result = await inviteRequest(action, {token});
    rememberState(result);

    let launch = activeGameFrom(result);
    if (!launch && action === 'start') launch = await recoverStartedGame(token);
    if (launch?.game?.id) {
      enterGame(launch.game, launch.me || null);
      dispatchNotifications(launch.result || result);
      return;
    }

    const invite = result?.invite || null;
    if (action === 'accept' && invite?.token) renderInviteeWaiting(invite);
    else if (action === 'start') throw new Error('Игра не запустилась. Попробуйте ещё раз.');

    if (action === 'cancel' || action === 'decline') {
      closeSheet();
      sheet?.replaceChildren();
    }
    dispatchNotifications(result);
  } catch (error) {
    if (action === 'start') {
      const recovered = await recoverStartedGame(token).catch(() => null);
      if (recovered?.game?.id) {
        enterGame(recovered.game, recovered.me || null);
        dispatchNotifications(recovered.result || null);
        return;
      }
    }

    if (String(state.activeGame?.status || '') !== 'active') {
      restorePreviousScreen(previousScreen);
      if (rollbackHtml) openSheet(rollbackHtml);
      toast(error?.message || 'Не удалось выполнить действие.');
    }
  } finally {
    runtime.actionBusy = false;
    runtime.pending.delete(requestKey);
  }
}

async function recoverStartedGame(token){
  const waits = [0, 120, 260, 500];
  for (const delay of waits) {
    if (delay > 0) await sleep(delay);
    const result = await inviteRequest('sync', {token}).catch(() => null);
    rememberState(result);
    const launch = activeGameFrom(result);
    if (launch?.game?.id) return {...launch, result};
  }
  return null;
}

function activeGameFrom(result){
  const game = result?.game || result?.active_game || null;
  if (!game?.id || String(game.status || '') !== 'active') return null;
  return {game, me:result?.me || null, result};
}

function renderAcceptedImmediately(token, summary){
  openSheet(`
    <span data-invite-sheet data-invite-token="${escapeHtml(token)}" data-invite-state="awaiting_start:invitee" hidden></span>
    <div class="sheet-head">
      <div><h2>Приглашение принято</h2><p>Ждём запуска матча от соперника.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${summary}
    <div class="small-note invite-status-note">Можно оставаться в приложении — матч откроется автоматически.</div>
    <button class="btn ghost full" data-invite-action="cancel" data-invite-token="${escapeHtml(token)}" type="button">Отменить участие</button>
  `);
}

function showPendingGameLaunch(summary){
  closeSheet();
  showScreen('game');
  const turn = document.getElementById('turnText');
  const timer = document.getElementById('timerText');
  const stableTimer = document.getElementById('timerTextV107');
  const players = document.getElementById('playersRow');
  const board = document.getElementById('gameBoard');
  if (turn) turn.textContent = 'Запускаем матч';
  if (timer) timer.textContent = '60 сек';
  if (stableTimer) stableTimer.textContent = '60 сек';
  if (players) players.innerHTML = '<div class="game-player active"><div class="name">Соперник готов</div><div class="mark">Подключаем игровое поле</div></div>';
  if (board) {
    board.replaceChildren();
    board.className = 'board size-3 is-pending-launch';
    board.innerHTML = `<div class="notifications-loading"><strong>Матч начинается…</strong>${summary ? '<span>Условия подтверждены</span>' : ''}</div>`;
  }
}

function renderInviteeWaiting(invite){
  openSheet(`
    <span data-invite-sheet data-invite-token="${escapeHtml(invite.token || '')}" data-invite-state="awaiting_start:invitee" hidden></span>
    <div class="sheet-head">
      <div><h2>Приглашение принято</h2><p>Ждём запуска матча от ${escapeHtml(invite.inviter_name || 'соперника')}.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">Можно оставаться в приложении — матч откроется автоматически.</div>
    <button class="btn ghost full" data-invite-action="cancel" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Отменить участие</button>
  `);
}

async function refreshActivatedInvite(fromTelegramActivation){
  if (!runtime.appReady || runtime.actionBusy || runtime.pending.size || runtime.syncBusy) return;
  if (document.visibilityState !== 'visible' || String(state.activeGame?.status || '') === 'active') return;
  runtime.syncBusy = true;
  const generation = ++runtime.syncGeneration;

  try {
    const launchToken = readLaunchToken();
    const trackedToken = launchToken || visibleInviteToken();
    let result;
    if (launchToken && launchToken !== runtime.lastLaunchToken) {
      runtime.lastLaunchToken = launchToken;
      result = await inviteRequest('open_link', {token:launchToken});
    } else {
      result = await inviteRequest('sync', trackedToken ? {token:trackedToken} : {});
    }
    if (generation !== runtime.syncGeneration || runtime.actionBusy) return;

    rememberState(result);
    const launch = activeGameFrom(result);
    if (launch?.game?.id) {
      enterGame(launch.game, launch.me || null);
      return;
    }

    const invite = result?.invite || result?.tracked_invite || null;
    if (!invite?.token) return;
    dispatchNotifications(result);

    const overlayActive = document.getElementById('sheetOverlay')?.classList.contains('active');
    const homeActive = String(document.querySelector('.screen.active')?.dataset.screen || '') === 'home';
    if (!overlayActive && homeActive && String(invite.status || '') === 'pending' && !invite.is_owner) {
      renderIncomingInvite(invite);
    } else if (!overlayActive && homeActive && String(invite.status || '') === 'awaiting_start' && invite.is_owner) {
      renderOwnerReady(invite);
    } else if (fromTelegramActivation && !overlayActive && homeActive) {
      document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
    }
  } catch (error) {
    // Retained invite watch remains the fallback.
  } finally {
    runtime.syncBusy = false;
  }
}

function renderIncomingInvite(invite){
  openSheet(`
    <span data-invite-sheet data-invite-token="${escapeHtml(invite.token || '')}" data-invite-state="pending:invitee" hidden></span>
    <div class="sheet-head">
      <div><h2>Вас приглашают сыграть</h2><p>От ${escapeHtml(invite.inviter_name || 'игрока')}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="stack invite-actions">
      <button class="btn primary full" data-invite-action="accept" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Принять приглашение</button>
      <button class="btn ghost full" data-invite-action="decline" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Отклонить</button>
    </div>
  `);
}

function renderOwnerReady(invite){
  openSheet(`
    <span data-invite-sheet data-invite-token="${escapeHtml(invite.token || '')}" data-invite-state="awaiting_start:owner" hidden></span>
    <div class="sheet-head">
      <div><h2>Соперник согласен</h2><p>${escapeHtml(invite.invitee_name || 'Игрок')} готов играть.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="stack invite-actions">
      <button class="btn primary full" data-invite-action="start" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Начать игру</button>
      <button class="btn ghost full" data-invite-action="cancel" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Отменить</button>
    </div>
  `);
}

function restorePreviousScreen(screen){
  if (screen !== 'game') showScreen(screen || 'home');
}

function readLaunchToken(){
  const startParam = String(getTelegram()?.initDataUnsafe?.start_param || '');
  const fromTelegram = startParam.startsWith('invite_') ? startParam.slice(7) : '';
  const fromQuery = new URLSearchParams(window.location.search).get('invite') || '';
  const token = String(fromTelegram || fromQuery).toLowerCase();
  return /^[a-f0-9]{24}$/.test(token) ? token : '';
}

function visibleInviteToken(){
  return String(document.querySelector('#sheet [data-invite-token]')?.dataset.inviteToken || '');
}

async function inviteRequest(action, payload){
  const response = await fetch(INVITES_URL, {
    method:'POST',
    headers:{'Content-Type':'application/json'},
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

function dispatchNotifications(result){
  document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
  if (Number.isFinite(Number(result?.unread_count))) {
    document.dispatchEvent(new CustomEvent('mgw:notification-count', {
      detail:{unreadCount:Number(result.unread_count)},
    }));
  }
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

function inviteSummary(invite){
  const gameType = String(invite?.game_type || 'tictactoe');
  const size = Number(invite?.board_size || 3);
  const columns = Number(invite?.board_columns || size);
  const rows = Number(invite?.board_rows || size);
  const variant = gameType === 'domino' ? 'Классика 0–6' : `${columns}×${rows}`;
  return `
    <div class="topup-success">
      <div><span>Игра</span><strong>${escapeHtml(invite?.game_title || 'Игра')}</strong></div>
      <div><span>Комната</span><strong>${escapeHtml(invite?.room_label || (invite?.room === 'gold' ? 'Gold-комната' : 'Матч-комната'))}</strong></div>
      <div><span>Вариант</span><strong>${escapeHtml(variant)}</strong></div>
      <div><span>Ставка</span><strong>${Number(invite?.bet || 0)} коинов</strong></div>
    </div>
  `;
}

function sleep(delay){
  return new Promise(resolve => window.setTimeout(resolve, delay));
}

function escapeHtml(value){
  return String(value ?? '').replace(/[&<>'"]/g, character => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;',
  }[character]));
}
