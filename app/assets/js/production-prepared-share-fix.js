import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { openSheet, closeSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { getTelegram, getInitData, haptic } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { renderBalances } from './ui.js?v=89';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const SHARE_CALLBACK_TIMEOUT_MS = 90000;

let installed = false;
let previousFetch = null;
let shareBusy = false;

const preparedDrafts = new Map();
const preparedDraftPromises = new Map();

export function initPreparedShareFix(){
  if (installed) return;
  installed = true;

  installPreparedDraftFetchBridge();
  document.addEventListener('click', handlePreparedShareClick, true);
}

function installPreparedDraftFetchBridge(){
  previousFetch = window.fetch.bind(window);

  window.fetch = async function preparedShareFetch(input, init = {}){
    const meta = preparedDraftRequestMeta(input, init);
    if (!meta) return previousFetch(input, init);

    const deferred = createDeferred();
    if (!preparedDraftPromises.has(meta.key)) {
      preparedDraftPromises.set(meta.key, deferred.promise);
    }

    const forwardedInit = meta.prepareMessage === false
      ? {
          ...init,
          body:JSON.stringify({ ...meta.payload, prepareMessage:true }),
        }
      : init;

    try {
      const response = await previousFetch(input, forwardedInit);
      const data = await response.clone().json().catch(() => null);
      const invite = data?.invite || null;

      if (response.ok && invite?.token && invite?.prepared_message_id) {
        preparedDrafts.set(meta.key, invite);
        deferred.resolve(invite);
      } else {
        deferred.reject(new Error(data?.error || 'Telegram-сообщение не удалось подготовить.'));
      }

      return response;
    } catch (error) {
      deferred.reject(error);
      throw error;
    } finally {
      if (preparedDraftPromises.get(meta.key) === deferred.promise) {
        preparedDraftPromises.delete(meta.key);
      }
    }
  };
}

function handlePreparedShareClick(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const target = origin.closest('button, [role="button"]');
  if (!target) return;

  if (target.matches('[data-create-link-invite]')) {
    event.preventDefault();
    event.stopImmediatePropagation();
    sharePreparedInvite(target);
    return;
  }

  if (target.matches('[data-v93-fallback-share]')) {
    event.preventDefault();
    event.stopImmediatePropagation();
    openFallbackShare(target.dataset.shareUrl || '', target.dataset.shareText || '');
    return;
  }

  if (target.matches('[data-v93-copy-link]')) {
    event.preventDefault();
    event.stopImmediatePropagation();
    copyInviteLink(target.dataset.shareUrl || '');
    return;
  }

  if (!target.matches('[data-v93-discard-draft]')) return;

  event.preventDefault();
  event.stopImmediatePropagation();
  const token = String(target.dataset.inviteToken || '');
  closeSheet();
  if (token) inviteRequest('discard_draft', { token }).catch(() => null);
}

async function sharePreparedInvite(button){
  if (shareBusy) return;
  const context = readInviteContext();
  if (!context) return;

  shareBusy = true;
  button.setAttribute('aria-busy', 'true');
  haptic('light');

  try {
    const invite = await ensurePreparedDraft(context);
    const token = String(invite?.token || '');
    const preparedId = String(invite?.prepared_message_id || '');
    const tg = getTelegram();

    if (preparedId && typeof tg?.shareMessage === 'function') {
      const sent = await sharePreparedMessage(tg, preparedId);

      if (sent === true) {
        preparedDrafts.delete(inviteContextKey(context));
        const optimisticInvite = { ...invite, status:'pending', is_owner:true };
        showInviteWaiting(optimisticInvite, 'Приглашение отправлено.');

        inviteRequest('confirm_shared', { token })
          .then(result => {
            syncInviteState(result);
            const confirmed = result?.invite || optimisticInvite;
            if (openInviteToken() === token) {
              showInviteWaiting(confirmed, 'Приглашение отправлено. Ждём ответа игрока.');
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

      toast(sent === false ? 'Отправка отменена.' : 'Telegram не подтвердил отправку.');
      return;
    }

    showPreparedLinkFallback(invite);
  } catch (error) {
    toast(error.message || 'Не удалось подготовить приглашение.');
  } finally {
    shareBusy = false;
    button.removeAttribute('aria-busy');
  }
}

function ensurePreparedDraft(context){
  const key = inviteContextKey(context);
  const cached = preparedDrafts.get(key);
  if (cached?.token && cached?.prepared_message_id) return Promise.resolve(cached);

  const existing = preparedDraftPromises.get(key);
  if (existing) return existing;

  const promise = inviteRequest('create_link_draft', {
    ...context,
    prepareMessage:true,
  })
    .then(result => {
      syncInviteState(result);
      const invite = result?.invite || null;
      if (!invite?.token || !invite?.prepared_message_id) {
        throw new Error('Telegram-сообщение не удалось подготовить.');
      }
      preparedDrafts.set(key, invite);
      return invite;
    })
    .finally(() => {
      if (preparedDraftPromises.get(key) === promise) preparedDraftPromises.delete(key);
    });

  preparedDraftPromises.set(key, promise);
  return promise;
}

function preparedDraftRequestMeta(input, init){
  const method = String(init?.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
  if (method !== 'POST') return null;

  let url;
  try {
    url = new URL(typeof input === 'string' ? input : input.url, window.location.href);
  } catch (error) {
    return null;
  }
  if (!url.pathname.endsWith('/bot/invites.php')) return null;

  const payload = parsePayload(init?.body);
  if (String(payload.action || '') !== 'create_link_draft') return null;

  const context = {
    gameType:String(payload.gameType || 'tictactoe'),
    room:String(payload.room || 'match'),
    boardSize:Number(payload.boardSize || 3),
    bet:Number(payload.bet || APP_CONFIG.matchBet),
  };

  return {
    key:inviteContextKey(context),
    payload,
    prepareMessage:payload.prepareMessage,
  };
}

function readInviteContext(){
  if (!document.querySelector('#sheet [data-invite-setup]')) return null;

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

function inviteContextKey(context){
  return [
    String(context.gameType || ''),
    String(context.room || ''),
    Number(context.boardSize || 0),
    Number(context.bet || 0),
  ].join(':');
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

function showPreparedLinkFallback(invite){
  const token = String(invite?.token || '');
  const shareUrl = String(invite?.share_url || '');
  const shareText = String(invite?.share_text || '');

  openSheet(`
    <span data-invite-sheet data-invite-token="${escapeHtml(token)}" data-invite-state="draft:owner" hidden></span>
    <div class="sheet-head">
      <div><h2>Ссылка подготовлена</h2><p>На этом устройстве Telegram не поддерживает окно отправки сообщения.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="stack invite-actions">
      <button class="btn primary full" data-v93-fallback-share data-share-url="${escapeHtml(shareUrl)}" data-share-text="${escapeHtml(shareText)}" type="button">Открыть список Telegram</button>
      <button class="btn ghost full" data-v93-copy-link data-share-url="${escapeHtml(shareUrl)}" type="button">Скопировать ссылку</button>
      <button class="btn ghost full" data-v93-discard-draft data-invite-token="${escapeHtml(token)}" type="button">Отменить</button>
    </div>
  `);
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

async function inviteRequest(action, payload = {}){
  const response = await fetch(INVITES_URL, {
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
    throw new Error(data?.error || `Ошибка API: ${response.status}`);
  }
  return data;
}

function syncInviteState(result){
  if (result?.user) {
    state.user = result.user;
    renderBalances(state.user);
  }
  if (result?.session) state.session = result.session;
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

function openInviteToken(){
  return String(document.querySelector('#sheet [data-invite-sheet][data-invite-token]')?.dataset.inviteToken || '');
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

function createDeferred(){
  let resolve;
  let reject;
  const promise = new Promise((res, rej) => {
    resolve = res;
    reject = rej;
  });
  promise.catch(() => null);
  return { promise, resolve, reject };
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
