import { applyServerProjection } from './server-projection.js';

const DEFAULT_INTERVAL_MS = 25000;

export function createPresenceOwner({ api, store, requestContext, intervalMs = DEFAULT_INTERVAL_MS }){
  if (!api || typeof api.heartbeat !== 'function') throw new TypeError('Clean presence API is required.');
  if (!store || typeof store.setState !== 'function') throw new TypeError('Clean presence store is required.');
  if (typeof requestContext !== 'function') throw new TypeError('Clean presence request context is required.');

  let started = false;
  let timer = 0;
  let inFlight = null;

  async function heartbeat(){
    if (inFlight) return inFlight;
    inFlight = api.heartbeat(requestContext())
      .then(result => {
        applyServerProjection(store, result);
        document.dispatchEvent(new CustomEvent('mgw:clean-presence-updated', {
          detail:{ presence:result.presence, session:result.session },
        }));
        return result;
      })
      .catch(error => {
        const previous = store.getState().presence || {};
        store.setState({
          presence:{ ...previous, state:'degraded', error:String(error?.message || error) },
        });
        document.dispatchEvent(new CustomEvent('mgw:clean-presence-failed'));
        return null;
      })
      .finally(() => {
        inFlight = null;
      });
    return inFlight;
  }

  function onVisibilityChange(){
    void heartbeat();
  }

  function start(){
    if (started) return;
    started = true;
    document.addEventListener('visibilitychange', onVisibilityChange);
    timer = window.setInterval(() => void heartbeat(), Math.max(10000, Number(intervalMs) || DEFAULT_INTERVAL_MS));
  }

  function stop(){
    if (!started) return;
    started = false;
    document.removeEventListener('visibilitychange', onVisibilityChange);
    if (timer) window.clearInterval(timer);
    timer = 0;
  }

  return Object.freeze({ start, stop, heartbeat });
}
