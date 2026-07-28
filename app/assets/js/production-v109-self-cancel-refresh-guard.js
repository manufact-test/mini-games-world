let initialized = false;

export function initV109SelfCancelRefreshGuard(){
  if (initialized) return;
  initialized = true;

  window.addEventListener('mgw:notifications-refresh', event => {
    const pending = window.__MGW_V109_INVITE_SPEED__?.cancelTokens;
    if (!(pending instanceof Set) || pending.size === 0) return;

    // The cancelling user needs no notification sheet or self confirmation.
    // The other participant receives the authoritative server notification.
    event.stopImmediatePropagation();
  }, true);
}
