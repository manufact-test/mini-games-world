const OPPONENTS_PATH = '/bot/invite-opponents.php';
const upstreamFetch = window.fetch.bind(window);
const nativeFetch = typeof window.__MGW_NATIVE_FETCH_V115__ === 'function'
  ? window.__MGW_NATIVE_FETCH_V115__
  : upstreamFetch;

window.fetch = async function opponentsEmptyCacheGuard(input, init = {}){
  if (!isOpponentsRequest(input, init)) return upstreamFetch(input, init);

  const cachedResponse = await upstreamFetch(input, init);
  const cachedPayload = await cachedResponse.clone().json().catch(() => null);
  const cachedItems = Array.isArray(cachedPayload?.items) ? cachedPayload.items : [];
  if (cachedItems.length > 0 || !cachedResponse.ok) return cachedResponse;

  try {
    const authoritativeResponse = await nativeFetch(input, {
      ...init,
      cache:'no-store',
    });
    return authoritativeResponse.ok ? authoritativeResponse : cachedResponse;
  } catch (error) {
    return cachedResponse;
  }
};

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
