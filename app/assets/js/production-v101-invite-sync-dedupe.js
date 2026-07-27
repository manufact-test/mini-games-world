import { stableHash } from './production-v101-speed-models.js?v=101';

const runtime = window.__MGW_V101_INVITE_SYNC_DEDUPE__ ||= {
  initialized:false,
  previousFetch:null,
  inFlight:new Map(),
};

export function initV101InviteSyncDedupe(){
  if (runtime.initialized) return;
  runtime.initialized = true;
  runtime.previousFetch = window.fetch.bind(window);
  window.fetch = dedupedFetch;
}

async function dedupedFetch(input, init = {}){
  const meta = syncMeta(input, init);
  if (!meta) return runtime.previousFetch(input, init);

  const existing = runtime.inFlight.get(meta.key);
  if (existing) return responseFromSnapshot(await existing);

  const promise = runtime.previousFetch(input, init)
    .then(snapshotResponse)
    .finally(() => runtime.inFlight.delete(meta.key));
  runtime.inFlight.set(meta.key, promise);
  return responseFromSnapshot(await promise);
}

function syncMeta(input, init){
  const method = String(init?.method || input?.method || 'GET').toUpperCase();
  if (method !== 'POST' || typeof init?.body !== 'string') return null;

  let url;
  let body;
  try {
    url = new URL(typeof input === 'string' ? input : input.url, window.location.href);
    body = JSON.parse(init.body);
  } catch (error) {
    return null;
  }

  if (!url.pathname.endsWith('/bot/invites.php') || String(body?.action || '') !== 'sync') return null;
  return {
    key:[
      stableHash(String(body?.initData || 'anonymous')),
      String(body?.sessionId || ''),
      String(body?.token || ''),
    ].join(':'),
  };
}

async function snapshotResponse(response){
  return {
    status:response.status,
    statusText:response.statusText,
    headers:Array.from(response.headers.entries()),
    body:await response.text(),
  };
}

function responseFromSnapshot(snapshot){
  return new Response(snapshot.body, {
    status:snapshot.status,
    statusText:snapshot.statusText,
    headers:snapshot.headers,
  });
}
