const OPPONENTS_PATH = '/bot/invite-opponents.php';
const RETRY_DELAYS_MS = [80, 140, 240, 400, 650, 950];
const upstreamFetch = window.fetch.bind(window);
const nativeFetch = typeof window.__MGW_NATIVE_FETCH_V115__ === 'function'
  ? window.__MGW_NATIVE_FETCH_V115__
  : upstreamFetch;

window.fetch = async function opponentsFreshUserActionV128(input, init = {}){
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

    // Keep the loading frame through the same seven-sample window that the
    // previous authoritative guard provided. This gives a phone/account that
    // became active after desktop bootstrap enough time to appear, without
    // ever rendering a stale non-empty boot snapshot.
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
  headers.set('X-MGW-Opponents-Source', 'manual-picker-v128');
  return headers;
}

function delay(ms){
  return new Promise(resolve => window.setTimeout(resolve, ms));
}
