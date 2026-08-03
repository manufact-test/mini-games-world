import { state } from './state.js?v=27';
import { api } from './api/client.js?v=47';
import { openSheet } from './components/sheet.js?v=68';
import { getInitData } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';

const OPPONENTS_URL = `${window.location.origin}/bot/invite-opponents.php`;
const OPPONENT_REFRESH_GAP_MS = 3000;
const SNAPSHOT_REFRESH_GAP_MS = 1200;

let initialized = false;
let networkFetch = null;
let historySnapshot = null;
let opponentsCache = null;
let historyRefreshPromise = null;
let opponentsRefreshPromise = null;
let lastHistoryRefreshAt = 0;
let lastOpponentsRefreshAt = 0;

export function initFirstInteractionReadinessEarly(){
  if (initialized) return;
  initialized = true;

  installOpponentResponseCache();
  document.addEventListener('click', handleEarlyClick, true);

  document.addEventListener('mgw:game-dismissed', () => {
    refreshHistorySnapshot(true);
    warmProfileSnapshot().catch(() => null);
    refreshOpponentsNetwork(true);
  });

  document.addEventListener('mgw:history-refresh', () => {
    refreshHistorySnapshot(true);
  });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;
    refreshHistorySnapshot(false);
    refreshOpponentsNetwork(false);
  });
}

export async function warmFirstInteractionData(){
  const tasks = [
    warmProfileSnapshot(),
    warmHistorySnapshot(),
    warmNotificationsSnapshot(),
    warmShopOrders(),
    refreshOpponentsNetwork(true),
  ];

  const results = await Promise.allSettled(tasks);

  return {
    profileReady:results[0].status === 'fulfilled',
    historyReady:results[1].status === 'fulfilled',
    notificationsReady:results[2].status === 'fulfilled',
    ordersReady:results[3].status === 'fulfilled',
    opponentsReady:results[4].status === 'fulfilled',
  };
}

async function warmProfileSnapshot(){
  const result = await api.profile();
  if (result?.user) state.user = mergeUserState(state.user, result.user);
  if (result?.stats && typeof result.stats === 'object') state.profileStats = result.stats;
  if (result?.session) state.session = result.session;
  return result;
}

async function warmHistorySnapshot(){
  const result = await api.history();
  historySnapshot = result;
  return result;
}

async function warmNotificationsSnapshot(){
  // Notifications are warmed for readiness only. The canonical notifications
  // screen is the sole owner of bell clicks, mark-read requests and sheet HTML.
  return api.notifications(false);
}

async function warmShopOrders(){
  const result = await api.shopOrders();
  state.profileOrders = Array.isArray(result?.orders) ? result.orders : [];
  return result;
}

function installOpponentResponseCache(){
  networkFetch = window.fetch.bind(window);

  window.fetch = async function firstInteractionFetch(input, init = {}){
    if (isOpponentsRequest(input, init) && opponentsCache?.data) {
      refreshOpponentsNetwork(false);
      return jsonResponse(opponentsCache.data);
    }

    const response = await networkFetch(input, init);
    if (isOpponentsRequest(input, init)) rememberOpponentsResponse(response);
    return response;
  };
}

function handleEarlyClick(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const target = origin.closest('button, [role="button"]');
  if (!target) return;

  if (target.id === 'balanceHistoryBtn' && historySnapshot) {
    event.preventDefault();
    event.stopImmediatePropagation();
    renderBalanceHistorySheet(
      historySnapshot.history || {},
      historySnapshot.topups || []
    );
    refreshHistorySnapshot(false);
    return;
  }

  if (target.id === 'matchHistoryBtn' && historySnapshot) {
    event.preventDefault();
    event.stopImmediatePropagation();
    renderMatchHistorySheet(historySnapshot.history?.matches || []);
    refreshHistorySnapshot(false);
    return;
  }

  if (target.matches('[data-invite-friend], [data-open-player-picker]')) {
    refreshOpponentsNetwork(false);
  }
}

function refreshHistorySnapshot(force = false){
  const now = Date.now();
  if (historyRefreshPromise) return historyRefreshPromise;
  if (!force && now - lastHistoryRefreshAt < SNAPSHOT_REFRESH_GAP_MS) {
    return Promise.resolve(historySnapshot);
  }

  lastHistoryRefreshAt = now;
  historyRefreshPromise = api.history()
    .then(result => {
      historySnapshot = result;
      const title = sheetTitle();
      if (title === 'История баланса') {
        renderBalanceHistorySheet(result.history || {}, result.topups || []);
      }
      if (title === 'История матчей') {
        renderMatchHistorySheet(result.history?.matches || []);
      }
      return result;
    })
    .catch(() => historySnapshot)
    .finally(() => { historyRefreshPromise = null; });

  return historyRefreshPromise;
}

function refreshOpponentsNetwork(force = false){
  const now = Date.now();
  if (opponentsRefreshPromise) return opponentsRefreshPromise;
  if (!force && now - lastOpponentsRefreshAt < OPPONENT_REFRESH_GAP_MS) {
    return Promise.resolve(opponentsCache?.data || { ok:true, items:[] });
  }

  lastOpponentsRefreshAt = now;
  opponentsRefreshPromise = requestUrl(OPPONENTS_URL, {})
    .then(data => {
      opponentsCache = { data, storedAt:Date.now() };
      return data;
    })
    .finally(() => { opponentsRefreshPromise = null; });

  return opponentsRefreshPromise;
}

function rememberOpponentsResponse(response){
  if (!response?.ok) return;
  response.clone().json().then(data => {
    opponentsCache = { data, storedAt:Date.now() };
  }).catch(() => null);
}

function isOpponentsRequest(input, init){
  const method = String(init?.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
  if (method !== 'POST') return false;

  try {
    const url = new URL(typeof input === 'string' ? input : input.url, window.location.href);
    return url.pathname.endsWith('/bot/invite-opponents.php');
  } catch (error) {
    return false;
  }
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
      <button class="history-tab active" data-v92-history-tab="operations" type="button">Операции</button>
      <button class="history-tab" data-v92-history-tab="topups" type="button">Пополнения</button>
    </div>
    <div class="history-scroll">
      <div class="history-tab-panel active" data-v92-history-panel="operations"><div class="history-section"><h3>Операции баланса</h3><div class="history-list">${operationHtml}</div></div></div>
      <div class="history-tab-panel" data-v92-history-panel="topups"><div class="history-section"><h3>Пополнения</h3><div class="history-list">${topupHtml}</div></div></div>
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

function bindHistoryTabs(){
  const tabs = document.querySelectorAll('[data-v92-history-tab]');
  const panels = document.querySelectorAll('[data-v92-history-panel]');
  tabs.forEach(tab => tab.addEventListener('click', () => {
    const target = tab.dataset.v92HistoryTab;
    tabs.forEach(item => item.classList.toggle('active', item === tab));
    panels.forEach(panel => panel.classList.toggle('active', panel.dataset.v92HistoryPanel === target));
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

function mergeUserState(currentUser, incomingUser){
  const current = currentUser && typeof currentUser === 'object' ? currentUser : {};
  const incoming = incomingUser && typeof incomingUser === 'object' ? incomingUser : {};
  const merged = { ...current, ...incoming };

  const incomingPhoto = String(incoming.photo_url || '').trim();
  const currentPhoto = String(current.photo_url || '').trim();
  if (!incomingPhoto && currentPhoto) merged.photo_url = currentPhoto;

  return merged;
}

function jsonResponse(data){
  return new Response(JSON.stringify(data), {
    status:200,
    headers:{ 'Content-Type':'application/json; charset=utf-8' },
  });
}

function topupStatusText(status){
  if (status === 'paid') return 'Пополнение начислено';
  if (status === 'rejected') return 'Заявка отклонена';
  if (status === 'cancelled') return 'Заявка отменена';
  if (status === 'pending') return 'Ожидает оплаты';
  return 'Заявка на пополнение';
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

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
