const OPPONENTS_PATH = '/bot/invite-opponents.php';
const RETRY_DELAYS_MS = [80, 140, 240, 400, 650, 950];
const REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES = 2;
const upstreamFetch = window.fetch.bind(window);
const nativeFetch = typeof window.__MGW_NATIVE_FETCH_V115__ === 'function'
  ? window.__MGW_NATIVE_FETCH_V115__
  : upstreamFetch;

window.fetch = async function opponentsAuthoritativeConfirm(input, init = {}){
  if (!isOpponentsRequest(input, init)) return upstreamFetch(input, init);

  let latestResponse = await upstreamFetch(input, init);
  let snapshot = await inspect(latestResponse);
  if (snapshot.hasPlayers || !latestResponse.ok) return latestResponse;

  // Never publish the first empty response. It can be an old prefetched cache
  // or a transient edge snapshot. A final empty state requires two separate
  // authoritative DB-primary responses; an unmarked empty response is only a
  // transport sample and can never finish the user-visible picker.
  let authoritativeEmptyResponses = snapshot.authoritative ? 1 : 0;

  for (const delayMs of RETRY_DELAYS_MS) {
    await delay(delayMs);
    try {
      const response = await nativeFetch(input, {
        ...init,
        cache:'no-store',
        headers:withNoCacheHeaders(init?.headers),
      });
      latestResponse = response;
      snapshot = await inspect(response);
      if (snapshot.hasPlayers) return response;
      if (!response.ok) break;
      if (snapshot.authoritative) {
        authoritativeEmptyResponses += 1;
        if (authoritativeEmptyResponses >= REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES) {
          return response;
        }
      }
    } catch (error) {
      // Keep loading and use the remaining bounded confirmation samples.
    }
  }

  if (authoritativeEmptyResponses >= REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES) {
    return latestResponse;
  }

  throw new Error('Authoritative opponent list was not confirmed.');
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
  headers.set('Cache-Control', 'no-cache');
  headers.set('Pragma', 'no-cache');
  return headers;
}

function delay(ms){
  return new Promise(resolve => window.setTimeout(resolve, ms));
}
