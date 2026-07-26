import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { api } from './api/client.js?v=47';
import { closeSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { getTelegram, getInitData, haptic } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const OPPONENTS_URL = `${window.location.origin}/bot/invite-opponents.php`;
const OPPONENT_REFRESH_GAP_MS = 3000;
const DRAFT_WARM_DELAY_MS = 60;

let initialized = false;
let networkFetch = null;
let opponentsCache = null;
let opponentsRefreshPromise = null;
let lastOpponentsRefreshAt = 0;
let draftWarmTimer = null;
let draftSerial = Promise.resolve();
let draftGeneration = 0;
let preparedDraft = null;
let shareBusy = false;

export function initFirstInteractionReadinessEarly(){
  if (initialized) return;
  initialized = true;

  installOpponentResponseCache();
  document.addEventListener('click', handleEarlyClick, true);

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;
    refreshOpponentsNetwork(false);
  });

  document.addEventListener('mgw:game-dismissed', () => {
    refreshOpponentsNetwork(true);
  });
}

export async function warmFirstInteractionData(){
  const tasks = [
    warmProfileSnapshot(),
    api.history(),
    api.notifications(false),
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

  if (target.matches('[data-invite-friend]')) {
    refreshOpponentsNetwork(false);
    queueMicrotask(() => scheduleCurrentDraftWarm(0));
    return;
  }

  if (target.matches('[data-invite-size], [data-invite-bet]')) {
    queueMicrotask(() => scheduleCurrentDraftWarm(DRAFT_WARM_DELAY_MS));
    return;
  }

  if (target.matches('[data-open-player-picker]')) {
    refreshOpponentsNetwork(false);
    return;
  }

  if (!target.matches('[data-create-link-invite]')) return;

  event.preventDefault();
  event.stopImmediatePropagation();
  sharePreparedLink(target);
}

function scheduleCurrentDraftWarm(delay = DRAFT_WARM_DELAY_MS){
  window.clearTimeout(draftWarmTimer);
  draftWarmTimer = window.setTimeout(() => {
    const context = readInviteContext();
    if (!context) return;
    ensureDraftForContext(context).catch(() => null);
  }, Math.max(0, delay));
}

async function sharePreparedLink(button){
  if (shareBusy) return;
  const context = readInviteContext();
  if (!context) return;

  shareBusy = true;
  haptic('light');
  button.setAttribute('aria-busy', 'true');

  try {
    const invite = await ensureDraftForContext(context);
    const shareUrl = String(invite?.share_url || '');
    const shareText = String(invite?.share_text || '');
    if (!shareUrl) throw new Error('Ссылка временно недоступна.');

    closeSheet();
    openTelegramShare(shareUrl, shareText);
  } catch (error) {
    toast(error.message || 'Не удалось подготовить приглашение.');
  } finally {
    shareBusy = false;
    button.removeAttribute('aria-busy');
  }
}

function ensureDraftForContext(context){
  const key = contextKey(context);
  if (preparedDraft?.key === key && preparedDraft.invite?.share_url) {
    return Promise.resolve(preparedDraft.invite);
  }
  if (preparedDraft?.key === key && preparedDraft.promise) {
    return preparedDraft.promise;
  }

  const generation = ++draftGeneration;
  const promise = draftSerial
    .catch(() => null)
    .then(async () => {
      const result = await inviteRequest('create_link_draft', {
        ...context,
        prepareMessage:false,
      });
      const invite = result?.invite || null;
      if (!invite?.token || !invite?.share_url) {
        throw new Error('Не удалось подготовить ссылку.');
      }
      if (generation === draftGeneration) {
        preparedDraft = { key, invite, promise:null };
      }
      return invite;
    });

  draftSerial = promise;
  preparedDraft = { key, invite:null, promise };
  return promise;
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

function contextKey(context){
  return [
    String(context.gameType || ''),
    String(context.room || ''),
    Number(context.boardSize || 0),
    Number(context.bet || 0),
  ].join(':');
}

function openTelegramShare(shareUrl, shareText){
  const text = String(shareText || '').replace(shareUrl, '').trim();
  const url = `https://t.me/share/url?url=${encodeURIComponent(shareUrl)}&text=${encodeURIComponent(text)}`;
  const tg = getTelegram();

  try {
    if (typeof tg?.openTelegramLink === 'function') tg.openTelegramLink(url);
    else window.open(url, '_blank', 'noopener,noreferrer');
  } catch (error) {
    window.open(url, '_blank', 'noopener,noreferrer');
  }
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
