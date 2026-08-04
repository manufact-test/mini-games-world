const ANNOUNCED_STORAGE_KEY = 'mgw_announced_notifications_v3';
const MAX_ANNOUNCED_IDS = 300;
const STYLE_ID = 'mgw-deeplink-toast-policy-v130';
const RELEASE_TIMEOUT_MS = 15_000;

const token = incomingToken();
if (token) installDeepLinkToastPolicy();

function installDeepLinkToastPolicy(){
  window.__MGW_INVITE_LINK_OPENING__ = true;
  installHideStyle();

  const observer = new MutationObserver(() => {
    hideToastNow();
    if (isIncomingInviteSheetOpen()) release();
  });
  observer.observe(document.documentElement, {
    subtree:true,
    childList:true,
    attributes:true,
    attributeFilter:['class'],
  });

  document.addEventListener('mgw:notification-sync', event => {
    if (event.detail?.announce !== false) return;
    const item = event.detail?.item || null;
    const id = String(item?.id || '');
    if (id) rememberNotificationId(id);
    hideToastNow();
    event.stopImmediatePropagation();
  }, true);

  const timeout = window.setTimeout(release, RELEASE_TIMEOUT_MS);

  function release(){
    window.clearTimeout(timeout);
    observer.disconnect();
    hideToastNow();
    document.getElementById(STYLE_ID)?.remove();
    window.__MGW_INVITE_LINK_OPENING__ = false;
  }
}

function installHideStyle(){
  if (document.getElementById(STYLE_ID)) return;
  const style = document.createElement('style');
  style.id = STYLE_ID;
  style.textContent = '#notificationToast{visibility:hidden!important;pointer-events:none!important;}';
  document.head.appendChild(style);
}

function hideToastNow(){
  const toast = document.getElementById('notificationToast');
  toast?.classList.remove('show', 'dragging');
  if (toast) {
    toast.style.transform = '';
    toast.style.opacity = '';
  }
}

function isIncomingInviteSheetOpen(){
  const overlay = document.getElementById('sheetOverlay');
  if (!overlay?.classList.contains('active')) return false;
  return Boolean(document.querySelector('[data-invite-sheet][data-invite-state="pending:invitee"]'));
}

function rememberNotificationId(id){
  try {
    const parsed = JSON.parse(localStorage.getItem(ANNOUNCED_STORAGE_KEY) || '[]');
    const ids = Array.isArray(parsed) ? parsed.map(String).filter(Boolean) : [];
    const next = ids.filter(value => value !== id);
    next.push(id);
    localStorage.setItem(ANNOUNCED_STORAGE_KEY, JSON.stringify(next.slice(-MAX_ANNOUNCED_IDS)));
  } catch (error) {}
}

function incomingToken(){
  const startParam = String(window.Telegram?.WebApp?.initDataUnsafe?.start_param || '');
  const fromTelegram = startParam.startsWith('invite_') ? startParam.slice(7) : '';
  const fromQuery = new URLSearchParams(window.location.search).get('invite') || '';
  const value = String(fromTelegram || fromQuery).toLowerCase();
  return /^[a-f0-9]{24}$/.test(value) ? value : '';
}
