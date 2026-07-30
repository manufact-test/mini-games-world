const ENDPOINT = new URL('../../../api.php', import.meta.url);

export function createRuntimeApi(fetchImpl = window.fetch.bind(window)){
  if (typeof fetchImpl !== 'function') throw new TypeError('A fetch implementation is required.');

  const bootstrap = context => post('bootstrap', buildPayload(context, true));
  const heartbeat = context => post('heartbeat', buildPayload(context, false));
  const syncMatch = context => post('match_sync', buildPayload(context, false));
  const startSearch = (context, commandId) => post('match_start_search', {
    ...buildPayload(context, false),
    command_id:String(commandId || ''),
  });
  const cancelSearch = (context, commandId) => post('match_cancel_search', {
    ...buildPayload(context, false),
    command_id:String(commandId || ''),
  });
  const makeMove = (context, gameId, cell, commandId) => post('match_move', {
    ...buildPayload(context, false),
    game_id:String(gameId || ''),
    cell:Number(cell),
    command_id:String(commandId || ''),
  });
  const surrender = (context, gameId, commandId) => post('match_surrender', {
    ...buildPayload(context, false),
    game_id:String(gameId || ''),
    command_id:String(commandId || ''),
  });
  const dismissResult = (context, commandId) => post('match_dismiss_result', {
    ...buildPayload(context, false),
    command_id:String(commandId || ''),
  });

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

  return Object.freeze({
    bootstrap,
    heartbeat,
    health,
    syncMatch,
    startSearch,
    cancelSearch,
    makeMove,
    surrender,
    dismissResult,
  });
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
