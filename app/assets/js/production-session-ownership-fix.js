const LEGACY_SESSION_KEY = 'mgw_device_session_id';
const SCOPED_SESSION_PREFIX = 'mgw_device_session_id:user:';
const OWNERSHIP_ERROR = 'Session ownership does not match the authenticated MGW account.';
const SAFE_RETRY_ACTIONS = new Set([
  'bootstrap',
  'profile',
  'history',
  'stats',
  'weekly_match_status',
  'game_state',
  'shop_status',
]);

const ERROR_TRANSLATIONS = new Map([
  [OWNERSHIP_ERROR, 'Сессия этого устройства устарела. Переподключаем профиль автоматически.'],
  ['Unable to resolve the MGW account.', 'Не удалось подключить игровой профиль. Закройте Mini Games World и откройте снова.'],
  ['Telegram identity subject is missing.', 'Telegram не передал данные аккаунта. Закройте Mini Games World и откройте снова из чата с ботом.'],
  ['Unable to register the MGW device.', 'Не удалось зарегистрировать это устройство. Закройте Mini Games World и откройте снова.'],
]);

let initialized = false;
let nativeFetch = null;

export function initSessionOwnershipFix(){
  if (initialized) return;
  initialized = true;

  syncScopedSession(false);
  nativeFetch = window.fetch.bind(window);
  window.fetch = ownershipAwareFetch;
}

async function ownershipAwareFetch(input, init = {}){
  const scopedSessionId = syncScopedSession(false);
  const firstInit = scopedSessionId ? rewriteSessionId(init, scopedSessionId) : init;

  let response = await nativeFetch(input, firstInit);
  const error = await responseError(response);

  if (error === OWNERSHIP_ERROR && isSafeRetryRequest(input, firstInit)) {
    const replacementSessionId = syncScopedSession(true);
    if (replacementSessionId) {
      response = await nativeFetch(input, rewriteSessionId(firstInit, replacementSessionId));
    }
  }

  return translateErrorResponse(response);
}

function syncScopedSession(forceRotate){
  const userId = telegramUserId();
  if (!userId) return '';

  const scopedKey = `${SCOPED_SESSION_PREFIX}${userId}`;
  let sessionId = '';

  try {
    sessionId = forceRotate ? '' : String(localStorage.getItem(scopedKey) || '').trim();
    if (!sessionId) {
      sessionId = createSessionId();
      localStorage.setItem(scopedKey, sessionId);
    }

    /* Legacy modules still read the old unscoped key. Keep that bridge pointed at
     * the session belonging to the currently authenticated Telegram account. */
    localStorage.setItem(LEGACY_SESSION_KEY, sessionId);
  } catch (error) {
    sessionId = createSessionId();
  }

  return sessionId;
}

function telegramUserId(){
  const direct = String(window.Telegram?.WebApp?.initDataUnsafe?.user?.id || '').trim();
  if (direct) return direct;

  try {
    const raw = String(window.Telegram?.WebApp?.initData || '');
    const user = JSON.parse(new URLSearchParams(raw).get('user') || 'null');
    return String(user?.id || '').trim();
  } catch (error) {
    return '';
  }
}

function createSessionId(){
  const random = globalThis.crypto?.randomUUID
    ? globalThis.crypto.randomUUID()
    : `${Date.now()}_${Math.random().toString(16).slice(2)}_${Math.random().toString(16).slice(2)}`;
  return `sess_${random}`;
}

function isSafeRetryRequest(input, init){
  const method = String(init?.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
  if (method !== 'POST') return false;

  try {
    const url = new URL(typeof input === 'string' ? input : input.url, window.location.href);
    if (!url.pathname.endsWith('/bot/api.php')) return false;
    const payload = parseBody(init?.body);
    return SAFE_RETRY_ACTIONS.has(String(payload.action || ''));
  } catch (error) {
    return false;
  }
}

function rewriteSessionId(init, sessionId){
  const payload = parseBody(init?.body);
  if (!payload || typeof payload !== 'object' || !Object.prototype.hasOwnProperty.call(payload, 'sessionId')) return init;

  return {
    ...init,
    body:JSON.stringify({ ...payload, sessionId }),
  };
}

async function responseError(response){
  if (!response || response.ok) return '';
  try {
    const data = await response.clone().json();
    return String(data?.error || '').trim();
  } catch (error) {
    return '';
  }
}

async function translateErrorResponse(response){
  if (!response || response.ok) return response;

  let data;
  try {
    data = await response.clone().json();
  } catch (error) {
    return response;
  }

  const message = String(data?.error || '').trim();
  const translated = ERROR_TRANSLATIONS.get(message);
  if (!translated) return response;

  const headers = new Headers(response.headers);
  headers.set('Content-Type', 'application/json; charset=utf-8');

  return new Response(JSON.stringify({ ...data, error:translated }), {
    status:response.status,
    statusText:response.statusText,
    headers,
  });
}

function parseBody(body){
  if (typeof body !== 'string' || body === '') return {};
  try {
    const parsed = JSON.parse(body);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch (error) {
    return {};
  }
}
