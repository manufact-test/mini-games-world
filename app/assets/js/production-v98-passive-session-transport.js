const PASSIVE_API_ACTIONS = new Set([
  'bootstrap',
  'game_state',
  'stats',
  'profile',
  'history',
  'weekly_match_status',
  'shop_status',
]);
const INVITE_GAME_ACTIONS = new Set(['sync', 'open_link', 'accept', 'start', 'rematch']);

let initialized = false;
let previousFetch = null;

export function initV98PassiveSessionTransport(){
  if (initialized) return;
  initialized = true;
  previousFetch = window.fetch.bind(window);
  window.fetch = v98Fetch;
}

async function v98Fetch(input, init = {}){
  const meta = requestMeta(input, init);
  const response = await previousFetch(input, init);
  if (!meta) return response;

  const data = await response.clone().json().catch(() => null);
  if (!data || typeof data !== 'object') return response;

  let changed = false;
  if (meta.kind === 'api' && PASSIVE_API_ACTIONS.has(meta.action)) {
    if (data?.session?.locked) {
      rememberPassiveLock(data.session);
      data.session = passiveSession(data.session);
      data.game = null;
      data.active_game = null;
      data.me = null;
      changed = true;
    } else if (data?.session) {
      clearPassiveLock();
    }

    if (meta.action === 'bootstrap' && data?.active_game?.id && !data?.session?.locked) {
      publishPendingGame(data.active_game, data.me || null);
      data.active_game = null;
      changed = true;
    }
  }

  if (meta.kind === 'invites' && INVITE_GAME_ACTIONS.has(meta.action)) {
    const game = data?.game?.id ? data.game : (data?.active_game?.id ? data.active_game : null);
    if (game && String(game.status || '') === 'active') {
      publishPendingGame(game, data.me || null);
      data.game = null;
      data.active_game = null;
      changed = true;
    }
  }

  return changed ? responseFromJson(response, data) : response;
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
    return { kind:'api', action:String(payload.action || '') };
  }
  if (url.pathname.endsWith('/bot/invites.php')) {
    return { kind:'invites', action:String(payload.action || '') };
  }
  return null;
}

function rememberPassiveLock(session){
  window.__MGW_V98_PASSIVE_SESSION_LOCK__ = {
    locked:true,
    message:String(session?.message || 'У вас уже идёт активная игра на другом устройстве.'),
    updatedAt:Date.now(),
  };
  document.dispatchEvent(new CustomEvent('mgw:v98-passive-lock-changed'));
}

function clearPassiveLock(){
  if (!window.__MGW_V98_PASSIVE_SESSION_LOCK__) return;
  window.__MGW_V98_PASSIVE_SESSION_LOCK__ = null;
  document.dispatchEvent(new CustomEvent('mgw:v98-passive-lock-changed'));
}

function passiveSession(session){
  return {
    ...session,
    locked:false,
    passive_locked:true,
    passive_lock_message:String(session?.message || 'У вас уже идёт активная игра на другом устройстве.'),
  };
}

function publishPendingGame(game, me){
  const detail = { game, me };
  window.__MGW_V98_PENDING_GAME__ = detail;
  queueMicrotask(() => document.dispatchEvent(new CustomEvent('mgw:v98-game-found', { detail })));
}

function responseFromJson(response, data){
  const headers = new Headers(response.headers);
  headers.set('Content-Type', 'application/json; charset=utf-8');
  return new Response(JSON.stringify(data), {
    status:response.status,
    statusText:response.statusText,
    headers,
  });
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
