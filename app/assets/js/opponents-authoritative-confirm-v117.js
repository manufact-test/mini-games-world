const OPPONENTS_PATH = '/bot/invite-opponents.php';
const RETRY_DELAYS_MS = [120, 260, 520];
const upstreamFetch = window.fetch.bind(window);
const nativeFetch = typeof window.__MGW_NATIVE_FETCH_V115__ === 'function'
  ? window.__MGW_NATIVE_FETCH_V115__
  : upstreamFetch;

window.fetch = async function opponentsAuthoritativeConfirm(input, init = {}){
  if (!isOpponentsRequest(input, init)) return upstreamFetch(input, init);

  let latestResponse = await upstreamFetch(input, init);
  if (await hasPlayers(latestResponse)) return latestResponse;
  if (!latestResponse.ok) return latestResponse;

  for (const delayMs of RETRY_DELAYS_MS) {
    await delay(delayMs);
    try {
      const response = await nativeFetch(input, {
        ...init,
        cache:'no-store',
        headers:withNoCacheHeaders(init?.headers),
      });
      latestResponse = response;
      if (await hasPlayers(response)) return response;
      if (!response.ok) break;
    } catch (error) {
      // Keep the most recent valid empty response and finish the confirmation window.
    }
  }

  return latestResponse;
};

async function hasPlayers(response){
  if (!response?.ok) return false;
  const payload = await response.clone().json().catch(() => null);
  return Array.isArray(payload?.items) && payload.items.length > 0;
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
