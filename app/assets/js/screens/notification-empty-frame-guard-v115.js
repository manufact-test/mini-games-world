const GUARD_MS = 420;
let guardUntil = 0;
let restoreTimer = null;
let observer = null;
let clickInstalled = false;

initNotificationEmptyFrameGuard();

function initNotificationEmptyFrameGuard(){
  if (!clickInstalled) {
    clickInstalled = true;
    document.addEventListener('click', event => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target?.closest('#notificationsOpen, #notificationToast')) return;
      guardUntil = performance.now() + GUARD_MS;
      window.clearTimeout(restoreTimer);
      queueMicrotask(guardTransientEmptyFrame);
    }, true);
  }

  const sheet = document.getElementById('sheet');
  if (!sheet) {
    document.addEventListener('DOMContentLoaded', initNotificationEmptyFrameGuard, { once:true });
    return;
  }
  if (observer) return;
  observer = new MutationObserver(guardTransientEmptyFrame);
  observer.observe(sheet, { childList:true, subtree:true });
}

function guardTransientEmptyFrame(){
  if (performance.now() >= guardUntil) return;
  if (sheetTitle() !== 'Уведомления') return;

  const empty = document.querySelector('#sheet .notifications-empty:not(.error)');
  if (!(empty instanceof HTMLElement)) return;
  if (String(empty.querySelector('strong')?.textContent || '').trim() !== 'Пока уведомлений нет') return;
  if (empty.dataset.mgwEmptyFrameGuard === 'active') return;

  const originalHtml = empty.innerHTML;
  empty.dataset.mgwEmptyFrameGuard = 'active';
  empty.classList.remove('notifications-empty');
  empty.classList.add('notifications-loading');
  empty.innerHTML = '<div>🔔</div><strong>Загружаем…</strong>';

  const remaining = Math.max(0, guardUntil - performance.now());
  restoreTimer = window.setTimeout(() => {
    if (!empty.isConnected || empty.dataset.mgwEmptyFrameGuard !== 'active') return;
    if (sheetTitle() !== 'Уведомления') return;
    empty.classList.remove('notifications-loading');
    empty.classList.add('notifications-empty');
    empty.innerHTML = originalHtml;
    delete empty.dataset.mgwEmptyFrameGuard;
  }, remaining);
}

function sheetTitle(){
  return String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').trim();
}
