const TAP_MOVE_TOLERANCE_PX = 18;
const TAP_MAX_DURATION_MS = 1600;
const COMPATIBILITY_CLICK_WINDOW_MS = 700;
const COMPATIBILITY_CLICK_DISTANCE_PX = 36;

let activePress = null;
let compatibilityClick = null;
let dispatchingFallbackClick = false;

initNotificationCompatibilityClickGuard();

function initNotificationCompatibilityClickGuard(){
  if (window.__MGW_NOTIFICATION_COMPAT_CLICK_GUARD_V127__) return;
  window.__MGW_NOTIFICATION_COMPAT_CLICK_GUARD_V127__ = true;

  // This guard is loaded before the canonical notification owner. It observes
  // the same physical press without replacing the owner. Telegram WebView can
  // retarget the generated click to the sheet overlay after pointerup opened
  // the sheet. The retargeted compatibility click must not close that sheet.
  window.addEventListener('pointerdown', rememberPress, true);
  window.addEventListener('pointerup', finishPress, true);
  window.addEventListener('pointercancel', cancelPress, true);
  window.addEventListener('click', suppressCompatibilityClick, true);
}

function rememberPress(event){
  const trigger = notificationTrigger(event.target);
  if (!trigger || !isPrimaryPointer(event)) return;

  activePress = {
    pointerId:event.pointerId,
    triggerId:trigger.id,
    startX:Number(event.clientX || 0),
    startY:Number(event.clientY || 0),
    startedAt:performance.now(),
  };
}

function finishPress(event){
  const press = activePress;
  activePress = null;
  if (!press || press.pointerId !== event.pointerId || !isPrimaryPointer(event)) return;

  const endX = Number(event.clientX || 0);
  const endY = Number(event.clientY || 0);
  const duration = performance.now() - press.startedAt;
  if (Math.hypot(endX - press.startX, endY - press.startY) > TAP_MOVE_TOLERANCE_PX) return;
  if (duration > TAP_MAX_DURATION_MS) return;

  compatibilityClick = {
    triggerId:press.triggerId,
    x:endX,
    y:endY,
    expiresAt:performance.now() + COMPATIBILITY_CLICK_WINDOW_MS,
  };

  // The canonical owner normally opens on pointerup. If a real Telegram
  // WebView changes the pointerup target, recover the same physical press with
  // one programmatic click after all pointerup listeners have finished.
  queueMicrotask(() => {
    if (isNotificationsSheetOpen()) return;
    const trigger = document.getElementById(press.triggerId);
    if (!(trigger instanceof HTMLElement)) return;

    dispatchingFallbackClick = true;
    try {
      trigger.click();
    } finally {
      dispatchingFallbackClick = false;
    }
  });
}

function cancelPress(event){
  if (activePress?.pointerId === event.pointerId) activePress = null;
}

function suppressCompatibilityClick(event){
  if (dispatchingFallbackClick) return;

  const guard = compatibilityClick;
  if (!guard) return;
  if (performance.now() > guard.expiresAt) {
    compatibilityClick = null;
    return;
  }
  if (Number(event.detail || 0) <= 0) return;

  const x = Number(event.clientX || 0);
  const y = Number(event.clientY || 0);
  const samePoint = Math.hypot(x - guard.x, y - guard.y) <= COMPATIBILITY_CLICK_DISTANCE_PX;
  const target = event.target instanceof Element ? event.target : null;
  const retargetedToOverlay = target?.id === 'sheetOverlay' || target?.closest?.('#sheetOverlay') === document.getElementById('sheetOverlay');
  const sameTrigger = notificationTrigger(target)?.id === guard.triggerId;
  if (!samePoint && !retargetedToOverlay && !sameTrigger) return;

  compatibilityClick = null;
  event.preventDefault();
  event.stopImmediatePropagation();
}

function notificationTrigger(target){
  const element = target instanceof Element
    ? target.closest('#notificationsOpen, #notificationToast')
    : null;
  return element instanceof HTMLElement ? element : null;
}

function isPrimaryPointer(event){
  if (event.isPrimary === false) return false;
  if (event.pointerType === 'mouse' && Number(event.button) !== 0) return false;
  return true;
}

function isNotificationsSheetOpen(){
  return document.getElementById('sheetOverlay')?.classList.contains('active')
    && String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').trim() === 'Уведомления';
}
