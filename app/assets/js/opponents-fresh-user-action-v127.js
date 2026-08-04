const OPPONENTS_PATH = '/bot/invite-opponents.php';
const RETRY_DELAYS_MS = [140, 320, 620];
const upstreamFetch = window.fetch.bind(window);
const nativeFetch = typeof window.__MGW_NATIVE_FETCH_V115__ === 'function'
  ? window.__MGW_NATIVE_FETCH_V115__
  : upstreamFetch;

window.fetch = async function opponentsFreshUserActionV127(input, init = {}){
  if (!isOpponentsRequest(input, init)) return upstreamFetch(input, init);

  let latestResponse = null;
  for (let attempt = 0; attempt <= RETRY_DELAYS_MS.length; attempt += 1) {
    if (attempt > 0) await delay(RETRY_DELAYS_MS[attempt - 1]);

    latestResponse = await nativeFetch(input, {
      ...init,
      cache:'no-store',
      headers:withNoCacheHeaders(init?.headers),
    });

    const snapshot = await inspect(latestResponse);
    if (!latestResponse.ok || snapshot.hasPlayers) return latestResponse;

    // A genuinely empty authoritative DB-primary response is confirmed only
    // after short retries. Until then the picker keeps its loading frame and
    // never paints a stale or false empty state.
    if (snapshot.authoritative && attempt === RETRY_DELAYS_MS.length) {
      return latestResponse;
    }
  }

  return latestResponse || upstreamFetch(input, init);
};

async function inspect(response){
  if (!response?.ok) return { hasPlayers:false, authoritative:false };
  const payload = await response.clone().json().catch(() => null);
  return {
    hasPlayers:Array.isArray(payload?.items) && payload.items.length > 0,
    authoritative:payload?.authoritative === true && payload?.storage_driver === 'database',
  };
}

function isOpponentsRequest(input, init){
  const method = String(init?.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
  if (method !== 'POST') return false;

  try {
    const url = new URL(typeof input === 'string' ? input : input.url, window.location.href);
    return url.pathname.endsWith(OPPONENTS_PATH);
  } catch (error) {
    return false;
  }
}

function withNoCacheHeaders(source){
  const headers = new Headers(source || {});
  headers.set('Cache-Control', 'no-cache, no-store, max-age=0');
  headers.set('Pragma', 'no-cache');
  headers.set('X-MGW-Opponents-Source', 'manual-picker-v127');
  return headers;
}

function delay(ms){
  return new Promise(resolve => window.setTimeout(resolve, ms));
}
