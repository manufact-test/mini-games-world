import { api } from '../api/client.js?v=47';
import { state } from '../state.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=41';

const STORAGE_KEY = 'mgw_android_account_link_v1';
const CONFIRMED_STATUSES = new Set(['confirmed','db_linked','linked']);
const TERMINAL_RESTART_STATUSES = new Set(['cancelled','expired']);
let initialized = false;
let busy = false;
let volatileTelegramUrl = '';

export function accountLinkProfileMarkup(auth, identities){
  if (!isAndroidProvider(auth) || hasTelegramIdentity(identities)) return '';
  return `
    <button class="profile-v2-setting-row profile-v2-setting-button" type="button" data-open-account-link>
      <span>
        <strong>Привязать Telegram-аккаунт</strong>
        <small>Использовать в Android ваш существующий MGW-профиль из Telegram</small>
      </span>
      <b>Подключить</b>
    </button>
    <div class="profile-v2-account-divider"></div>
  `;
}

export function initAccountLinkUi(){
  if (initialized) return;
  initialized = true;

  const resume = () => {
    if (document.visibilityState === 'hidden') return;
    void resumePendingSilently();
  };
  document.addEventListener('visibilitychange', resume);
  globalThis.addEventListener?.('focus', resume, { passive:true });
  globalThis.addEventListener?.('pageshow', resume, { passive:true });
  queueMicrotask(resume);
}

export async function openAccountLinkSheet(){
  if (!isCurrentAndroidProvider()) {
    toast('Привязка Telegram доступна только в Android-приложении.');
    return;
  }
  if (hasTelegramIdentity(state.mgwProfile?.identities)) {
    clearPending();
    toast('Telegram уже привязан к этому MGW-профилю.');
    return;
  }

  renderLoading('Проверяем состояние привязки…');
  const pending = loadPending();
  if (!pending) {
    renderIntro();
    return;
  }

  await refreshPending(pending, { showSheet:true });
}

async function createChallenge(){
  if (busy) return;
  busy = true;
  renderLoading('Создаём безопасную ссылку…');
  try {
    const result = await api.accountLinkCreate();
    const link = result?.link || {};
    if (String(link.status || '') === 'linked') {
      clearPending();
      completeUiAndReload();
      return;
    }

    const challengeId = String(link.challenge_id || '').trim();
    const telegramUrl = String(link.telegram_url || '').trim();
    if (!/^lnk_[a-f0-9]{20}$/.test(challengeId) || !safeTelegramUrl(telegramUrl)) {
      throw new Error('Сервер вернул некорректную ссылку привязки.');
    }

    volatileTelegramUrl = telegramUrl;
    savePending({
      challenge_id:challengeId,
      expires_at:String(link.expires_at || ''),
    });
    renderPendingState({ status:'pending', challenge_id:challengeId }, true);
    openTelegram(telegramUrl);
  } catch (error) {
    renderError(error?.message || 'Не удалось создать ссылку привязки.');
  } finally {
    busy = false;
  }
}

async function refreshPending(pending, options = {}){
  if (busy) return;
  busy = true;
  try {
    const result = await api.accountLinkStatus(pending.challenge_id);
    const link = result?.link || {};
    const status = String(link.status || '');

    if (CONFIRMED_STATUSES.has(status)) {
      await finalizePending(pending.challenge_id, options);
      return;
    }

    if (TERMINAL_RESTART_STATUSES.has(status)) {
      clearPending();
      if (options.showSheet) {
        renderIntro(status === 'expired'
          ? 'Ссылка устарела. Создайте новую попытку.'
          : 'Предыдущая попытка привязки отменена.');
      }
      return;
    }

    if (options.showSheet) renderPendingState(link, false);
  } catch (error) {
    if (['challenge_not_found','challenge_expired'].includes(String(error?.code || ''))) {
      clearPending();
      if (options.showSheet) renderIntro('Попытка привязки больше не активна. Создайте новую.');
      return;
    }
    if (options.showSheet) renderError(error?.message || 'Не удалось проверить привязку.', true);
  } finally {
    busy = false;
  }
}

async function finalizePending(challengeId, options = {}){
  if (options.showSheet) renderLoading('Завершаем привязку профиля…');
  try {
    const result = await api.accountLinkFinalize(challengeId);
    if (String(result?.link?.status || '') !== 'linked') {
      throw new Error('Сервер не подтвердил завершение привязки.');
    }
    clearPending();
    completeUiAndReload();
  } catch (error) {
    if (options.showSheet) {
      renderError(error?.message || 'Не удалось завершить привязку.', true);
    } else {
      toast(error?.message || 'Не удалось завершить привязку Telegram.');
    }
  }
}

async function resumePendingSilently(){
  if (busy || !isCurrentAndroidProvider()) return;
  if (hasTelegramIdentity(state.mgwProfile?.identities)) {
    clearPending();
    return;
  }
  const pending = loadPending();
  if (!pending) return;
  await refreshPending(pending, { showSheet:false });
}

function renderIntro(note = ''){
  volatileTelegramUrl = '';
  openSheet(`
    <div class="sheet-head">
      <div>
        <h2>Привязать Telegram</h2>
        <p>Android будет использовать ваш существующий профиль MGW из Telegram.</p>
      </div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${note ? `<div class="profile-v2-empty">${escapeHtml(note)}</div>` : ''}
    <div class="profile-v2-account-card">
      <div class="profile-v2-setting-row">
        <span><strong>Что сохранится</strong><small>Баланс, покупки, статистика, рейтинг, друзья и турнирный прогресс Telegram-профиля.</small></span>
      </div>
      <div class="profile-v2-account-divider"></div>
      <div class="profile-v2-setting-row">
        <span><strong>Что не переносится</strong><small>Временные 1000 стартовых коинов нового Android-профиля не добавляются к вашему балансу.</small></span>
      </div>
    </div>
    <div class="btn-row">
      <button class="btn primary full" type="button" data-account-link-start>Открыть Telegram и подтвердить</button>
    </div>
  `);
  bindSheetButton('[data-account-link-start]', createChallenge);
}

function renderPendingState(link, freshLink){
  const status = String(link?.status || 'pending');
  const claimed = status === 'claimed';
  const canReopen = safeTelegramUrl(volatileTelegramUrl);
  const lead = claimed
    ? 'Telegram-профиль найден. Нажмите «Подтвердить привязку» в сообщении бота, затем вернитесь сюда.'
    : (freshLink
      ? 'Ссылка готова. Telegram откроется автоматически. Подтвердите привязку у бота и вернитесь в приложение.'
      : 'Эта попытка ещё не подтверждена. Если Telegram не открылся или приложение перезапускалось, создайте новую ссылку.');

  openSheet(`
    <div class="sheet-head">
      <div><h2>Привязка Telegram</h2><p>${escapeHtml(lead)}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="profile-v2-account-card">
      <div class="profile-v2-setting-row">
        <span><strong>Статус</strong><small>${claimed ? 'Ожидаем подтверждение в Telegram' : 'Ожидаем открытие Telegram'}</small></span>
        <b>${claimed ? 'Telegram открыт' : 'Ожидание'}</b>
      </div>
    </div>
    <div class="btn-row">
      ${canReopen ? '<button class="btn ghost full" type="button" data-account-link-open>Открыть Telegram</button>' : ''}
      <button class="btn primary full" type="button" data-account-link-check>Я подтвердил в Telegram</button>
      ${!canReopen && !claimed ? '<button class="btn ghost full" type="button" data-account-link-restart>Создать новую ссылку</button>' : ''}
    </div>
  `);

  bindSheetButton('[data-account-link-open]', () => openTelegram(volatileTelegramUrl));
  bindSheetButton('[data-account-link-check]', async () => {
    const pending = loadPending();
    if (pending) await refreshPending(pending, { showSheet:true });
  });
  bindSheetButton('[data-account-link-restart]', createChallenge);
}

function renderLoading(message){
  openSheet(`
    <div class="sheet-head">
      <div><h2>Привязка Telegram</h2><p>${escapeHtml(message)}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="profile-v2-empty">Подождите несколько секунд…</div>
  `);
}

function renderError(message, keepPending = false){
  if (!keepPending) clearPending();
  openSheet(`
    <div class="sheet-head">
      <div><h2>Не удалось завершить привязку</h2><p>${escapeHtml(message)}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="btn-row">
      <button class="btn primary full" type="button" data-account-link-retry>${keepPending ? 'Проверить ещё раз' : 'Попробовать снова'}</button>
    </div>
  `);
  bindSheetButton('[data-account-link-retry]', async () => {
    const pending = keepPending ? loadPending() : null;
    if (pending) await refreshPending(pending, { showSheet:true });
    else renderIntro();
  });
}

function completeUiAndReload(){
  closeSheet();
  toast('Telegram-аккаунт привязан. Загружаем ваш профиль…');
  globalThis.setTimeout(() => globalThis.location.reload(), 320);
}

function openTelegram(candidate){
  if (!safeTelegramUrl(candidate)) {
    renderError('Ссылка Telegram недействительна.');
    return;
  }
  globalThis.location.assign(candidate);
}

function bindSheetButton(selector, handler){
  document.querySelector(selector)?.addEventListener('click', event => {
    event.preventDefault();
    void handler();
  }, { once:true });
}

function isCurrentAndroidProvider(){
  const provider = state.profileAuth?.provider || state.user?.mgw_identity_provider || '';
  return String(provider).trim().toLowerCase() === 'android_device';
}

function isAndroidProvider(auth){
  return String(auth?.provider || '').trim().toLowerCase() === 'android_device';
}

function hasTelegramIdentity(identities){
  return Array.isArray(identities)
    && identities.some(identity => String(identity?.provider || '').trim().toLowerCase() === 'telegram');
}

function safeTelegramUrl(candidate){
  try {
    const url = new URL(String(candidate || ''));
    return url.protocol === 'https:' && url.hostname === 't.me' && url.username === '' && url.password === '';
  } catch (_) {
    return false;
  }
}

function loadPending(){
  try {
    const parsed = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
    const challengeId = String(parsed?.challenge_id || '').trim();
    if (!/^lnk_[a-f0-9]{20}$/.test(challengeId)) {
      clearPending();
      return null;
    }
    return {
      challenge_id:challengeId,
      expires_at:String(parsed?.expires_at || ''),
    };
  } catch (_) {
    clearPending();
    return null;
  }
}

function savePending(value){
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({
      challenge_id:String(value?.challenge_id || ''),
      expires_at:String(value?.expires_at || ''),
    }));
  } catch (_) {}
}

function clearPending(){
  volatileTelegramUrl = '';
  try { localStorage.removeItem(STORAGE_KEY); } catch (_) {}
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#39;');
}
