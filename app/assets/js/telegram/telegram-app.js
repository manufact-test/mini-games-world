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
  try { getTelegram()?.HapticFeedback?.impactOccurred?.(type); } catch(e) {}
}
