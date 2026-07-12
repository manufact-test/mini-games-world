const nativeFetch = window.fetch.bind(window);

const inFlight = new Map();
const nextAllowedAt = new Map();
const DEFAULT_RATE_LIMIT_BACKOFF_MS = 5000;
const GAME_STATE_MIN_GAP_MS = 2200;

let installed = false;
let rateLimitedUntil = 0;

export function initRequestGuard(){
  if (installed) return;
  installed = true;
  window.fetch = guardedFetch;
}

async function guardedFetch(input, init = {}){
  const meta = requestMeta(input, init);
  if (!meta) return nativeFetch(input, init);

  if (meta.kind === 'notifications' && !meta.markRead && !isHomeScreen()) {
    return idleNotificationsResponse();
  }

  if (!meta.singleFlightKey) {
    return executeGuardedRequest(input, init, meta);
  }

  const existing = inFlight.get(meta.singleFlightKey);
  if (existing) {
    const response = await existing;
    return response.clone();
  }

  let task;
  task = executeGuardedRequest(input, init, meta).finally(() => {
    if (inFlight.get(meta.singleFlightKey) === task) {
      inFlight.delete(meta.singleFlightKey);
    }
  });
  inFlight.set(meta.singleFlightKey, task);

  const response = await task;
  return response.clone();
}

async function executeGuardedRequest(input, init, meta){
  if (meta.waitForVisible) {
    await waitForVisibleDocument();
  }

  await waitForRequestWindow(meta);
  markRequestStarted(meta);

  let response = await nativeFetch(input, init);
  if (response.status !== 429) return response;

  registerRateLimit(response);
  if (!meta.safeRetry) return friendlyRateLimitResponse(response);

  await waitUntil(rateLimitedUntil);
  markRequestStarted(meta);
  response = await nativeFetch(input, init);

  if (response.status === 429) {
    registerRateLimit(response);
    return friendlyRateLimitResponse(response);
  }

  return response;
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

  if (url.pathname.endsWith('/bot/api.php')) {
    const action = String(payload.action || '');

    if (action === 'game_state') {
      const gameId = String(payload.gameId || 'current');
      return {
        kind:'game_state',
        singleFlightKey:`game_state:${gameId}`,
        throttleKey:'game_state',
        minGapMs:GAME_STATE_MIN_GAP_MS,
        safeRetry:true,
        waitForVisible:true,
      };
    }

    if (action === 'stats') {
      return {
        kind:'stats',
        singleFlightKey:'stats',
        throttleKey:'stats',
        minGapMs:5000,
        safeRetry:true,
        waitForVisible:true,
      };
    }

    if (action === 'game_action') {
      return {
        kind:'game_action',
        singleFlightKey:`game_action:${String(payload.gameId || 'current')}`,
        throttleKey:'',
        minGapMs:0,
        safeRetry:false,
        waitForVisible:true,
      };
    }

    return null;
  }

  if (url.pathname.endsWith('/bot/notifications.php')) {
    const markRead = Boolean(payload.markRead);
    return {
      kind:'notifications',
      markRead,
      singleFlightKey:markRead ? '' : 'notifications:unread',
      throttleKey:markRead ? '' : 'notifications',
      minGapMs:markRead ? 0 : 10000,
      safeRetry:!markRead,
      waitForVisible:!markRead,
    };
  }

  return null;
}

async function waitForRequestWindow(meta){
  const endpointReadyAt = meta.throttleKey
    ? Number(nextAllowedAt.get(meta.throttleKey) || 0)
    : 0;
  await waitUntil(Math.max(rateLimitedUntil, endpointReadyAt));
}

function markRequestStarted(meta){
  if (!meta.throttleKey || !meta.minGapMs) return;
  nextAllowedAt.set(meta.throttleKey, Date.now() + meta.minGapMs);
}

function registerRateLimit(response){
  const retryAfterMs = parseRetryAfterMs(response);
  rateLimitedUntil = Math.max(
    rateLimitedUntil,
    Date.now() + Math.max(DEFAULT_RATE_LIMIT_BACKOFF_MS, retryAfterMs)
  );
}

function parseRetryAfterMs(response){
  const raw = String(response.headers.get('Retry-After') || '').trim();
  if (!raw) return 0;

  const seconds = Number(raw);
  if (Number.isFinite(seconds) && seconds >= 0) {
    return Math.ceil(seconds * 1000);
  }

  const date = Date.parse(raw);
  return Number.isFinite(date) ? Math.max(0, date - Date.now()) : 0;
}

function friendlyRateLimitResponse(response){
  const headers = new Headers(response.headers);
  headers.set('Content-Type', 'application/json; charset=utf-8');

  return new Response(JSON.stringify({
    ok:false,
    error:'Связь временно перегружена. Подключение восстановится автоматически.',
  }), {
    status:429,
    statusText:response.statusText || 'Too Many Requests',
    headers,
  });
}

function idleNotificationsResponse(){
  const rawCount = String(document.getElementById('notificationsOpen')?.dataset.unread || '0');
  const unreadCount = Math.max(0, Number.parseInt(rawCount, 10) || 0);

  return jsonResponse({
    ok:true,
    items:[],
    unread_count:unreadCount,
  });
}

function jsonResponse(data){
  return new Response(JSON.stringify(data), {
    status:200,
    headers:{'Content-Type':'application/json; charset=utf-8'},
  });
}

function isHomeScreen(){
  const activeScreen = document.querySelector('.screen.active');
  return String(activeScreen?.dataset.screen || '') === 'home';
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

async function waitForVisibleDocument(){
  if (document.visibilityState === 'visible') return;

  await new Promise(resolve => {
    const onVisibilityChange = () => {
      if (document.visibilityState !== 'visible') return;
      document.removeEventListener('visibilitychange', onVisibilityChange);
      resolve();
    };
    document.addEventListener('visibilitychange', onVisibilityChange);
  });
}

async function waitUntil(timestamp){
  const delay = Math.max(0, Number(timestamp || 0) - Date.now());
  if (delay <= 0) return;
  await new Promise(resolve => setTimeout(resolve, delay));
}
