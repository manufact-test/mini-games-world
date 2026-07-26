import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { api } from './api/client.js?v=47';
import { openSheet, closeSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { showScreen } from './router.js?v=27';
import { getTelegram, getInitData, haptic } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { renderBalances, roomName } from './ui.js?v=89';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const HISTORY_REFRESH_GAP_MS = 1500;
const NOTIFICATIONS_REFRESH_GAP_MS = 1500;
const SHARE_CALLBACK_TIMEOUT_MS = 90000;

let installed = false;
let baseFetch = null;
let historyCache = null;
let notificationsCache = null;
let historyRefreshPromise = null;
let notificationsRefreshPromise = null;
let lastHistoryRefreshAt = 0;
let lastNotificationsRefreshAt = 0;

let gameActionBusy = false;
let gameGeneration = 0;
let gameStateInFlight = null;
let gameActionPromise = null;
let latestGameStateResult = null;
let baseGameState = null;
let baseGameAction = null;

let linkInviteBusy = false;

export function initInteractionLatencyCoordinator(){
  if (installed) return;
  installed = true;

  APP_CONFIG.searchIntervalMs = 800;
  APP_CONFIG.gameIntervalMs = 450;

  installZeroTransitionStyle();
  installResponseCache();
  installSerializedGameState();
  installImmediateNavigation();
  installOptimisticTicTacToe();

  document.addEventListener('mgw:app-ready', () => {
    prefetchHistory();
    prefetchNotifications();
  }, { once:true });

  document.addEventListener('mgw:game-dismissed', prefetchHistory);
  document.addEventListener('mgw:history-refresh', prefetchHistory);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;
    prefetchHistory();
    prefetchNotifications();
  });
}

function installZeroTransitionStyle(){
  const style = document.createElement('style');
  style.id = 'mgw-zero-latency-transitions';
  style.textContent = `
    #sheetOverlay,
    #sheetOverlay #sheet {
      transition:none !important;
      animation:none !important;
    }
  `;
  document.head.appendChild(style);
}

function installResponseCache(){
  baseFetch = window.fetch.bind(window);

  window.fetch = async function interactionFetch(input, init = {}){
    const meta = requestMeta(input, init);

    if (meta?.kind === 'history' && historyCache?.data) {
      refreshCacheInBackground(input, init, 'history');
      return historyResponseFromCache(historyCache);
    }

    if (meta?.kind === 'notifications' && meta.markRead && notificationsCache?.data) {
      refreshCacheInBackground(input, init, 'notifications');
      const cached = structuredCloneSafe(notificationsCache.data);
      cached.unread_count = 0;
      return jsonResponse(cached);
    }

    const response = await baseFetch(input, init);
    rememberResponse(meta, response);
    return response;
  };
}

function installSerializedGameState(){
  baseGameState = api.gameState.bind(api);
  baseGameAction = api.gameAction.bind(api);

  api.gameState = function serializedGameState(gameId = null){
    const key = String(gameId || 'search');
    const startedGeneration = gameGeneration;

    if (gameActionPromise) {
      return gameActionPromise
        .catch(() => null)
        .then(() => latestGameStateResult || baseGameState(gameId));
    }

    if (gameStateInFlight?.key === key) return gameStateInFlight.promise;

    const promise = baseGameState(gameId)
      .then(async result => {
        if (startedGeneration !== gameGeneration) {
          if (gameActionPromise) await gameActionPromise.catch(() => null);
          return latestGameStateResult || result;
        }
        latestGameStateResult = result;
        return result;
      })
      .finally(() => {
        if (gameStateInFlight?.promise === promise) gameStateInFlight = null;
      });

    gameStateInFlight = { key, promise };
    return promise;
  };
}

function installImmediateNavigation(){
  document.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const target = origin.closest('button, [role="button"]');
    if (!target) return;

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

    if (target.matches('[data-latency-fallback-share]')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      openFallbackShare(target.dataset.shareUrl || '', target.dataset.shareText || '');
      return;
    }

    if (target.matches('[data-latency-copy-link]')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      copyInviteLink(target.dataset.shareUrl || '');
      return;
    }

    if (target.id === 'startSearchBtn') {
      const info = document.getElementById('searchInfo');
      if (info) {
        info.textContent = `${roomName(state.room)} · участие ${Number(state.selectedBet || APP_CONFIG.matchBet)} коинов`;
      }
      closeSheet();
      showScreen('search');
      return;
    }

    if (target.id === 'cancelSearch' || target.id === 'changeSearch') {
      clearSearchTimers();
      showScreen('home');
    }
  }, true);
}

function installOptimisticTicTacToe(){
  document.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const button = origin.closest('[data-game-cell]');
    if (!button) return;

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
  }, true);
}

async function submitOptimisticTicTacToe(game, cell, symbol, button){
  gameActionBusy = true;
  gameGeneration += 1;
  haptic('light');

  const boardElement = document.getElementById('gameBoard');
  const turnElement = document.getElementById('turnText');
  const previousHtml = boardElement?.innerHTML || '';
  const previousClass = boardElement?.className || '';
  const previousTurnText = turnElement?.textContent || '';
  const previousGame = structuredCloneSafe(state.activeGame);

  if (boardElement) {
    boardElement.querySelectorAll('[data-game-cell]').forEach(item => {
      item.disabled = true;
      item.classList.add('locked');
    });
  }

  button.textContent = symbol === 'X' ? '✕' : '○';
  button.classList.remove('locked');
  button.classList.add(symbol === 'X' ? 'x' : 'o', 'is-optimistic');
  if (turnElement) turnElement.textContent = 'Ход соперника';

  gameActionPromise = baseGameAction(game.id, { type:'cell', cell });

  try {
    const result = await gameActionPromise;
    latestGameStateResult = result;

    if (result.user) {
      state.user = result.user;
      state.session = result.session || state.session;
      renderBalances(state.user);
    }

    if (result.game) {
      state.activeGame = result.game;
      renderAuthoritativeTicTacToe(result.game, result.me || { id:state.user?.id });
    }

    prefetchHistory();
  } catch (error) {
    latestGameStateResult = {
      game:previousGame,
      user:state.user,
      me:{ id:state.user?.id },
    };
    state.activeGame = previousGame;

    if (boardElement) {
      boardElement.className = previousClass;
      boardElement.innerHTML = previousHtml;
    }
    if (turnElement) turnElement.textContent = previousTurnText;
    toast(error.message || 'Не удалось выполнить ход.');
  } finally {
    gameActionPromise = null;
    gameActionBusy = false;
    gameGeneration += 1;
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
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = 'Открываем Telegram…';
  haptic('light');

  try {
    const result = await invitePost('create_link_draft', context);
    syncInviteUser(result);

    const invite = result.invite || null;
    const token = String(invite?.token || '');
    if (!token) throw new Error('Не удалось подготовить ссылку.');

    const tg = getTelegram();
    const preparedId = String(invite?.prepared_message_id || '');

    if (preparedId && typeof tg?.shareMessage === 'function') {
      const sent = await sharePreparedMessage(tg, preparedId);

      if (sent === true) {
        const optimisticInvite = { ...invite, status:'pending', is_owner:true };
        showImmediateInviteWaiting(optimisticInvite, 'Приглашение отправлено.');

        invitePost('confirm_shared', { token })
          .then(confirmed => {
            syncInviteUser(confirmed);
            const finalInvite = confirmed.invite || optimisticInvite;
            if (openInviteToken() === token) {
              showImmediateInviteWaiting(finalInvite, 'Приглашение отправлено. Ждём ответа игрока.');
            }
            document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
          })
          .catch(async error => {
            await invitePost('discard_draft', { token }).catch(() => null);
            if (openInviteToken() === token) closeSheet();
            toast(error.message || 'Telegram отправил сообщение, но подтверждение приглашения не завершилось.');
          });
        return;
      }

      button.disabled = false;
      button.textContent = originalText;
      toast(sent === false ? 'Отправка отменена.' : 'Telegram не подтвердил отправку.');
      invitePost('discard_draft', { token }).catch(() => null);
      return;
    }

    showImmediatePreparedLink(invite);
  } catch (error) {
    button.disabled = false;
    button.textContent = originalText;
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

  invitePost(action, { token })
    .then(result => {
      syncInviteUser(result);
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

  invitePost('discard_draft', { token }).catch(error => {
    toast(error.message || 'Не удалось удалить черновик приглашения.');
  });
}

function showImmediateInviteWaiting(invite, message){
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

function showImmediatePreparedLink(invite){
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
      <button class="btn primary full" data-latency-fallback-share data-share-url="${escapeHtml(shareUrl)}" data-share-text="${escapeHtml(shareText)}" type="button">Открыть список Telegram</button>
      <button class="btn ghost full" data-latency-copy-link data-share-url="${escapeHtml(shareUrl)}" type="button">Скопировать ссылку</button>
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

  const size = Number(document.querySelector('#sheet [data-invite-size].active')?.dataset.inviteSize || 3);
  const bet = Number(document.querySelector('#sheet [data-invite-bet].active')?.dataset.inviteBet || APP_CONFIG.matchBet);

  return {
    gameType,
    room:state.room === 'gold' ? 'gold' : 'match',
    boardSize:size,
    bet,
  };
}

async function invitePost(action, payload = {}){
  const response = await baseFetch(INVITES_URL, {
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
    if (response.status === 429) throw new Error('Связь перегружена. Попробуйте ещё раз через несколько секунд.');
    throw new Error(data?.error || 'Сервис приглашений временно недоступен.');
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

function syncInviteUser(result){
  if (result?.user) {
    state.user = result.user;
    renderBalances(state.user);
  }
  if (result?.session) state.session = result.session;
}

function openInviteToken(){
  return String(document.querySelector('#sheet [data-invite-sheet][data-invite-token]')?.dataset.inviteToken || '');
}

function inviteSummary(invite){
  const gameTitle = String(invite?.game_title || 'Игра');
  const roomLabel = String(invite?.room_label || (invite?.room === 'gold' ? 'Gold-комната' : 'Матч-комната'));
  const gameType = String(invite?.game_type || 'tictactoe');
  const size = Number(invite?.board_size || 0);
  const boardLabel = gameType === 'domino' ? 'Классика 0–6' : `${size}×${size}`;

  return `
    <div class="topup-success">
      <div><span>Игра</span><strong>${escapeHtml(gameTitle)}</strong></div>
      <div><span>Комната</span><strong>${escapeHtml(roomLabel)}</strong></div>
      <div><span>Вариант</span><strong>${escapeHtml(boardLabel)}</strong></div>
      <div><span>Ставка</span><strong>${Number(invite?.bet || 0)} коинов</strong></div>
    </div>
  `;
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

function clearSearchTimers(){
  state.timers.search = clearTimer(state.timers.search);
  if (window.__MGW_SEARCH_SCREEN_RUNTIME__?.emptyRoomBotCheckTimer !== null) {
    window.clearTimeout(window.__MGW_SEARCH_SCREEN_RUNTIME__.emptyRoomBotCheckTimer);
    window.__MGW_SEARCH_SCREEN_RUNTIME__.emptyRoomBotCheckTimer = null;
  }
}

function prefetchHistory(){
  window.setTimeout(() => {
    api.history().catch(() => null);
  }, 0);
}

function prefetchNotifications(){
  window.setTimeout(() => {
    api.notifications(false).catch(() => null);
  }, 0);
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
  if (url.pathname.endsWith('/bot/api.php') && String(payload.action || '') === 'history') {
    return { kind:'history', markRead:false };
  }
  if (url.pathname.endsWith('/bot/notifications.php')) {
    return { kind:'notifications', markRead:Boolean(payload.markRead) };
  }
  return null;
}

function rememberResponse(meta, response){
  if (!meta || !response.ok) return;
  response.clone().json().then(data => {
    const value = { data, storedAt:Date.now() };
    if (meta.kind === 'history') {
      historyCache = value;
      document.dispatchEvent(new CustomEvent('mgw:history-cache-updated', { detail:{ data } }));
    }
    if (meta.kind === 'notifications') {
      notificationsCache = value;
      document.dispatchEvent(new CustomEvent('mgw:notifications-cache-updated', { detail:{ data } }));
    }
  }).catch(() => null);
}

function refreshCacheInBackground(input, init, kind){
  const now = Date.now();
  if (kind === 'history') {
    if (historyRefreshPromise || now - lastHistoryRefreshAt < HISTORY_REFRESH_GAP_MS) return;
    lastHistoryRefreshAt = now;
    historyRefreshPromise = baseFetch(input, init)
      .then(response => rememberResponse({ kind, markRead:false }, response))
      .catch(() => null)
      .finally(() => { historyRefreshPromise = null; });
    return;
  }

  if (notificationsRefreshPromise || now - lastNotificationsRefreshAt < NOTIFICATIONS_REFRESH_GAP_MS) return;
  lastNotificationsRefreshAt = now;
  notificationsRefreshPromise = baseFetch(input, init)
    .then(response => rememberResponse({ kind, markRead:false }, response))
    .catch(() => null)
    .finally(() => { notificationsRefreshPromise = null; });
}

function historyResponseFromCache(cache){
  const cached = structuredCloneSafe(cache.data);
  if (cached && typeof cached === 'object') delete cached.user;
  return jsonResponse(cached);
}

function symbolForUser(game, userId){
  const player = Array.isArray(game.players)
    ? game.players.find(item => String(item?.id || '') === userId)
    : null;
  return String(player?.symbol || '');
}

function structuredCloneSafe(value){
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}

function jsonResponse(data){
  return new Response(JSON.stringify(data), {
    status:200,
    headers:{ 'Content-Type':'application/json; charset=utf-8' },
  });
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

function clearTimer(timer){
  if (timer) window.clearInterval(timer);
  return null;
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
