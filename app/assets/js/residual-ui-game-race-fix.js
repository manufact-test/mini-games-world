import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { api } from './api/client.js?v=47';
import { openSheet, closeSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { getTelegram, getInitData, haptic } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { renderBalances } from './ui.js?v=89';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const SHARE_CALLBACK_TIMEOUT_MS = 90000;
const REFRESH_GAP_MS = 1200;

let earlyInstalled = false;
let afterInstalled = false;
let networkFetch = null;

let historyCache = null;
let notificationsCache = null;
let historyRefreshPromise = null;
let notificationsRefreshPromise = null;
let lastHistoryRefreshAt = 0;
let lastNotificationsRefreshAt = 0;

const gameStateInFlightByKey = new Map();
const gameActionPromiseByKey = new Map();
const latestGameResultByKey = new Map();
const gameGenerationByKey = new Map();
let gameActionBusy = false;
let linkInviteBusy = false;

export function initResidualUiGameRaceFixEarly(){
  if (earlyInstalled) return;
  earlyInstalled = true;

  installCacheObserver();
  document.addEventListener('click', handleEarlyClick, true);

  document.addEventListener('mgw:app-ready', () => {
    refreshHistoryNetwork();
    refreshNotificationsNetwork(false);
  }, { once:true });

  document.addEventListener('mgw:game-dismissed', refreshHistoryNetwork);
  document.addEventListener('mgw:history-refresh', refreshHistoryNetwork);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;
    refreshHistoryNetwork();
    refreshNotificationsNetwork(false);
  });
}

export function initResidualUiGameRaceFixAfter(){
  if (afterInstalled) return;
  afterInstalled = true;

  api.gameState = serializedGameState;
}

function installCacheObserver(){
  networkFetch = window.fetch.bind(window);

  window.fetch = async function residualCacheFetch(input, init = {}){
    const meta = requestMeta(input, init);
    const response = await networkFetch(input, init);
    rememberResponse(meta, response);
    return response;
  };
}

function handleEarlyClick(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const target = origin.closest('button, [role="button"]');
  if (!target) return;

  if (target.id === 'notificationsOpen' && notificationsCache?.data) {
    event.preventDefault();
    event.stopImmediatePropagation();
    haptic('light');
    renderNotificationsSheet(notificationsCache.data.items || []);
    setUnreadCount(0);
    refreshNotificationsNetwork(true);
    return;
  }

  if (target.id === 'balanceHistoryBtn' && historyCache?.data) {
    event.preventDefault();
    event.stopImmediatePropagation();
    renderBalanceHistorySheet(
      historyCache.data.history || {},
      historyCache.data.topups || []
    );
    refreshHistoryNetwork();
    return;
  }

  if (target.id === 'matchHistoryBtn' && historyCache?.data) {
    event.preventDefault();
    event.stopImmediatePropagation();
    renderMatchHistorySheet(historyCache.data.history?.matches || []);
    refreshHistoryNetwork();
    return;
  }

  if (target.matches('[data-create-link-invite]')) {
    event.preventDefault();
    event.stopImmediatePropagation();
    createLinkInviteImmediately(target);
    return;
  }

  if (target.matches('[data-invite-action="cancel"], [data-invite-action="decline"]')) {
    event.preventDefault();
    event.stopImmediatePropagation();
    finishInviteImmediately(target);
    return;
  }

  if (target.matches('[data-discard-draft]')) {
    event.preventDefault();
    event.stopImmediatePropagation();
    discardDraftImmediately(target);
    return;
  }

  if (target.matches('[data-v91-fallback-share]')) {
    event.preventDefault();
    event.stopImmediatePropagation();
    openFallbackShare(target.dataset.shareUrl || '', target.dataset.shareText || '');
    return;
  }

  if (target.matches('[data-v91-copy-link]')) {
    event.preventDefault();
    event.stopImmediatePropagation();
    copyInviteLink(target.dataset.shareUrl || '');
    return;
  }

  const cell = target.closest('[data-game-cell]');
  if (cell) {
    handleTicTacToeCell(event, cell);
  }
}

function handleTicTacToeCell(event, button){
  const game = state.activeGame;
  if (!game?.id || String(game.game_type || 'tictactoe') !== 'tictactoe') return;

  event.preventDefault();
  event.stopImmediatePropagation();

  if (gameActionBusy) return;

  const userId = String(state.user?.id || '');
  const cell = Number(button.dataset.gameCell);
  const board = String(game.board || '');
  const symbol = symbolForUser(game, userId);

  const allowed = String(game.status || '') === 'active'
    && String(game.turn || '') === userId
    && !button.disabled
    && button.textContent.trim() === ''
    && Number.isInteger(cell)
    && cell >= 0
    && cell < board.length
    && board[cell] === '-'
    && Boolean(symbol);

  if (!allowed) return;
  submitOptimisticTicTacToe(game, cell, symbol, button);
}

async function serializedGameState(gameId = null){
  const key = gameKey(gameId);
  const generation = generationFor(key);
  const activeAction = gameActionPromiseByKey.get(key);

  if (activeAction) {
    await activeAction.catch(() => null);
    const latest = latestGameResultByKey.get(key);
    if (latest) return latest;
  }

  const existing = gameStateInFlightByKey.get(key);
  if (existing) return existing;

  const promise = requestApi('game_state', { gameId })
    .then(async result => {
      if (generation !== generationFor(key)) {
        const currentAction = gameActionPromiseByKey.get(key);
        if (currentAction) await currentAction.catch(() => null);
        return latestGameResultByKey.get(key) || result;
      }

      latestGameResultByKey.set(key, result);
      return result;
    })
    .finally(() => {
      if (gameStateInFlightByKey.get(key) === promise) {
        gameStateInFlightByKey.delete(key);
      }
    });

  gameStateInFlightByKey.set(key, promise);
  return promise;
}

async function submitOptimisticTicTacToe(game, cell, symbol, button){
  const key = gameKey(game.id);
  gameActionBusy = true;
  bumpGeneration(key);
  haptic('light');

  const boardElement = document.getElementById('gameBoard');
  const turnElement = document.getElementById('turnText');
  const previousHtml = boardElement?.innerHTML || '';
  const previousClass = boardElement?.className || '';
  const previousTurnText = turnElement?.textContent || '';
  const previousGame = clone(state.activeGame);

  boardElement?.querySelectorAll('[data-game-cell]').forEach(item => {
    item.disabled = true;
    item.classList.add('locked');
  });

  button.textContent = symbol === 'X' ? '✕' : '○';
  button.classList.remove('locked');
  button.classList.add(symbol === 'X' ? 'x' : 'o', 'is-optimistic');
  if (turnElement) turnElement.textContent = 'Ход соперника';

  const actionPromise = requestApi('game_action', {
    gameId:game.id,
    gameAction:{ type:'cell', cell },
  });
  gameActionPromiseByKey.set(key, actionPromise);

  try {
    const result = await actionPromise;
    latestGameResultByKey.set(key, result);

    if (result.user) {
      state.user = result.user;
      state.session = result.session || state.session;
      renderBalances(state.user);
    }

    if (result.game) {
      state.activeGame = result.game;
      renderAuthoritativeTicTacToe(result.game, result.me || { id:state.user?.id });
      if (String(result.game.status || '') === 'finished') refreshHistoryNetwork(true);
    }
  } catch (error) {
    state.activeGame = previousGame;
    latestGameResultByKey.set(key, {
      game:previousGame,
      user:state.user,
      me:{ id:state.user?.id },
    });

    if (boardElement) {
      boardElement.className = previousClass;
      boardElement.innerHTML = previousHtml;
    }
    if (turnElement) turnElement.textContent = previousTurnText;
    toast(error.message || 'Не удалось выполнить ход.');
  } finally {
    if (gameActionPromiseByKey.get(key) === actionPromise) {
      gameActionPromiseByKey.delete(key);
    }
    gameActionBusy = false;
    bumpGeneration(key);
  }
}

function renderAuthoritativeTicTacToe(game, me){
  const container = document.getElementById('gameBoard');
  if (!container) return;

  const boardSize = Number(game?.board_size || 3);
  const board = String(game?.board || '');
  const meId = String(me?.id || state.user?.id || '');

  container.className = `board size-${boardSize}`;
  container.dataset.gameType = 'tictactoe';
  container.innerHTML = board.split('').map((cell, index) => {
    const empty = cell === '-';
    const canMove = String(game?.status || '') === 'active'
      && String(game?.turn || '') === meId
      && empty;
    const label = empty ? '' : (cell === 'X' ? '✕' : '○');
    return `<button class="cell ${cell === 'X' ? 'x' : ''} ${cell === 'O' ? 'o' : ''} ${canMove ? '' : 'locked'}" data-game-cell="${index}" ${canMove ? '' : 'disabled'} type="button">${label}</button>`;
  }).join('');

  const turn = document.getElementById('turnText');
  if (turn) {
    turn.textContent = String(game?.status || '') === 'finished'
      ? 'Игра завершена'
      : (String(game?.turn || '') === meId ? 'Ваш ход' : 'Ход соперника');
  }
}

async function createLinkInviteImmediately(button){
  if (linkInviteBusy || button.disabled) return;
  linkInviteBusy = true;

  const context = readInviteContext();
  button.disabled = true;
  haptic('light');

  try {
    const result = await inviteRequest('create_link_draft', context);
    syncInviteState(result);

    const invite = result.invite || null;
    const token = String(invite?.token || '');
    if (!token) throw new Error('Не удалось подготовить ссылку.');

    const tg = getTelegram();
    const preparedId = String(invite?.prepared_message_id || '');

    if (preparedId && typeof tg?.shareMessage === 'function') {
      const sent = await sharePreparedMessage(tg, preparedId);

      if (sent === true) {
        const optimisticInvite = { ...invite, status:'pending', is_owner:true };
        showInviteWaiting(optimisticInvite, 'Приглашение отправлено.');

        inviteRequest('confirm_shared', { token })
          .then(confirmed => {
            syncInviteState(confirmed);
            const finalInvite = confirmed.invite || optimisticInvite;
            if (openInviteToken() === token) {
              showInviteWaiting(finalInvite, 'Приглашение отправлено. Ждём ответа игрока.');
            }
            document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
          })
          .catch(async error => {
            await inviteRequest('discard_draft', { token }).catch(() => null);
            if (openInviteToken() === token) closeSheet();
            toast(error.message || 'Не удалось подтвердить отправку приглашения.');
          });
        return;
      }

      button.disabled = false;
      toast(sent === false ? 'Отправка отменена.' : 'Telegram не подтвердил отправку.');
      inviteRequest('discard_draft', { token }).catch(() => null);
      return;
    }

    showPreparedLink(invite);
  } catch (error) {
    button.disabled = false;
    toast(error.message || 'Не удалось подготовить приглашение.');
  } finally {
    linkInviteBusy = false;
  }
}

function finishInviteImmediately(button){
  const action = String(button.dataset.inviteAction || '');
  const token = String(button.dataset.inviteToken || '');
  if (!token || !['cancel', 'decline'].includes(action)) return;

  const previousHtml = document.getElementById('sheet')?.innerHTML || '';
  closeSheet();
  haptic('light');

  inviteRequest(action, { token })
    .then(result => {
      syncInviteState(result);
      toast(action === 'decline' ? 'Приглашение отклонено.' : 'Приглашение отменено.');
      document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
    })
    .catch(error => {
      if (previousHtml) openSheet(previousHtml);
      toast(error.message || 'Не удалось отменить приглашение.');
    });
}

function discardDraftImmediately(button){
  const token = String(button.dataset.inviteToken || openInviteToken() || '');
  closeSheet();
  haptic('light');
  if (!token) return;

  inviteRequest('discard_draft', { token }).catch(error => {
    toast(error.message || 'Не удалось удалить черновик приглашения.');
  });
}

function showInviteWaiting(invite, message){
  const token = String(invite?.token || '');
  openSheet(`
    <span data-invite-sheet data-invite-token="${escapeHtml(token)}" data-invite-state="pending:owner" hidden></span>
    <div class="sheet-head">
      <div><h2>Приглашение отправлено</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">${escapeHtml(message)}</div>
    <button class="btn ghost full" data-invite-action="cancel" data-invite-token="${escapeHtml(token)}" type="button">Отменить приглашение</button>
  `);
}

function showPreparedLink(invite){
  const token = String(invite?.token || '');
  const shareUrl = String(invite?.share_url || '');
  const shareText = String(invite?.share_text || '');

  openSheet(`
    <span data-invite-sheet data-invite-token="${escapeHtml(token)}" data-invite-state="draft:owner" hidden></span>
    <div class="sheet-head">
      <div><h2>Ссылка подготовлена</h2><p>Выберите способ отправки.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="stack invite-actions">
      <button class="btn primary full" data-v91-fallback-share data-share-url="${escapeHtml(shareUrl)}" data-share-text="${escapeHtml(shareText)}" type="button">Открыть список Telegram</button>
      <button class="btn ghost full" data-v91-copy-link data-share-url="${escapeHtml(shareUrl)}" type="button">Скопировать ссылку</button>
      <button class="btn ghost full" data-discard-draft data-invite-token="${escapeHtml(token)}" type="button">Отменить</button>
    </div>
  `);
}

function readInviteContext(){
  const title = String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').toLowerCase();
  const gameType = title.includes('4 в ряд') ? 'four_in_a_row'
    : title.includes('морской бой') ? 'battleship'
      : title.includes('шаш') ? 'checkers'
        : title.includes('реверси') ? 'reversi'
          : title.includes('шахмат') ? 'chess'
            : title.includes('домино') ? 'domino'
              : title.includes('го') ? 'go'
                : 'tictactoe';

  return {
    gameType,
    room:state.room === 'gold' ? 'gold' : 'match',
    boardSize:Number(document.querySelector('#sheet [data-invite-size].active')?.dataset.inviteSize || 3),
    bet:Number(document.querySelector('#sheet [data-invite-bet].active')?.dataset.inviteBet || APP_CONFIG.matchBet),
  };
}

function renderBalanceHistorySheet(history, topups = []){
  const operations = Array.isArray(history?.operations) ? history.operations : [];
  const topupHtml = topups.length
    ? topups.slice(0, 20).map(renderTopupHistoryItem).join('')
    : '<div class="small-note">Заявок на пополнение пока нет.</div>';
  const operationHtml = operations.length
    ? operations.slice(0, 20).map(item => `
      <div class="history-item">
        <div>
          <strong>${escapeHtml(item.title || 'Операция')}</strong>
          <span>${escapeHtml(item.description || '')}</span>
          <em>${escapeHtml(formatDate(item.created_at))}</em>
        </div>
        <b class="${item.tone === 'pos' ? 'pos' : (item.tone === 'neg' ? 'neg' : '')}">${escapeHtml(item.amount_label || '0 коинов')}</b>
      </div>
    `).join('')
    : '<div class="small-note">Операций пока нет.</div>';

  openSheet(`
    <div class="sheet-head"><div><h2>История баланса</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="history-tabs" role="tablist">
      <button class="history-tab active" data-v91-history-tab="operations" type="button">Операции</button>
      <button class="history-tab" data-v91-history-tab="topups" type="button">Пополнения</button>
    </div>
    <div class="history-scroll">
      <div class="history-tab-panel active" data-v91-history-panel="operations"><div class="history-section"><h3>Операции баланса</h3><div class="history-list">${operationHtml}</div></div></div>
      <div class="history-tab-panel" data-v91-history-panel="topups"><div class="history-section"><h3>Пополнения</h3><div class="history-list">${topupHtml}</div></div></div>
    </div>
    <button class="btn ghost full" data-close-sheet type="button">Понятно</button>
  `);

  bindHistoryTabs();
}

function renderMatchHistorySheet(matches = []){
  const html = matches.length
    ? matches.slice(0, 20).map(item => {
      const tone = item.tone === 'pos' ? 'pos' : (item.tone === 'neg' ? 'neg' : '');
      const room = item.room_label || (item.room === 'gold' ? 'Gold' : 'Match');
      const board = item.board_size ? `${item.board_size}×${item.board_size}` : 'поле';
      const payout = item.payout ? `+${Number(item.payout).toLocaleString('ru-RU')} коинов` : '';
      return `
        <div class="history-item match-history-item">
          <div>
            <strong>${escapeHtml(item.result || 'Матч')}</strong>
            <span>${escapeHtml(room)} · ${escapeHtml(board)} · ставка ${Number(item.bet || 0).toLocaleString('ru-RU')} коинов</span>
            <span>Соперник: ${escapeHtml(item.opponent || 'Соперник')}</span>
            <em>#${escapeHtml(item.short_id || '')} · ${escapeHtml(formatDate(item.finished_at || item.created_at))}</em>
          </div>
          <b class="${tone}">${escapeHtml(payout)}</b>
        </div>
      `;
    }).join('')
    : '<div class="small-note">Истории матчей пока нет.</div>';

  openSheet(`
    <div class="sheet-head"><div><h2>История матчей</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="history-scroll"><div class="history-section"><h3>Последние игры</h3><div class="history-list">${html}</div></div></div>
    <button class="btn ghost full" data-close-sheet type="button">Понятно</button>
  `);
}

function renderNotificationsSheet(items = []){
  const body = items.length
    ? `<div class="notifications-list">${items.map(renderNotification).join('')}</div>`
    : '<div class="notifications-empty"><div>🔔</div><strong>Пока уведомлений нет</strong></div>';

  openSheet(`
    <div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    ${body}
  `);
}

function renderNotification(item){
  const tone = ['success', 'danger', 'info', 'warning'].includes(String(item?.tone || ''))
    ? String(item.tone)
    : 'info';
  const message = notificationMessage(item);
  const token = String(item?.invite_token || '');
  const actions = Array.isArray(item?.actions) ? item.actions : [];
  const actionHtml = token && actions.length
    ? `<div class="notification-actions invite-actions">${actions.map(action => {
      const primary = action === 'accept' || action === 'start';
      return `<button class="btn ${primary ? 'primary' : 'ghost'} full" data-invite-action="${escapeHtml(action)}" data-invite-token="${escapeHtml(token)}" type="button">${escapeHtml(actionLabel(action))}</button>`;
    }).join('')}</div>`
    : '';

  return `
    <article class="notification-card ${tone}">
      <div class="notification-icon">${notificationIcon(tone, item?.type)}</div>
      <div class="notification-copy">
        <div class="notification-head"><strong>${escapeHtml(item?.title || 'Уведомление')}</strong><span>${escapeHtml(formatDate(item?.created_at))}</span></div>
        ${message ? `<p>${escapeHtml(message)}</p>` : ''}
        ${actionHtml}
      </div>
    </article>
  `;
}

function bindHistoryTabs(){
  const tabs = document.querySelectorAll('[data-v91-history-tab]');
  const panels = document.querySelectorAll('[data-v91-history-panel]');
  tabs.forEach(tab => tab.addEventListener('click', () => {
    const target = tab.dataset.v91HistoryTab;
    tabs.forEach(item => item.classList.toggle('active', item === tab));
    panels.forEach(panel => panel.classList.toggle('active', panel.dataset.v91HistoryPanel === target));
  }));
}

function renderTopupHistoryItem(item){
  const room = item.room === 'match' ? 'Match' : 'Gold';
  const status = topupStatusText(item.status);
  const tone = item.status === 'paid' ? 'pos' : (['rejected', 'cancelled'].includes(item.status) ? 'neg' : '');
  const amount = item.status === 'paid'
    ? `+${Number(item.coins || 0).toLocaleString('ru-RU')} коинов`
    : (['rejected', 'cancelled'].includes(item.status) ? '0 коинов' : 'ожидает');
  return `
    <div class="history-item">
      <div>
        <strong>${escapeHtml(status)}</strong>
        <span>${escapeHtml(room)} · ${Number(item.price || item.amount_rub || 0).toLocaleString('ru-RU')} ₽ → ${Number(item.coins || 0).toLocaleString('ru-RU')} коинов</span>
        ${item.status === 'rejected' && item.reject_reason ? `<span>Причина: ${escapeHtml(item.reject_reason)}</span>` : ''}
        <em>#${escapeHtml(item.short_id || '')} · ${escapeHtml(formatDate(item.created_at))}</em>
      </div>
      <b class="${tone}">${escapeHtml(amount)}</b>
    </div>
  `;
}

function refreshHistoryNetwork(force = false){
  const now = Date.now();
  if (historyRefreshPromise || (!force && now - lastHistoryRefreshAt < REFRESH_GAP_MS)) return historyRefreshPromise;
  lastHistoryRefreshAt = now;

  historyRefreshPromise = requestApi('history')
    .then(data => {
      historyCache = { data, storedAt:Date.now() };
      const title = sheetTitle();
      if (title === 'История баланса') renderBalanceHistorySheet(data.history || {}, data.topups || []);
      if (title === 'История матчей') renderMatchHistorySheet(data.history?.matches || []);
      return data;
    })
    .catch(() => null)
    .finally(() => { historyRefreshPromise = null; });

  return historyRefreshPromise;
}

function refreshNotificationsNetwork(markRead){
  const now = Date.now();
  if (notificationsRefreshPromise || (!markRead && now - lastNotificationsRefreshAt < REFRESH_GAP_MS)) return notificationsRefreshPromise;
  lastNotificationsRefreshAt = now;

  notificationsRefreshPromise = requestUrl(APP_CONFIG.notificationsBase, { markRead })
    .then(data => {
      notificationsCache = { data, storedAt:Date.now() };
      if (sheetTitle() === 'Уведомления') renderNotificationsSheet(data.items || []);
      if (markRead) setUnreadCount(0);
      return data;
    })
    .catch(() => null)
    .finally(() => { notificationsRefreshPromise = null; });

  return notificationsRefreshPromise;
}

function rememberResponse(meta, response){
  if (!meta || !response.ok) return;
  response.clone().json().then(data => {
    if (meta.kind === 'history') historyCache = { data, storedAt:Date.now() };
    if (meta.kind === 'notifications') notificationsCache = { data, storedAt:Date.now() };
  }).catch(() => null);
}

function requestMeta(input, init){
  const method = String(init?.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
  if (method !== 'POST') return null;

  let url;
  try {
    url = new URL(typeof input === 'string' ? input : input.url, window.location.href);
  } catch (error) {
    return null;
  }

  const payload = parsePayload(init?.body);
  if (url.pathname.endsWith('/bot/api.php') && String(payload.action || '') === 'history') return { kind:'history' };
  if (url.pathname.endsWith('/bot/notifications.php')) return { kind:'notifications' };
  return null;
}

function requestApi(action, payload = {}){
  return requestUrl(APP_CONFIG.apiBase, { action, ...payload });
}

async function inviteRequest(action, payload = {}){
  return requestUrl(INVITES_URL, { action, ...payload });
}

async function requestUrl(url, payload = {}){
  const response = await networkFetch(url, {
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
    if (response.status === 429) throw new Error('Связь перегружена. Попробуйте ещё раз через несколько секунд.');
    throw new Error(data?.error || `Ошибка API: ${response.status}`);
  }
  return data;
}

function sharePreparedMessage(tg, preparedId){
  return new Promise(resolve => {
    let settled = false;
    const finish = value => {
      if (settled) return;
      settled = true;
      window.clearTimeout(timeout);
      resolve(value);
    };
    const timeout = window.setTimeout(() => finish(null), SHARE_CALLBACK_TIMEOUT_MS);
    try {
      tg.shareMessage(preparedId, result => finish(Boolean(result)));
    } catch (error) {
      finish(null);
    }
  });
}

function syncInviteState(result){
  if (result?.user) {
    state.user = result.user;
    renderBalances(state.user);
  }
  if (result?.session) state.session = result.session;
}

function openFallbackShare(shareUrl, shareText){
  if (!shareUrl) return toast('Ссылка временно недоступна.');
  const text = String(shareText || '').replace(shareUrl, '').trim();
  const url = `https://t.me/share/url?url=${encodeURIComponent(shareUrl)}&text=${encodeURIComponent(text)}`;
  const tg = getTelegram();
  try {
    if (tg?.openTelegramLink) tg.openTelegramLink(url);
    else window.open(url, '_blank', 'noopener,noreferrer');
  } catch (error) {
    window.open(url, '_blank', 'noopener,noreferrer');
  }
}

async function copyInviteLink(url){
  if (!url) return toast('Ссылка временно недоступна.');
  try {
    await navigator.clipboard.writeText(url);
    toast('Ссылка скопирована.');
  } catch (error) {
    window.prompt('Скопируйте ссылку:', url);
  }
}

function inviteSummary(invite){
  const gameType = String(invite?.game_type || 'tictactoe');
  const size = Number(invite?.board_size || 0);
  const boardLabel = gameType === 'domino' ? 'Классика 0–6' : `${size}×${size}`;
  return `
    <div class="topup-success">
      <div><span>Игра</span><strong>${escapeHtml(invite?.game_title || 'Игра')}</strong></div>
      <div><span>Комната</span><strong>${escapeHtml(invite?.room_label || (invite?.room === 'gold' ? 'Gold-комната' : 'Матч-комната'))}</strong></div>
      <div><span>Вариант</span><strong>${escapeHtml(boardLabel)}</strong></div>
      <div><span>Ставка</span><strong>${Number(invite?.bet || 0)} коинов</strong></div>
    </div>
  `;
}

function notificationMessage(item){
  let message = String(item?.message || '').trim();
  const patterns = [
    /\s*Баланс уже обновлён\.?/giu,
    /\s*Баланс не изменён\.?/giu,
    /\s*Баланс:\s*-?[\d\s]+\s*→\s*-?[\d\s]+\.?/giu,
    /\s*Статус (?:уже )?обновлён[^.]*\.?/giu,
    /\s*Откройте Mini App[^.]*\.?/giu,
  ];
  patterns.forEach(pattern => { message = message.replace(pattern, ' '); });
  return message.replace(/\s+/g, ' ').replace(/\s+([.,!?])/g, '$1').trim();
}

function notificationIcon(tone, type = ''){
  if (String(type).startsWith('invite_')) return '🎮';
  if (tone === 'success') return '✓';
  if (tone === 'danger' || tone === 'warning') return '!';
  return 'i';
}

function actionLabel(action){
  return {
    accept:'Принять приглашение',
    decline:'Отклонить',
    start:'Начать игру',
    cancel:'Отменить',
  }[String(action || '')] || 'Открыть';
}

function topupStatusText(status){
  if (status === 'paid') return 'Пополнение начислено';
  if (status === 'rejected') return 'Заявка отклонена';
  if (status === 'cancelled') return 'Заявка отменена';
  if (status === 'pending') return 'Ожидает оплаты';
  return 'Заявка на пополнение';
}

function setUnreadCount(count){
  const button = document.getElementById('notificationsOpen');
  if (!button) return;
  const safe = Math.max(0, Math.trunc(Number(count || 0)));
  button.dataset.unread = safe > 99 ? '99+' : String(safe);
  button.classList.toggle('has-unread', safe > 0);
  button.setAttribute('aria-label', safe > 0 ? `Уведомления: ${safe} новых` : 'Уведомления');
}

function symbolForUser(game, userId){
  const player = Array.isArray(game.players)
    ? game.players.find(item => String(item?.id || '') === userId)
    : null;
  return String(player?.symbol || '');
}

function gameKey(gameId){
  return String(gameId || 'search');
}

function generationFor(key){
  return Number(gameGenerationByKey.get(key) || 0);
}

function bumpGeneration(key){
  gameGenerationByKey.set(key, generationFor(key) + 1);
}

function openInviteToken(){
  return String(document.querySelector('#sheet [data-invite-sheet][data-invite-token]')?.dataset.inviteToken || '');
}

function sheetTitle(){
  return String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').trim();
}

function formatDate(value){
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleString('ru-RU', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' });
}

function parsePayload(body){
  if (typeof body !== 'string' || body === '') return {};
  try {
    const parsed = JSON.parse(body);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch (error) {
    return {};
  }
}

function clone(value){
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
