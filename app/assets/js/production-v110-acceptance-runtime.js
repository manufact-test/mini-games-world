import { state } from './state.js?v=27';
import { openSheet } from './components/sheet.js?v=68';
import { haptic, getInitData } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { peekV101CachedJson } from './production-v101-speed-runtime.js?v=101';

const NOTIFICATIONS_URL = `${window.location.origin}/bot/notifications.php`;
const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const TICK_MS = 100;
const PENDING_TTL_MS = 5000;

const runtime = window.__MGW_V110_ACCEPTANCE__ ||= {
  initialized:false,
  notifications:new Map(),
  notificationsOpening:false,
  pendingMove:null,
  pendingFrame:0,
  clock:null,
  clockTimer:null,
  timerObserver:null,
};

export function initV110AcceptanceRuntime(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  window.addEventListener('click', ownNotificationOpen, true);
  window.addEventListener('click', guardAndTrackTicTacToe, true);
  window.addEventListener('click', stabilizeSearchSummary, true);

  document.addEventListener('mgw:notification-sync', event => {
    upsertNotification(event.detail?.item || null);
  });
  document.addEventListener('mgw:v101-cache-updated', event => {
    if (String(event.detail?.id || '') === 'notifications') {
      mergeNotifications(event.detail?.data?.items || []);
    }
  });
  document.addEventListener('mgw:app-ready', () => {
    mergeNotifications(peekV101CachedJson('notifications', 30000)?.items || []);
  }, { once:true });

  runtime.clockTimer = window.setInterval(tickGameUi, TICK_MS);
  const timer = document.getElementById('timerText');
  if (timer && typeof MutationObserver === 'function') {
    runtime.timerObserver = new MutationObserver(() => paintClock());
    runtime.timerObserver.observe(timer, { childList:true, characterData:true, subtree:true });
  }

  installSearchSummaryStyle();
}

function ownNotificationOpen(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const toast = origin.closest('#notificationToast');
  const bell = origin.closest('#notificationsOpen');
  if (!toast && !bell) return;

  event.preventDefault();
  event.stopImmediatePropagation();

  if (toast instanceof HTMLElement) seedToastPreview(toast);
  dismissToast(toast);
  void openNotificationsImmediately();
}

function seedToastPreview(toast){
  const title = String(toast.querySelector('.notification-toast-copy strong')?.textContent || 'Уведомление').trim();
  const message = String(toast.querySelector('.notification-toast-copy span')?.textContent || '').trim();
  if (!title && !message) return;
  upsertNotification({
    id:`preview-${Date.now()}`,
    title:title || 'Уведомление',
    message,
    created_at:new Date().toISOString(),
    tone:'info',
    actions:[],
    __preview:true,
  });
}

async function openNotificationsImmediately(){
  if (runtime.notificationsOpening) return;
  runtime.notificationsOpening = true;
  haptic('light');

  mergeNotifications(peekV101CachedJson('notifications', 30000)?.items || []);
  renderNotifications(currentNotifications(), currentNotifications().length === 0);

  try {
    const snapshot = await readNotificationSnapshot();
    replaceNotifications(snapshot.items);
    renderNotifications(currentNotifications(), false);
    document.dispatchEvent(new CustomEvent('mgw:notification-count', { detail:{ unreadCount:0 } }));
    void rawPost(NOTIFICATIONS_URL, { markRead:true }).catch(() => null);
  } catch (error) {
    if (!currentNotifications().length) renderNotificationError();
  } finally {
    runtime.notificationsOpening = false;
  }
}

async function readNotificationSnapshot(){
  const [notifications, invites] = await Promise.all([
    rawPost(NOTIFICATIONS_URL, { markRead:false }),
    rawPost(INVITES_URL, { action:'sync', token:'' }).catch(() => null),
  ]);

  const items = Array.isArray(notifications?.items) ? notifications.items : [];
  const inviteItems = Array.isArray(invites?.invite_events) ? invites.invite_events : [];
  const byId = new Map();
  for (const item of [...items, ...inviteItems]) {
    const id = String(item?.id || '');
    if (id) byId.set(id, item);
  }
  return { items:[...byId.values()] };
}

async function rawPost(url, payload){
  const speed = window.__MGW_V101_SPEED__;
  const fetcher = typeof speed?.rawFetch === 'function' ? speed.rawFetch : window.fetch.bind(window);
  const response = await fetcher(url, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({ initData:getInitData(), sessionId:getSessionId(), ...payload }),
    priority:'high',
    cache:'no-store',
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) {
    throw new Error(data?.error || `Ошибка уведомлений: ${response.status}`);
  }
  return data;
}

function replaceNotifications(items){
  runtime.notifications = new Map();
  mergeNotifications(items);
}

function mergeNotifications(items){
  for (const item of Array.isArray(items) ? items : []) upsertNotification(item);
}

function upsertNotification(item){
  const id = String(item?.id || '');
  if (!id) return;
  const previous = runtime.notifications.get(id) || {};
  const next = { ...previous, ...item };
  // The server is the only owner of actionable invitation state. Never infer
  // buttons from an old notification type or token on the client.
  next.actions = Array.isArray(item?.actions) ? [...item.actions] : [];
  runtime.notifications.set(id, next);
  trimNotifications();
}

function currentNotifications(){
  const items = [...runtime.notifications.values()]
    .sort((a, b) => timestamp(b?.created_at) - timestamp(a?.created_at));
  const authoritative = items.filter(item => !item?.__preview);
  return (authoritative.length ? authoritative : items).slice(0, 30);
}

function trimNotifications(){
  if (runtime.notifications.size <= 80) return;
  const items = [...runtime.notifications.values()]
    .sort((a, b) => timestamp(b?.created_at) - timestamp(a?.created_at))
    .slice(0, 60);
  runtime.notifications = new Map(items.map(item => [String(item.id), item]));
}

function renderNotifications(items, loading){
  const body = items.length
    ? `<div class="notifications-list">${items.map(renderNotification).join('')}</div>`
    : (loading
      ? '<div class="notifications-loading"><div>🔔</div><strong>Обновляем уведомления…</strong></div>'
      : '<div class="notifications-empty"><div>🔔</div><strong>Пока уведомлений нет</strong></div>');

  openSheet(`
    <div class="sheet-head">
      <div><h2>Уведомления</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${body}
  `);
}

function renderNotification(item){
  const tone = ['success','danger','info','warning'].includes(String(item?.tone || '')) ? String(item.tone) : 'info';
  const actions = renderActions(item);
  const message = cleanNotificationMessage(item?.message);
  return `
    <article class="notification-card ${tone}">
      <div class="notification-icon">${notificationIcon(tone, item?.type)}</div>
      <div class="notification-copy">
        <div class="notification-head">
          <strong>${escapeHtml(item?.title || 'Уведомление')}</strong>
          <span>${escapeHtml(formatDate(item?.created_at))}</span>
        </div>
        ${message ? `<p>${escapeHtml(message)}</p>` : ''}
        ${actions}
      </div>
    </article>
  `;
}

function renderActions(item){
  const actions = Array.isArray(item?.actions) ? item.actions : [];
  const token = String(item?.invite_token || '');
  if (!token || !actions.length) return '';
  return `<div class="notification-actions invite-actions">${actions.map(action => {
    const primary = action === 'accept' || action === 'start';
    return `<button class="btn ${primary ? 'primary' : 'ghost'} full" data-invite-action="${escapeHtml(action)}" data-invite-token="${escapeHtml(token)}" type="button">${escapeHtml(actionLabel(action))}</button>`;
  }).join('')}</div>`;
}

function actionLabel(action){
  return {
    accept:'Принять приглашение',
    decline:'Отклонить',
    start:'Начать игру',
    cancel:'Отменить',
  }[String(action || '')] || 'Открыть';
}

function renderNotificationError(){
  openSheet(`
    <div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="notifications-empty error"><div>⚠️</div><strong>Не удалось обновить уведомления</strong><span>Попробуйте ещё раз.</span></div>
  `);
}

function dismissToast(toast){
  const element = toast instanceof HTMLElement ? toast : document.getElementById('notificationToast');
  element?.classList.remove('show', 'dragging');
  if (element instanceof HTMLElement) {
    element.style.transform = '';
    element.style.opacity = '';
  }
}

function stabilizeSearchSummary(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const button = origin.closest('button');
  if (!(button instanceof HTMLButtonElement)) return;
  if (![
    'startSearchBtn','startFourSearchBtn','startBattleshipSearchBtn','startCheckersSearchBtn',
    'startReversiSearchBtn','startChessSearchBtn','startGoSearchBtn','startDominoSearchBtn',
  ].includes(button.id)) return;

  const context = searchContext(button.id);
  const info = document.getElementById('searchInfo');
  if (!info) return;
  info.classList.add('mgw-v110-search-summary');
  info.innerHTML = `<span>${escapeHtml(context.primary)}</span><span>${escapeHtml(context.secondary)}</span>`;
}

function searchContext(buttonId){
  const typeById = {
    startSearchBtn:'tictactoe',
    startFourSearchBtn:'four_in_a_row',
    startBattleshipSearchBtn:'battleship',
    startCheckersSearchBtn:'checkers',
    startReversiSearchBtn:'reversi',
    startChessSearchBtn:'chess',
    startGoSearchBtn:'go',
    startDominoSearchBtn:'domino',
  };
  const type = typeById[buttonId] || 'tictactoe';
  const room = String(state.room || '') === 'gold' ? 'gold' : 'match';
  const bet = room === 'match' ? 10 : Number(state.selectedBet || 10);
  const size = {
    tictactoe:Number(state.selectedBoardSize || 3),
    four_in_a_row:Number(state.selectedFourBoardSize || 7),
    battleship:10,
    checkers:8,
    reversi:Number(state.selectedReversiBoardSize || 8),
    chess:8,
    go:Number(state.selectedGoBoardSize || 9),
    domino:7,
  }[type] || 3;
  const title = {
    tictactoe:'Крестики-нолики', four_in_a_row:'4 в ряд', battleship:'Морской бой',
    checkers:'Шашки', reversi:'Реверси', chess:'Шахматы', go:'Го', domino:'Домино',
  }[type] || 'Игра';
  const roomTitle = room === 'gold' ? 'Gold-комната' : 'Матч-комната';
  return {
    primary:`${title} · ${roomTitle} · участие ${bet} коинов`,
    secondary:type === 'domino' ? 'Классика 0–6' : `Поле ${size}×${size}`,
  };
}

function installSearchSummaryStyle(){
  if (document.getElementById('mgw-v110-search-style')) return;
  const style = document.createElement('style');
  style.id = 'mgw-v110-search-style';
  style.textContent = `
    #searchInfo{min-height:2.9em}
    #searchInfo.mgw-v110-search-summary{display:grid;gap:2px;align-content:start}
    #searchInfo.mgw-v110-search-summary>span{display:block}
  `;
  document.head.appendChild(style);
}

function guardAndTrackTicTacToe(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const button = origin.closest('#gameBoard[data-game-type="tictactoe"] [data-game-cell]');
  if (!(button instanceof HTMLButtonElement)) return;

  const descriptor = validTicTacToeMove(button);
  if (!descriptor) {
    event.preventDefault();
    event.stopImmediatePropagation();
    return;
  }

  runtime.pendingMove = {
    ...descriptor,
    startedAt:Date.now(),
    sawRequest:false,
  };
  queuePendingPaint();
}

function validTicTacToeMove(button){
  const game = state.activeGame;
  const id = String(game?.id || '');
  if (!id || String(game?.game_type || '') !== 'tictactoe' || String(game?.status || '') !== 'active') return null;

  const item = window.__MGW_V100_GAME_RUNTIME__?.games?.get?.(id);
  const authoritative = item?.authoritative || game;
  const viewerId = String(item?.viewer?.id || state.user?.id || '');
  const cell = Number(button.dataset.gameCell);
  const board = String(authoritative?.board || '');
  const player = Array.isArray(authoritative?.players)
    ? authoritative.players.find(entry => String(entry?.id || '') === viewerId)
    : null;
  const symbol = String(player?.symbol || '');

  if (!viewerId || !Number.isInteger(cell) || !['X','O'].includes(symbol)) return null;
  if (item?.running || Number(item?.queue?.length || 0) > 0 || item?.surrenderPending) return null;
  if (String(authoritative?.turn || '') !== viewerId) return null;
  if (cell < 0 || cell >= board.length || board[cell] !== '-') return null;
  return { gameId:id, cell, symbol };
}

function tickGameUi(){
  reconcilePendingMove();
  syncClock();
  paintClock();
}

function reconcilePendingMove(){
  const pending = runtime.pendingMove;
  if (!pending) return;
  if (Date.now() - pending.startedAt > PENDING_TTL_MS || String(state.activeGame?.id || '') !== pending.gameId) {
    clearPendingMove();
    return;
  }

  const item = window.__MGW_V100_GAME_RUNTIME__?.games?.get?.(pending.gameId);
  const authoritative = item?.authoritative || state.activeGame;
  const board = String(authoritative?.board || '');
  if (board[pending.cell] === pending.symbol || String(authoritative?.status || '') === 'finished') {
    clearPendingMove();
    return;
  }

  if (item?.running || Number(item?.queue?.length || 0) > 0) pending.sawRequest = true;
  if (pending.sawRequest && !item?.running && Number(item?.queue?.length || 0) === 0) {
    clearPendingMove();
    return;
  }
  queuePendingPaint();
}

function queuePendingPaint(){
  if (!runtime.pendingMove || runtime.pendingFrame) return;
  runtime.pendingFrame = window.requestAnimationFrame(() => {
    runtime.pendingFrame = 0;
    paintPendingMove();
    if (runtime.pendingMove) queuePendingPaint();
  });
}

function paintPendingMove(){
  const pending = runtime.pendingMove;
  if (!pending) return;
  const cell = document.querySelector(`#gameBoard[data-game-type="tictactoe"] [data-game-cell="${pending.cell}"]`);
  if (!(cell instanceof HTMLButtonElement)) return;
  const label = pending.symbol === 'X' ? '✕' : '○';
  if (cell.textContent !== label) cell.textContent = label;
  cell.classList.toggle('x', pending.symbol === 'X');
  cell.classList.toggle('o', pending.symbol === 'O');
  cell.classList.add('locked', 'mgw-pending-action');
  cell.disabled = true;
}

function clearPendingMove(){
  runtime.pendingMove = null;
  if (runtime.pendingFrame) window.cancelAnimationFrame(runtime.pendingFrame);
  runtime.pendingFrame = 0;
}

function syncClock(){
  const game = state.activeGame;
  if (!game?.id || String(game?.status || '') !== 'active') {
    runtime.clock = null;
    return;
  }

  const timeoutSec = Math.max(1, Number(game.move_timeout_sec || 60));
  const turn = String(game.turn || '');
  const startedAt = String(game.turn_started_at || '');
  const signature = `${String(game.id)}|${turn}|${startedAt}`;
  const serverNowMs = finiteNumber(game.server_now_ms);
  const deadlineMs = finiteNumber(game.turn_deadline_ms);
  const serverRemainingMs = deadlineMs !== null && serverNowMs !== null
    ? Math.max(0, deadlineMs - serverNowMs)
    : Math.max(0, Number(game.time_left ?? timeoutSec) * 1000);

  if (!runtime.clock || runtime.clock.signature !== signature) {
    runtime.clock = {
      signature,
      gameId:String(game.id),
      deadlinePerformance:performance.now() + serverRemainingMs,
      timeoutSec,
      lastPaint:null,
    };
    return;
  }

  const localRemaining = Math.max(0, runtime.clock.deadlinePerformance - performance.now());
  // Never jump upward on a same-turn poll. Correct only when the server is
  // materially ahead of the local clock, which preserves authoritative expiry.
  if (serverRemainingMs + 700 < localRemaining) {
    runtime.clock.deadlinePerformance = performance.now() + serverRemainingMs;
  }
}

function paintClock(){
  const clock = runtime.clock;
  const timer = document.getElementById('timerText');
  if (!timer || !clock || String(state.activeGame?.id || '') !== clock.gameId) return;
  const seconds = Math.max(0, Math.ceil((clock.deadlinePerformance - performance.now()) / 1000));
  const label = `${seconds} сек`;
  if (timer.textContent !== label) timer.textContent = label;
  clock.lastPaint = seconds;
}

function finiteNumber(value){
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

function cleanNotificationMessage(value){
  let message = String(value || '').trim();
  const fragments = [
    /\s*Баланс уже обновлён\.?/giu,
    /\s*Баланс не изменён\.?/giu,
    /\s*Баланс:\s*-?[\d\s]+\s*→\s*-?[\d\s]+\.?/giu,
    /\s*Статус (?:уже )?обновлён[^.]*\.?/giu,
    /\s*Откройте Mini App[^.]*\.?/giu,
  ];
  for (const pattern of fragments) message = message.replace(pattern, ' ');
  return message.replace(/\s+/g, ' ').replace(/\s+([.,!?])/g, '$1').replace(/\.{2,}/g, '.').trim();
}

function notificationIcon(tone, type = ''){
  if (String(type).startsWith('invite_')) return '🎮';
  if (tone === 'success') return '✓';
  if (tone === 'danger' || tone === 'warning') return '!';
  return 'i';
}

function formatDate(value){
  const date = new Date(String(value || ''));
  if (Number.isNaN(date.getTime())) return '';
  return new Intl.DateTimeFormat('ru-RU', {
    day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit',
  }).format(date);
}

function timestamp(value){
  const parsed = Date.parse(String(value || ''));
  return Number.isFinite(parsed) ? parsed : 0;
}

function escapeHtml(value){
  return String(value ?? '').replace(/[&<>'"]/g, character => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;',
  }[character]));
}
