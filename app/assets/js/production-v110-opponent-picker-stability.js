import { getInitData } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';

const OPPONENTS_PATH = '/bot/invite-opponents.php';
const CACHE_PREFIX = 'mgw_v110_recent_opponents_r12';
const CACHE_TTL_MS = 5 * 60 * 1000;
const PREFETCH_INTERVAL_MS = 12000;
const EMPTY_RETRY_DELAYS_MS = [240, 680];
const MAX_ITEMS = 10;

const runtime = window.__MGW_V110_OPPONENT_PICKER__ ||= {
  initialized:false,
  appReady:false,
  originalFetch:null,
  cache:null,
  cacheLoaded:false,
  refreshPromise:null,
  prefetchTimer:null,
};

export function initV110OpponentPickerStability(){
  if (runtime.initialized) return;
  runtime.initialized = true;
  runtime.originalFetch = window.fetch.bind(window);
  window.fetch = interceptedFetch;

  document.addEventListener('mgw:app-ready', () => {
    runtime.appReady = true;
    hydrateCache();
    schedulePrefetch(0);
  }, { once:true });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && runtime.appReady) schedulePrefetch(0);
    else window.clearTimeout(runtime.prefetchTimer);
  });

  window.addEventListener('pageshow', () => {
    if (document.visibilityState === 'visible' && runtime.appReady) schedulePrefetch(0);
  }, { capture:true });
}

async function interceptedFetch(input, init = {}){
  if (!isOpponentRequest(input)) return runtime.originalFetch(input, init);

  hydrateCache();
  const cached = freshCachedItems();
  if (cached.length && !init?.mgwOpponentRefresh) {
    void refreshFromNetwork(input, { ...init, mgwOpponentRefresh:true }).catch(() => null);
    return jsonResponse({ ok:true, items:cached });
  }

  return fetchWithEmptyRetry(input, init);
}

async function fetchWithEmptyRetry(input, init){
  let response = await runtime.originalFetch(input, stripPrivateOptions(init));
  let payload = await parseResponse(response);
  if (hasItems(payload)) {
    rememberItems(payload.items);
    return response;
  }
  if (!response.ok || payload?.ok === false) return response;

  for (const delayMs of EMPTY_RETRY_DELAYS_MS) {
    await delay(delayMs);
    response = await runtime.originalFetch(input, stripPrivateOptions(init));
    payload = await parseResponse(response);
    if (hasItems(payload)) {
      rememberItems(payload.items);
      return response;
    }
    if (!response.ok || payload?.ok === false) return response;
  }

  return response;
}

async function refreshFromNetwork(input, init){
  if (runtime.refreshPromise) return runtime.refreshPromise;
  runtime.refreshPromise = (async () => {
    const response = await fetchWithEmptyRetry(input, init);
    const payload = await parseResponse(response);
    if (hasItems(payload)) rememberItems(payload.items);
    return response;
  })();
  try {
    return await runtime.refreshPromise;
  } finally {
    runtime.refreshPromise = null;
  }
}

function schedulePrefetch(delayMs = PREFETCH_INTERVAL_MS){
  window.clearTimeout(runtime.prefetchTimer);
  if (!runtime.appReady || document.visibilityState !== 'visible') return;
  runtime.prefetchTimer = window.setTimeout(async () => {
    try {
      await prefetchOpponents();
    } finally {
      schedulePrefetch(PREFETCH_INTERVAL_MS);
    }
  }, Math.max(0, Number(delayMs || 0)));
}

async function prefetchOpponents(){
  if (runtime.refreshPromise || !runtime.appReady || document.visibilityState !== 'visible') return false;
  const body = JSON.stringify({ initData:getInitData(), sessionId:getSessionId() });
  const input = `${window.location.origin}${OPPONENTS_PATH}`;
  const init = {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body,
    priority:'low',
    cache:'no-store',
    mgwPrefetch:true,
    mgwOpponentRefresh:true,
  };
  try {
    await refreshFromNetwork(input, init);
    return true;
  } catch (error) {
    return false;
  }
}

function isOpponentRequest(input){
  try {
    const value = input instanceof Request ? input.url : String(input || '');
    return new URL(value, window.location.origin).pathname === OPPONENTS_PATH;
  } catch (error) {
    return false;
  }
}

function stripPrivateOptions(init = {}){
  const clean = { ...init };
  delete clean.mgwOpponentRefresh;
  return clean;
}

async function parseResponse(response){
  try {
    return await response.clone().json();
  } catch (error) {
    return null;
  }
}

function hasItems(payload){
  return Array.isArray(payload?.items) && payload.items.length > 0;
}

function rememberItems(values){
  const items = normalizeItems(values);
  if (!items.length) return;
  runtime.cache = { savedAt:Date.now(), items };
  try {
    localStorage.setItem(cacheKey(), JSON.stringify(runtime.cache));
  } catch (error) {}
}

function hydrateCache(){
  if (runtime.cacheLoaded) return;
  runtime.cacheLoaded = true;
  try {
    const parsed = JSON.parse(localStorage.getItem(cacheKey()) || 'null');
    if (!parsed || Date.now() - Number(parsed.savedAt || 0) > CACHE_TTL_MS) return;
    const items = normalizeItems(parsed.items);
    if (items.length) runtime.cache = { savedAt:Number(parsed.savedAt || Date.now()), items };
  } catch (error) {}
}

function freshCachedItems(){
  if (!runtime.cache || Date.now() - Number(runtime.cache.savedAt || 0) > CACHE_TTL_MS) return [];
  return normalizeItems(runtime.cache.items);
}

function normalizeItems(values){
  const seen = new Set();
  const result = [];
  for (const value of Array.isArray(values) ? values : []) {
    const id = String(value?.id || '');
    if (!id || seen.has(id)) continue;
    seen.add(id);
    result.push({ ...value, id });
    if (result.length >= MAX_ITEMS) break;
  }
  result.sort((left, right) => {
    const online = Number(Boolean(right?.online)) - Number(Boolean(left?.online));
    if (online !== 0) return online;
    const leftTime = Date.parse(String(left?.last_game_at || '')) || 0;
    const rightTime = Date.parse(String(right?.last_game_at || '')) || 0;
    return rightTime - leftTime;
  });
  return result;
}

function cacheKey(){
  let scope = String(getSessionId() || 'anonymous');
  try {
    const rawUser = new URLSearchParams(getInitData()).get('user');
    const user = rawUser ? JSON.parse(rawUser) : null;
    if (user?.id) scope = String(user.id);
  } catch (error) {}
  return `${CACHE_PREFIX}:${scope}`;
}

function jsonResponse(payload){
  return new Response(JSON.stringify(payload), {
    status:200,
    headers:{ 'Content-Type':'application/json; charset=utf-8' },
  });
}

function delay(ms){
  return new Promise(resolve => window.setTimeout(resolve, Math.max(0, Number(ms || 0))));
}
