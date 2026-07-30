const ENDPOINT = new URL('../../../api.php', import.meta.url);

export function createRuntimeApi(fetchImpl = window.fetch.bind(window)){
  if (typeof fetchImpl !== 'function') throw new TypeError('A fetch implementation is required.');

  async function bootstrap({ installationId, launch }){
    const response = await fetch(`${ENDPOINT.href}?action=bootstrap`, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      cache:'no-store',
      credentials:'same-origin',
      body:JSON.stringify({
        installation_id:installationId,
        launch:{
          runtime:String(launch?.runtime || ''),
          path:String(launch?.path || ''),
          source:String(launch?.source || 'standard'),
          invite_present:Boolean(launch?.inviteToken),
          telegram_available:Boolean(launch?.telegramAvailable),
        },
      }),
    });
    return readResponse(response);
  }

  async function health(){
    const response = await fetch(`${ENDPOINT.href}?action=health`, {
      method:'GET',
      cache:'no-store',
      credentials:'same-origin',
    });
    return readResponse(response);
  }

  return Object.freeze({ bootstrap, health });
}

async function readResponse(response){
  const payload = await response.json().catch(() => null);
  if (!response.ok || !payload || payload.ok !== true) {
    throw new Error(String(payload?.error || `Clean runtime request failed (${response.status}).`));
  }
  return payload;
}
