export function getTelegram(){
  return window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;
}
export function initTelegramApp(){
  const tg = getTelegram();
  document.documentElement.style.backgroundColor = '#090c14';
  document.body.style.backgroundColor = '#090c14';
  if (!tg) return null;
  try {
    tg.ready();
    tg.expand();
    tg.disableVerticalSwipes?.();
    tg.setHeaderColor?.('#090c14');
    tg.setBackgroundColor?.('#090c14');
    tg.setBottomBarColor?.('#090c14');
  } catch(e) {}
  return tg;
}
export function getInitData(){ return getTelegram()?.initData || ''; }
function isAndroidShell(){
  return /(?:^|\s)MiniGamesWorldAndroid\/\d+(?:\s|$)/.test(String(navigator.userAgent || ''));
}

function androidHapticPattern(type){
  switch (String(type || 'light')) {
    case 'success': return [16, 34, 24];
    case 'warning': return [22, 28, 22];
    case 'error': return [30, 28, 30];
    case 'heavy': return 42;
    case 'medium': return 28;
    default: return 16;
  }
}

export function haptic(type = 'light'){
  // Startup preparation may intentionally render hidden Store/Profile surfaces
  // underneath the intro preloader. Those programmatic paints must never feel
  // like a user action on the phone, so suppress haptics until the app is visible.
  const preloader = document.getElementById('preloader');
  if (preloader instanceof HTMLElement && !preloader.classList.contains('hidden')) return;

  const tg = getTelegram();
  try {
    if (tg?.HapticFeedback) {
      if (['success','warning','error'].includes(String(type))) {
        tg.HapticFeedback.notificationOccurred?.(String(type));
      } else {
        tg.HapticFeedback.impactOccurred?.(String(type));
      }
      return;
    }
  } catch(e) {}

  // The Android shell deliberately exposes no privileged JavaScript bridge.
  // Chromium's standard vibration capability is enough for parity and remains
  // a no-op everywhere except the authenticated native Android container.
  if (!isAndroidShell() || typeof navigator.vibrate !== 'function') return;
  try { navigator.vibrate(androidHapticPattern(type)); } catch(e) {}
}
