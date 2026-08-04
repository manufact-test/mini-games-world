const OPPONENTS_PATH = '/bot/invite-opponents.php';
const RETRY_DELAYS_MS = [150, 250, 400, 600, 850, 1100];
const REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES = 3;
const MIN_EMPTY_CONFIRMATION_MS = 3200;
const upstreamFetch = window.fetch.bind(window);
const nativeFetch = typeof window.__MGW_NATIVE_FETCH_V115__ === 'function'
  ? window.__MGW_NATIVE_FETCH_V115__
  : upstreamFetch;

window.fetch = async function opponentsAuthoritativeConfirm(input, init = {}){
  if (!isOpponentsRequest(input, init)) return upstreamFetch(input, init);

  const startedAt = performance.now();
  let latestResponse = await upstreamFetch(input, init);
  let snapshot = await inspect(latestResponse);
  if (snapshot.hasPlayers || !latestResponse.ok) return latestResponse;

  // The JSON state is the canonical player-profile catalog and live presence is
  // layered onto it by the endpoint. Empty becomes final only after several
  // complete catalog+presence samples spanning the full transient window.
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
      if (snapshot.authoritative) authoritativeEmptyResponses += 1;

      if (authoritativeEmptyResponses >= REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES
          && performance.now() - startedAt >= MIN_EMPTY_CONFIRMATION_MS) {
        return response;
      }
    } catch (error) {
      // Keep neutral loading and use the remaining bounded samples.
    }
  }

  if (authoritativeEmptyResponses >= REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES
      && performance.now() - startedAt >= MIN_EMPTY_CONFIRMATION_MS) {
    return latestResponse;
  }

  throw new Error('Authoritative opponent list was not confirmed.');
};

async function inspect(response){
  if (!response?.ok) return { hasPlayers:false, authoritative:false };
  const payload = await response.clone().json().catch(() => null);
  return {
    hasPlayers:Array.isArray(payload?.items) && payload.items.length > 0,
    authoritative:payload?.authoritative === true
      && payload?.complete === true
      && payload?.storage_driver === 'json'
      && Number(payload?.unresolved_online_count || 0) === 0,
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

function delay(ms){ return new Promise(resolve => window.setTimeout(resolve, ms)); }
