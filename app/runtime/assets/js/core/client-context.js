export function readTelegramInitData(){
  return String(window.Telegram?.WebApp?.initData || '');
}

export function readPresenceContext(){
  const telegramPlatform = String(window.Telegram?.WebApp?.platform || '').trim().toLowerCase();
  const browserPlatform = String(navigator.userAgentData?.platform || navigator.platform || '').trim().toLowerCase();
  return Object.freeze({
    visibility:normalizeVisibility(document.visibilityState),
    platform:normalizePlatform(telegramPlatform || browserPlatform || 'unknown'),
    timezone_offset:new Date().getTimezoneOffset(),
  });
}

function normalizeVisibility(value){
  const state = String(value || '').toLowerCase();
  return ['visible', 'hidden', 'prerender'].includes(state) ? state : 'unknown';
}

function normalizePlatform(value){
  const platform = String(value || '').toLowerCase().replace(/[^a-z0-9_-]+/g, '_').slice(0, 32);
  return platform || 'unknown';
}
