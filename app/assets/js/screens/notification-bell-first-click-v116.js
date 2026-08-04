import { closeSheet } from '../components/sheet.js?v=68';

const RETRY_DELAYS_MS = [180, 520];
const STALE_REOPEN_BLOCK_MS = 1200;
let attemptGeneration = 0;
let retryTimers = [];
let initialized = false;
let observer = null;
let notificationsWereOpen = false;
let blockAutomaticReopenUntil = 0;

initBellFirstClickGuard();

function initBellFirstClickGuard(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;

    if (isNotificationCloseGesture(target)) {
      blockAutomaticReopen();
      return;
    }

    const bell = target?.closest('#notificationsOpen');
    if (!(bell instanceof HTMLElement) || !event.isTrusted) return;
    if (isAnySheetOpen()) return;

    blockAutomaticReopenUntil = 0;
    startBoundedRetry(bell);
  }, true);

  document.addEventListener('mgw:sheet-closed', () => {
    if (notificationsWereOpen) blockAutomaticReopen();
    notificationsWereOpen = false;
    cancelAttempt();
  });

  installOverlayObserver();
}

function startBoundedRetry(bell){
  cancelAttempt();
  const generation = ++attemptGeneration;

  retryTimers = RETRY_DELAYS_MS.map(delay => window.setTimeout(() => {
    if (generation !== attemptGeneration) return;
    if (document.visibilityState !== 'visible') return cancelAttempt();
    if (isAnySheetOpen()) return cancelAttempt();
    if (performance.now() < blockAutomaticReopenUntil) return cancelAttempt();

    // The canonical notification owner receives the synthetic click. This guard
    // never renders a sheet and retries only within the bounded first-click window.
    bell.click();
  }, delay));
}

function installOverlayObserver(){
  const overlay = document.getElementById('sheetOverlay');
  if (!overlay) {
    document.addEventListener('DOMContentLoaded', installOverlayObserver, { once:true });
    return;
  }
  if (observer) return;

  observer = new MutationObserver(() => {
    const active = overlay.classList.contains('active');
    const notificationsOpen = active && sheetTitle() === 'Уведомления';

    if (!active) {
      notificationsWereOpen = false;
      return;
    }
    if (!notificationsOpen) {
      cancelAttempt();
      return;
    }

    notificationsWereOpen = true;
    if (performance.now() < blockAutomaticReopenUntil) {
      // A request that started before manual close may finish later and call
      // openSheet again. Close that stale repaint instead of reopening the UI.
      closeSheet();
      return;
    }

    cancelAttempt();
  });
  observer.observe(overlay, { attributes:true, attributeFilter:['class'] });
}

function blockAutomaticReopen(){
  blockAutomaticReopenUntil = performance.now() + STALE_REOPEN_BLOCK_MS;
  cancelAttempt();
}

function cancelAttempt(){
  attemptGeneration += 1;
  retryTimers.forEach(timer => window.clearTimeout(timer));
  retryTimers = [];
}

function isNotificationCloseGesture(target){
  if (!isNotificationsSheetOpen()) return false;
  return Boolean(target?.closest('[data-close-sheet]') || target?.id === 'sheetOverlay');
}

function isAnySheetOpen(){
  return Boolean(document.getElementById('sheetOverlay')?.classList.contains('active'));
}

function isNotificationsSheetOpen(){
  return isAnySheetOpen() && sheetTitle() === 'Уведомления';
}

function sheetTitle(){
  return String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').trim();
}
