const nativeFetch = window.fetch.bind(window);

const inFlight = new Map();
const nextAllowedAt = new Map();
const responseCache = new Map();
const DEFAULT_RATE_LIMIT_BACKOFF_MS = 5000;
const MAX_RATE_LIMIT_BACKOFF_MS = 60000;
const RATE_LIMIT_JITTER_MS = 3000;
const GAME_STATE_MIN_GAP_MS = 2400;
const SEARCH_STATE_MIN_GAP_MS = 3500;
const STATS_MIN_GAP_MS = 30000;
const NOTIFICATIONS_MIN_GAP_MS = 30000;

let installed = false;
let rateLimitedUntil = 0;
let consecutiveRateLimits = 0;

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

  if (meta.cacheWhenHidden && document.visibilityState !== 'visible') {
    const cached = cachedResponse(meta.cacheKey);
    if (cached) return cached;
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
  if (response.status !== 429) {
    registerSuccessfulRequest();
    await rememberResponse(meta, response);
    return response;
  }

  registerRateLimit(response);
  if (!meta.safeRetry) return friendlyRateLimitResponse(response);

  await waitUntil(rateLimitedUntil);
  markRequestStarted(meta);
  response = await nativeFetch(input, init);

  if (response.status === 429) {
    registerRateLimit(response);
    return cachedResponse(meta.cacheKey) || friendlyRateLimitResponse(response);
  }

  registerSuccessfulRequest();
  await rememberResponse(meta, response);
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
      const hasGameId = String(payload.gameId || '') !== '';
      const gameId = String(payload.gameId || 'current');
      const key = `game_state:${gameId}`;
      const minGapMs = hasGameId ? GAME_STATE_MIN_GAP_MS : SEARCH_STATE_MIN_GAP_MS;
      return {
        kind:'game_state',
        singleFlightKey:key,
        cacheKey:key,
        cacheWhenHidden:true,
        throttleKey:hasGameId ? 'game_state' : 'search_state',
        minGapMs,
        jitterMs:Math.round(minGapMs * 0.2),
        safeRetry:true,
        waitForVisible:false,
      };
    }

    if (action === 'stats') {
      return {
        kind:'stats',
        singleFlightKey:'stats',
        cacheKey:'stats',
        cacheWhenHidden:true,
        throttleKey:'stats',
        minGapMs:STATS_MIN_GAP_MS,
        jitterMs:5000,
        safeRetry:true,
        waitForVisible:false,
      };
    }

    if (action === 'game_action') {
      return {
        kind:'game_action',
        singleFlightKey:`game_action:${String(payload.gameId || 'current')}`,
        cacheKey:'',
        cacheWhenHidden:false,
        throttleKey:'',
        minGapMs:0,
        jitterMs:0,
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
      cacheKey:'',
      cacheWhenHidden:false,
      throttleKey:markRead ? '' : 'notifications',
      minGapMs:markRead ? 0 : NOTIFICATIONS_MIN_GAP_MS,
      jitterMs:markRead ? 0 : 5000,
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
  const jitter = randomJitter(meta.jitterMs || 0);
  nextAllowedAt.set(meta.throttleKey, Date.now() + meta.minGapMs + jitter);
}

function randomJitter(maxMs){
  const safeMax = Math.max(0, Math.trunc(Number(maxMs || 0)));
  return safeMax > 0 ? Math.floor(Math.random() * (safeMax + 1)) : 0;
}

function registerSuccessfulRequest(){
  consecutiveRateLimits = 0;
}

function registerRateLimit(response){
  consecutiveRateLimits = Math.min(5, consecutiveRateLimits + 1);
  const retryAfterMs = parseRetryAfterMs(response);
  const exponentialBackoffMs = Math.min(
    MAX_RATE_LIMIT_BACKOFF_MS,
    DEFAULT_RATE_LIMIT_BACKOFF_MS * (2 ** (consecutiveRateLimits - 1))
  );
  const delayMs = Math.max(exponentialBackoffMs, retryAfterMs) + randomJitter(RATE_LIMIT_JITTER_MS);
  rateLimitedUntil = Math.max(rateLimitedUntil, Date.now() + delayMs);
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

async function rememberResponse(meta, response){
  if (!meta.cacheKey || !response.ok) return;

  try {
    responseCache.set(meta.cacheKey, {
      body:await response.clone().text(),
      contentType:response.headers.get('Content-Type') || 'application/json; charset=utf-8',
    });
  } catch (error) {
    // The live response still works even when a browser refuses to clone it.
  }
}

function cachedResponse(key){
  if (!key) return null;
  const cached = responseCache.get(key);
  if (!cached) return null;

  return new Response(cached.body, {
    status:200,
    headers:{'Content-Type':cached.contentType},
  });
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
