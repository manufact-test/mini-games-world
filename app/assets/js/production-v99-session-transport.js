const PASSIVE_API_ACTIONS = new Set([
  'bootstrap',
  'game_state',
  'stats',
  'profile',
  'history',
  'weekly_match_status',
  'shop_status',
]);
const PASSIVE_INVITE_ACTIONS = new Set(['sync', 'open_link']);
const GAME_INVITE_ACTIONS = new Set(['sync', 'open_link', 'accept', 'start', 'rematch']);

let initialized = false;
let previousFetch = null;
const INVITE_EXPECTATION_KEY = 'mgw_v99_invite_game_expected';

export function initV99SessionTransport(){
  if (initialized) return;
  initialized = true;
  previousFetch = window.fetch.bind(window);
  window.fetch = v99Fetch;
  document.addEventListener('click', rememberExplicitInviteIntent, true);
}

export function currentV99PassiveLock(){
  return window.__MGW_V99_PASSIVE_LOCK__ || null;
}

export function rememberV99PassiveLock(session){
  const lock = {
    locked:true,
    message:String(session?.message || 'У вас уже идёт активная игра на другом устройстве.'),
    updatedAt:Date.now(),
  };
  window.__MGW_V99_PASSIVE_LOCK__ = lock;
  document.dispatchEvent(new CustomEvent('mgw:v99-passive-lock-changed', { detail:lock }));
  return lock;
}

export function clearV99PassiveLock(){
  if (!window.__MGW_V99_PASSIVE_LOCK__) return;
  window.__MGW_V99_PASSIVE_LOCK__ = null;
  document.dispatchEvent(new CustomEvent('mgw:v99-passive-lock-changed', { detail:null }));
}

async function v99Fetch(input, init = {}){
  const meta = requestMeta(input, init);
  const response = await previousFetch(input, init);
  if (!meta || !response.ok) return response;

  const data = await response.clone().json().catch(() => null);
  if (!data || typeof data !== 'object') return response;

  let changed = false;
  if (meta.kind === 'api' && PASSIVE_API_ACTIONS.has(meta.action)) {
    if (data?.session?.locked) {
      rememberV99PassiveLock(data.session);
      data.session = passiveSession(data.session);
      data.game = null;
      data.active_game = null;
      data.me = null;
      changed = true;
    } else if (data?.session) {
      clearV99PassiveLock();
    }
  }

  if (meta.kind === 'invites') {
    if (PASSIVE_INVITE_ACTIONS.has(meta.action) && data?.session?.locked) {
      rememberV99PassiveLock(data.session);
      data.session = passiveSession(data.session);
      data.game = null;
      data.active_game = null;
      changed = true;
    }

    const game = data?.game?.id ? data.game : (data?.active_game?.id ? data.active_game : null);
    if (GAME_INVITE_ACTIONS.has(meta.action) && game && String(game.status || '') === 'active') {
      const explicitAction = ['open_link', 'accept', 'start', 'rematch'].includes(meta.action);
      const mayEnter = explicitAction || inviteExpectationActive();
      if (!currentV99PassiveLock()?.locked && mayEnter) {
        clearInviteExpectation();
        publishGame(game, data.me || null);
      }
      data.game = null;
      data.active_game = null;
      changed = true;
    }
  }

  return changed ? responseFromJson(response, data) : response;
}

function rememberExplicitInviteIntent(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const action = String(origin.closest('[data-invite-action]')?.dataset.inviteAction || '');
  const rematch = origin.closest('[data-create-rematch]');
  if (!['accept','start'].includes(action) && !rematch) return;
  try {
    sessionStorage.setItem(INVITE_EXPECTATION_KEY, String(Date.now() + 10 * 60 * 1000));
  } catch (error) {
    window.__MGW_V99_INVITE_EXPECTED_UNTIL__ = Date.now() + 10 * 60 * 1000;
  }
}

function inviteExpectationActive(){
  let until = Number(window.__MGW_V99_INVITE_EXPECTED_UNTIL__ || 0);
  try {
    until = Math.max(until, Number(sessionStorage.getItem(INVITE_EXPECTATION_KEY) || 0));
  } catch (error) {
    // Session storage is optional in restricted WebViews.
  }
  return until > Date.now();
}

function clearInviteExpectation(){
  window.__MGW_V99_INVITE_EXPECTED_UNTIL__ = 0;
  try { sessionStorage.removeItem(INVITE_EXPECTATION_KEY); } catch (error) {}
}

function publishGame(game, me){
  const detail = { game, me };
  queueMicrotask(() => document.dispatchEvent(new CustomEvent('mgw:v99-game-found', { detail })));
}

function passiveSession(session){
  return {
    ...session,
    locked:false,
    passive_locked:true,
    passive_lock_message:String(session?.message || 'У вас уже идёт активная игра на другом устройстве.'),
  };
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
  if (url.pathname.endsWith('/bot/api.php')) return { kind:'api', action:String(payload.action || '') };
  if (url.pathname.endsWith('/bot/invites.php')) return { kind:'invites', action:String(payload.action || '') };
  return null;
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
