import { state } from './state.js?v=27';
import { openSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { getInitData } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { renderBalances } from './ui.js?v=89';

const API_URL = `${window.location.origin}/bot/api.php`;
const CACHE_TTL_MS = 20000;
const RETRY_DELAY_MS = 140;

const runtime = window.__MGW_V102_HISTORY__ ||= {
  initialized:false,
  snapshot:null,
  storedAt:0,
  inFlight:null,
  generation:0,
};

export function initV102HistoryController(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  document.addEventListener('click', interceptHistoryClick, true);
  document.addEventListener('pointerdown', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    if (origin.closest('#moreMenuOpen, #gameMenuOpen')) void prefetchHistory();
    if (origin.closest('#topupContinue, #storeCreateOrder')) invalidateHistory();
  }, true);

  document.addEventListener('mgw:app-ready', () => {
    window.setTimeout(() => void prefetchHistory(), 90);
  }, { once:true });
  document.addEventListener('mgw:game-finished', invalidateHistory);
}

export function invalidateV102History(){
  invalidateHistory();
}

async function interceptHistoryClick(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const balanceButton = origin.closest('#balanceHistoryBtn');
  const matchButton = origin.closest('#matchHistoryBtn');
  const button = balanceButton || matchButton;
  if (!(button instanceof HTMLButtonElement)) return;

  event.preventDefault();
  event.stopImmediatePropagation();

  const kind = balanceButton ? 'balance' : 'matches';
  const previousDisabled = button.disabled;
  button.disabled = true;
  button.setAttribute('aria-busy', 'true');

  try {
    const result = await loadHistory();
    if (result?.user) {
      state.user = result.user;
      renderBalances(state.user);
    }
    if (kind === 'balance') renderBalanceHistory(result);
    else renderMatchHistory(result);
  } catch (error) {
    toast(error?.message || 'Не удалось загрузить историю.');
  } finally {
    if (document.body.contains(button)) {
      button.disabled = previousDisabled;
      button.removeAttribute('aria-busy');
    }
  }
}

function prefetchHistory(){
  return loadHistory().catch(() => null);
}

function invalidateHistory(){
  runtime.generation++;
  runtime.snapshot = null;
  runtime.storedAt = 0;
}

function loadHistory(){
  if (runtime.snapshot && Date.now() - runtime.storedAt <= CACHE_TTL_MS) {
    return Promise.resolve(clone(runtime.snapshot));
  }
  if (runtime.inFlight) return runtime.inFlight.then(clone);

  const generation = runtime.generation;
  runtime.inFlight = requestHistoryWithRetry()
    .then(result => {
      if (generation === runtime.generation) {
        runtime.snapshot = clone(result);
        runtime.storedAt = Date.now();
      }
      return result;
    })
    .finally(() => { runtime.inFlight = null; });
  return runtime.inFlight.then(clone);
}

async function requestHistoryWithRetry(){
  let lastError = null;
  for (let attempt = 0; attempt < 2; attempt++) {
    try {
      const response = await fetch(API_URL, {
        method:'POST',
        headers:{ 'Content-Type':'application/json' },
        body:JSON.stringify({
          initData:getInitData(),
          sessionId:getSessionId(),
          action:'history',
        }),
      });
      const text = await response.text();
      const data = parseJson(text);
      if (response.ok && data && data.ok !== false) return data;

      const fallback = data?.error || (response.ok
        ? 'Сервер вернул пустую историю. Повторяем запрос.'
        : `Ошибка загрузки истории: ${response.status}`);
      lastError = new Error(fallback);
      if (!response.ok || attempt > 0) break;
    } catch (error) {
      lastError = error;
      if (attempt > 0) break;
    }
    await delay(RETRY_DELAY_MS);
  }

  throw new Error(lastError?.message || 'Не удалось загрузить историю.');
}

function renderBalanceHistory(result){
  const history = result?.history || {};
  const operations = Array.isArray(history.operations) ? history.operations : [];
  const topups = Array.isArray(result?.topups) ? result.topups : [];

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

  const topupHtml = topups.length
    ? topups.slice(0, 20).map(item => {
      const room = item.room === 'match' ? 'Match' : 'Gold';
      const price = Number(item.price || item.amount_rub || 0).toLocaleString('ru-RU');
      const coins = Number(item.coins || 0).toLocaleString('ru-RU');
      const reason = item.status === 'rejected' && item.reject_reason
        ? `<span>Причина: ${escapeHtml(item.reject_reason)}</span>`
        : '';
      return `
        <div class="history-item">
          <div>
            <strong>${escapeHtml(topupStatusText(item.status))}</strong>
            <span>${escapeHtml(room)} · ${price} ₽ → ${coins} коинов</span>
            ${reason}
            <em>#${escapeHtml(item.short_id || '')} · ${escapeHtml(formatDate(item.created_at))}</em>
          </div>
          <b class="${topupTone(item.status)}">${escapeHtml(topupAmountLabel(item))}</b>
        </div>
      `;
    }).join('')
    : '<div class="small-note">Заявок на пополнение пока нет.</div>';

  openSheet(`
    <div class="sheet-head">
      <div><h2>История баланса</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="history-tabs" role="tablist">
      <button class="history-tab active" data-history-tab="operations" type="button">Операции</button>
      <button class="history-tab" data-history-tab="topups" type="button">Пополнения</button>
    </div>
    <div class="history-scroll">
      <div class="history-tab-panel active" data-history-panel="operations">
        <div class="history-section"><h3>Операции баланса</h3><div class="history-list">${operationHtml}</div></div>
      </div>
      <div class="history-tab-panel" data-history-panel="topups">
        <div class="history-section"><h3>Пополнения</h3><div class="history-list">${topupHtml}</div></div>
      </div>
    </div>
    <button class="btn ghost full" data-close-sheet type="button">Понятно</button>
  `);
  bindHistoryTabs();
}

function renderMatchHistory(result){
  const matches = Array.isArray(result?.history?.matches) ? result.history.matches : [];
  const matchHtml = matches.length
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
    <div class="sheet-head">
      <div><h2>История матчей</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="history-scroll">
      <div class="history-section"><h3>Последние игры</h3><div class="history-list">${matchHtml}</div></div>
    </div>
    <button class="btn ghost full" data-close-sheet type="button">Понятно</button>
  `);
}

function bindHistoryTabs(){
  const tabs = document.querySelectorAll('[data-history-tab]');
  const panels = document.querySelectorAll('[data-history-panel]');
  tabs.forEach(tab => tab.addEventListener('click', () => {
    const target = tab.dataset.historyTab;
    tabs.forEach(item => item.classList.toggle('active', item === tab));
    panels.forEach(panel => panel.classList.toggle('active', panel.dataset.historyPanel === target));
  }));
}

function topupStatusText(status){
  if (status === 'paid') return 'Пополнение начислено';
  if (status === 'rejected') return 'Заявка отклонена';
  if (status === 'cancelled') return 'Заявка отменена';
  if (status === 'pending') return 'Ожидает оплаты';
  return 'Заявка на пополнение';
}

function topupTone(status){
  if (status === 'paid') return 'pos';
  if (status === 'rejected' || status === 'cancelled') return 'neg';
  return '';
}

function topupAmountLabel(item){
  if (item.status === 'paid') return `+${Number(item.coins || 0).toLocaleString('ru-RU')} коинов`;
  if (item.status === 'rejected' || item.status === 'cancelled') return '0 коинов';
  return 'ожидает';
}

function formatDate(value){
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleString('ru-RU', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' });
}

function parseJson(value){
  const text = String(value || '').replace(/^\uFEFF/, '').trim();
  if (!text) return null;
  try { return JSON.parse(text); } catch (error) { return null; }
}

function delay(ms){
  return new Promise(resolve => window.setTimeout(resolve, ms));
}

function clone(value){
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}

function escapeHtml(value){
  return String(value ?? '').replace(/[&<>'"]/g, character => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;',
  }[character]));
}
