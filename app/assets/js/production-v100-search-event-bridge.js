let initialized = false;

export function initV100SearchEventBridge(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('mgw:v99-search-request', event => {
    document.dispatchEvent(new CustomEvent('mgw:v100-search-request', {
      detail:event.detail || {},
    }));
  });
}
