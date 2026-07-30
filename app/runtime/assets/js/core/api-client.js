const ENDPOINT = new URL('../../../api.php', import.meta.url);

export function createRuntimeApi(fetchImpl = window.fetch.bind(window)){
  if (typeof fetchImpl !== 'function') throw new TypeError('A fetch implementation is required.');

  async function bootstrap(context){
    return post('bootstrap', buildPayload(context, true));
  }

  async function heartbeat(context){
    return post('heartbeat', buildPayload(context, false));
  }

  async function health(){
    const response = await fetch(`${ENDPOINT.href}?action=health`, {
      method:'GET',
      cache:'no-store',
      credentials:'same-origin',
    });
    return readResponse(response);
  }

  async function post(action, payload){
    const response = await fetch(`${ENDPOINT.href}?action=${encodeURIComponent(action)}`, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      cache:'no-store',
      credentials:'same-origin',
      body:JSON.stringify(payload),
    });
    return readResponse(response);
  }

  return Object.freeze({ bootstrap, heartbeat, health });
}

function buildPayload(context, includeLaunch){
  const launch = context?.launch || {};
  const payload = {
    installation_id:String(context?.installationId || ''),
    session_id:String(context?.sessionId || ''),
    init_data:String(context?.initData || ''),
    presence:{
      visibility:String(context?.presence?.visibility || 'unknown'),
      platform:String(context?.presence?.platform || 'unknown'),
      timezone_offset:Number(context?.presence?.timezone_offset || 0),
    },
  };
  if (includeLaunch) {
    payload.launch = {
      runtime:String(launch.runtime || ''),
      path:String(launch.path || ''),
      source:String(launch.source || 'standard'),
      invite_present:Boolean(launch.inviteToken),
      telegram_available:Boolean(launch.telegramAvailable),
    };
  }
  return payload;
}

async function readResponse(response){
  const payload = await response.json().catch(() => null);
  if (!response.ok || !payload || payload.ok !== true) {
    throw new Error(String(payload?.error || `Clean runtime request failed (${response.status}).`));
  }
  return payload;
}
