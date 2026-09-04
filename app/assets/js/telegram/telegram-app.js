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
export function haptic(type = 'light'){
  // Startup preparation may intentionally render hidden Store/Profile surfaces
  // underneath the intro preloader. Those programmatic paints must never feel
  // like a user action on the phone, so suppress haptics until the app is visible.
  const preloader = document.getElementById('preloader');
  if (preloader instanceof HTMLElement && !preloader.classList.contains('hidden')) return;
  try { getTelegram()?.HapticFeedback?.impactOccurred?.(type); } catch(e) {}
}
