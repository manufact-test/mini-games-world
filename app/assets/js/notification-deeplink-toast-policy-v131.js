const STYLE_ID = 'mgw-deeplink-toast-policy-v131';
const RELEASE_TIMEOUT_MS = 15_000;
const SHEET_CHECK_INTERVAL_MS = 50;

const token = incomingToken();
if (token) installDeepLinkToastPolicy();

function installDeepLinkToastPolicy(){
  window.__MGW_INVITE_LINK_OPENING__ = true;
  installHideStyle();

  document.addEventListener('mgw:notification-sync', event => {
    if (event.detail?.announce !== false) return;
    hideToastNow();
    // Do not stop propagation: passive-v130 must receive announce:false,
    // clear a matching pending notification and remember its id.
  }, true);

  const interval = window.setInterval(() => {
    hideToastNow();
    if (isIncomingInviteSheetOpen()) release();
  }, SHEET_CHECK_INTERVAL_MS);
  const timeout = window.setTimeout(release, RELEASE_TIMEOUT_MS);

  function release(){
    window.clearInterval(interval);
    window.clearTimeout(timeout);
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
  if (!toast) return;
  toast.classList.remove('show', 'dragging');
  if (toast.style.transform) toast.style.transform = '';
  if (toast.style.opacity) toast.style.opacity = '';
}

function isIncomingInviteSheetOpen(){
  const overlay = document.getElementById('sheetOverlay');
  if (!overlay?.classList.contains('active')) return false;
  return Boolean(document.querySelector('[data-invite-sheet][data-invite-state="pending:invitee"]'));
}

function incomingToken(){
  const startParam = String(window.Telegram?.WebApp?.initDataUnsafe?.start_param || '');
  const fromTelegram = startParam.startsWith('invite_') ? startParam.slice(7) : '';
  const fromQuery = new URLSearchParams(window.location.search).get('invite') || '';
  const value = String(fromTelegram || fromQuery).toLowerCase();
  return /^[a-f0-9]{24}$/.test(value) ? value : '';
}
