const RETRY_GAP_MS = 90;
const RETRY_LIMIT_MS = 1400;
let attemptGeneration = 0;
let retryTimer = null;
let initialized = false;

initBellFirstClickGuard();

function initBellFirstClickGuard(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;

    if (target?.closest('[data-close-sheet]')) {
      cancelAttempt();
      return;
    }

    const bell = target?.closest('#notificationsOpen');
    if (!(bell instanceof HTMLElement) || !event.isTrusted) return;
    if (isAnySheetOpen()) return;

    const generation = ++attemptGeneration;
    const startedAt = performance.now();
    window.clearTimeout(retryTimer);
    retryTimer = window.setTimeout(
      () => ensureBellOpened(generation, startedAt, bell),
      RETRY_GAP_MS
    );
  }, true);
}

function ensureBellOpened(generation, startedAt, bell){
  if (generation !== attemptGeneration) return;
  if (document.visibilityState !== 'visible') return cancelAttempt();

  if (isNotificationsSheetOpen()) return cancelAttempt();
  if (isAnySheetOpen()) return cancelAttempt();
  if (performance.now() - startedAt >= RETRY_LIMIT_MS) return cancelAttempt();

  bell.click();
  retryTimer = window.setTimeout(
    () => ensureBellOpened(generation, startedAt, bell),
    RETRY_GAP_MS
  );
}

function cancelAttempt(){
  attemptGeneration += 1;
  window.clearTimeout(retryTimer);
  retryTimer = null;
}

function isAnySheetOpen(){
  return Boolean(document.getElementById('sheetOverlay')?.classList.contains('active'));
}

function isNotificationsSheetOpen(){
  if (!isAnySheetOpen()) return false;
  return String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').trim() === 'Уведомления';
}
