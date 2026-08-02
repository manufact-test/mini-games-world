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
  document.addEventListener('click', handleTerminalAction, true);
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
  const originalLabel = button.textContent || '';
  button.disabled = true;
  button.textContent = action === 'decline' ? 'Отклоняем…' : 'Отменяем…';

  try {
    const result = await inviteRequest(action, token);
    const invite = result?.invite && typeof result.invite === 'object' ? result.invite : { token };
    const rawUnreadCount = result?.unread_count;
    const unreadCount = rawUnreadCount !== undefined && rawUnreadCount !== null
      && Number.isFinite(Number(rawUnreadCount))
      ? Math.max(0, Number(rawUnreadCount))
      : null;

    if (notificationSurface) {
      const item = terminalNotificationItem(action, token, invite);
      const detail = { item, announce:false };
      if (unreadCount !== null) detail.unreadCount = unreadCount;
      document.dispatchEvent(new CustomEvent('mgw:notification-sync', { detail }));
    } else {
      closeSheet();
    }

    if (unreadCount !== null) {
      document.dispatchEvent(new CustomEvent('mgw:notification-count', {
        detail:{ unreadCount },
      }));
    }
    document.dispatchEvent(new CustomEvent('mgw:invite-terminal-action-completed', {
      detail:{ action, token, invite, notificationSurface },
    }));

    window.setTimeout(() => {
      document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
    }, 600);
  } catch (error) {
    button.disabled = false;
    button.textContent = originalLabel;
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

function terminalNotificationItem(action, token, invite){
  const card = [...document.querySelectorAll('[data-notification-invite-token]')]
    .find(element => String(element.getAttribute('data-notification-invite-token') || '') === token);
  const notificationId = String(card?.getAttribute('data-notification-id') || `local_invite_${token}`);
  const source = String(invite?.source || '');
  const type = action === 'decline'
    ? (source === 'rematch' ? 'invite_rematch_received' : 'invite_received')
    : 'invite_accepted';

  return {
    id:notificationId,
    type,
    title:action === 'decline' ? 'Приглашение отклонено' : 'Приглашение отменено',
    message:'Приглашение больше недоступно.',
    tone:'warning',
    invite_token:token,
    invite_status:String(invite?.status || (action === 'decline' ? 'declined' : 'cancelled')),
    actions:[],
    read:true,
    created_at:new Date().toISOString(),
  };
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
