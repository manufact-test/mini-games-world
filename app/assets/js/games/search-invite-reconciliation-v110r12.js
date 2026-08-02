import { getInitData } from '../telegram/telegram-app.js?v=27';
import { getSessionId } from '../session.js?v=27';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
let initialized = false;
let busy = false;
let queued = false;

export function initSearchInviteReconciliation(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('mgw:search-stopped', () => {
    void reconcileInviteNotifications();
  });
}

async function reconcileInviteNotifications(){
  if (busy) {
    queued = true;
    return;
  }

  busy = true;
  try {
    const result = await inviteSync();
    const unreadCount = Number(result?.unread_count || 0);
    document.dispatchEvent(new CustomEvent('mgw:notification-count', {
      detail:{ unreadCount },
    }));

    for (const value of Array.isArray(result?.invite_events) ? result.invite_events : []) {
      const item = value && typeof value === 'object' ? value : null;
      if (!item?.id || !item?.invite_token) continue;
      document.dispatchEvent(new CustomEvent('mgw:notification-sync', {
        detail:{ item, unreadCount, announce:false },
      }));
    }
  } catch (error) {
    document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
  } finally {
    busy = false;
    if (queued) {
      queued = false;
      void reconcileInviteNotifications();
    }
  }
}

async function inviteSync(){
  const response = await fetch(INVITES_URL, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({
      initData:getInitData(),
      sessionId:getSessionId(),
      action:'sync',
      token:'',
    }),
    priority:'high',
    cache:'no-store',
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) {
    throw new Error(data?.error || 'Invite reconciliation failed.');
  }
  return data;
}
