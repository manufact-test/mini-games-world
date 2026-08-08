const INVITES_PATH = '/bot/invites.php';
const LIFECYCLE_ACTIONS = new Set(['accept', 'start', 'decline', 'cancel']);

const runtime = window.__MGW_V110_INVITE_ACTION_TRANSPORT_OWNER__ ||= {
  initialized:false,
  upstreamFetch:null,
  lifecycleInFlight:0,
};

export function initV110InviteActionTransportOwner(){
  if (runtime.initialized) return;
  runtime.initialized = true;
  runtime.upstreamFetch = window.fetch.bind(window);
  window.fetch = inviteOwnedFetch;
}

async function inviteOwnedFetch(input, init = {}){
  const meta = inviteRequestMeta(input, init);
  if (!meta) return runtime.upstreamFetch(input, init);

  if (meta.action === 'sync' && runtime.lifecycleInFlight > 0) {
    throw ownedAbort();
  }

  if (!LIFECYCLE_ACTIONS.has(meta.action)) {
    return runtime.upstreamFetch(input, init);
  }

  runtime.lifecycleInFlight += 1;
  try {
    return await runtime.upstreamFetch(input, init);
  } finally {
    runtime.lifecycleInFlight = Math.max(0, runtime.lifecycleInFlight - 1);
  }
}

function inviteRequestMeta(input, init){
  const rawUrl = typeof input === 'string' ? input : String(input?.url || '');
  let url;
  try {
    url = new URL(rawUrl, window.location.href);
  } catch (error) {
    return null;
  }

  const method = String(init?.method || input?.method || 'GET').toUpperCase();
  if (url.origin !== window.location.origin || url.pathname !== INVITES_PATH || method !== 'POST') {
    return null;
  }

  const bodyText = typeof init?.body === 'string' ? init.body : '';
  if (!bodyText) return null;

  try {
    const body = JSON.parse(bodyText);
    return { action:String(body?.action || '') };
  } catch (error) {
    return null;
  }
}

function ownedAbort(){
  const error = new DOMException(
    'Background invite sync is superseded by the active lifecycle action.',
    'AbortError'
  );
  Object.defineProperty(error, 'mgwInviteTransportOwned', {
    value:true,
    enumerable:false,
  });
  return error;
}
