const TOKEN_PATTERN = /^[a-f0-9]{24}$/i;

export function readCanonicalLaunch(locationObject = window.location){
  const url = new URL(locationObject.href);
  const telegram = window.Telegram?.WebApp || null;
  const queryInvite = normalizeToken(url.searchParams.get('invite'));
  const startParam = String(telegram?.initDataUnsafe?.start_param || url.searchParams.get('startapp') || '').trim();
  const startInvite = startParam.startsWith('invite_')
    ? normalizeToken(startParam.slice('invite_'.length))
    : '';
  const inviteToken = queryInvite || startInvite;

  return Object.freeze({
    runtime:'mgw-clean-v1',
    path:url.pathname,
    inviteToken,
    source:inviteToken ? 'invite' : 'standard',
    telegramAvailable:Boolean(telegram),
    initDataPresent:Boolean(String(telegram?.initData || '')),
  });
}

function normalizeToken(value){
  const token = String(value || '').trim().toLowerCase();
  return TOKEN_PATTERN.test(token) ? token : '';
}
