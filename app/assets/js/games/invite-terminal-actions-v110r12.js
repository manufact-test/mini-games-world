import { getInitData } from '../telegram/telegram-app.js?v=27';
import { getSessionId } from '../session.js?v=27';
import { closeSheet } from '../components/sheet.js?v=1109';
import { toast } from '../components/toast.js?v=1109';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const TERMINAL_ACTIONS = new Set(['decline', 'cancel']);
let initialized = false;
let busyToken = '';

export function initInviteTerminalActions(){
  if (initialized) return;
  initialized = true;
  // Terminal actions are owned before any document-level compatibility handler.
  // This guarantees that decline/cancel cannot fall through to an old success toast.
  window.addEventListener('click', handleTerminalAction, true);
}

async function handleTerminalAction(event){
  const button = event.target instanceof Element
    ? event.target.closest('[data-invite-action][data-invite-token]')
    : null;
  if (!(button instanceof HTMLButtonElement)) return;

  const action = String(button.dataset.inviteAction || '');
  const token = String(button.dataset.inviteToken || '');
  if (!TERMINAL_ACTIONS.has(action) || !token) return;

  event.preventDefault();
  event.stopImmediatePropagation();
  if (busyToken) return;

  const notificationSurface = isNotificationSurface(button);
  busyToken = token;

  document.dispatchEvent(new CustomEvent('mgw:invite-terminal-action-started', {
    detail:{ action, token, notificationSurface },
  }));
  closeSheet();
  document.dispatchEvent(new CustomEvent('mgw:notification-remove', {
    detail:{ inviteToken:token },
  }));

  try {
    const result = await inviteRequest(action, token);
    const invite = result?.invite && typeof result.invite === 'object' ? result.invite : { token };
    const rawUnreadCount = result?.unread_count;
    const unreadCount = rawUnreadCount !== undefined && rawUnreadCount !== null
      && Number.isFinite(Number(rawUnreadCount))
      ? Math.max(0, Number(rawUnreadCount))
      : null;

    if (unreadCount !== null) {
      document.dispatchEvent(new CustomEvent('mgw:notification-count', {
        detail:{ unreadCount },
      }));
    }
    document.dispatchEvent(new CustomEvent('mgw:invite-terminal-action-completed', {
      detail:{ action, token, invite, notificationSurface },
    }));
    // Local removal plus the authoritative unread_count are already complete.
    // A success-side notifications refresh can return an older snapshot after
    // the next invite toast has opened, so normal invite sync owns reconciliation.
    document.dispatchEvent(new CustomEvent('mgw:game-dismissed'));
  } catch (error) {
    document.dispatchEvent(new CustomEvent('mgw:invite-terminal-action-failed', {
      detail:{ action, token, notificationSurface },
    }));
    // Failure must restore the still-authoritative pending invitation.
    document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
    toast(error?.message || 'Не удалось изменить приглашение.');
  } finally {
    busyToken = '';
  }
}

function isNotificationSurface(button){
  const sheet = document.getElementById('sheet');
  return Boolean(
    sheet?.contains(button)
      && sheet.querySelector('[data-notifications-owner="r12"]')
  );
}

async function inviteRequest(action, token){
  const speed = window.__MGW_V101_SPEED__;
  const fetcher = typeof speed?.rawFetch === 'function'
    ? speed.rawFetch
    : window.fetch.bind(window);
  const response = await fetcher(INVITES_URL, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({
      initData:getInitData(),
      sessionId:getSessionId(),
      action,
      token,
    }),
    priority:'high',
    cache:'no-store',
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) {
    throw new Error(data?.error || `Ошибка приглашения: ${response.status}`);
  }
  return data;
}
