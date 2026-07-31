import { getInitData } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';

const NOTIFICATIONS_URL = `${window.location.origin}/bot/notifications.php`;
const RETRY_MS = 120;
const MAX_ATTEMPTS = 10;

const runtime = window.__MGW_V110_NOTIFICATION_PREFLIGHT__ ||= {
  initialized:false,
  busy:false,
  bypass:false,
};

export function initV110NotificationPreflight(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  // Register before the notification owner. The owner still renders the sheet;
  // this transport only makes sure its live list is populated before opening.
  document.addEventListener('click', interceptOpen, true);
}

function interceptOpen(event){
  if (runtime.bypass) return;
  const target = event.target instanceof Element
    ? event.target.closest('#notificationToast, #notificationsOpen')
    : null;
  if (!target) return;
  if (target.id === 'notificationToast' && !target.classList.contains('show')) return;

  event.preventDefault();
  event.stopImmediatePropagation();
  void primeAndOpen();
}

async function primeAndOpen(){
  if (runtime.busy) return;
  runtime.busy = true;

  let lastUnread = 0;
  try {
    for (let attempt = 0; attempt < MAX_ATTEMPTS; attempt++) {
      const result = await readNotifications().catch(() => null);
      const items = Array.isArray(result?.items) ? result.items : [];
      lastUnread = Number(result?.unread_count || lastUnread || 0);

      document.dispatchEvent(new CustomEvent('mgw:notification-count', {
        detail:{ unreadCount:lastUnread },
      }));
      for (const item of items) {
        if (!item?.id) continue;
        document.dispatchEvent(new CustomEvent('mgw:notification-sync', {
          detail:{ item, unreadCount:lastUnread },
        }));
      }

      if (items.length > 0 || lastUnread <= 0) break;
      await delay(RETRY_MS);
    }
  } finally {
    runtime.busy = false;
    openThroughNotificationOwner();
  }
}

function openThroughNotificationOwner(){
  const button = document.getElementById('notificationsOpen');
  if (!(button instanceof HTMLElement)) return;

  runtime.bypass = true;
  try {
    button.click();
  } finally {
    queueMicrotask(() => { runtime.bypass = false; });
  }
}

async function readNotifications(){
  const speed = window.__MGW_V101_SPEED__;
  const fetcher = typeof speed?.rawFetch === 'function'
    ? speed.rawFetch
    : window.fetch.bind(window);
  const response = await fetcher(NOTIFICATIONS_URL, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({
      initData:getInitData(),
      sessionId:getSessionId(),
      markRead:false,
    }),
    priority:'high',
    cache:'no-store',
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) {
    throw new Error(data?.error || 'Notification preflight failed');
  }
  return data;
}

function delay(ms){
  return new Promise(resolve => window.setTimeout(resolve, ms));
}
