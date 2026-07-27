export function cacheDisposition(ageMs, ttlMs, maxStaleMs){
  const age = Math.max(0, Number(ageMs || 0));
  const ttl = Math.max(0, Number(ttlMs || 0));
  const maxStale = Math.max(ttl, Number(maxStaleMs || ttl));
  if (age <= ttl) return 'fresh';
  if (age <= maxStale) return 'stale';
  return 'miss';
}

export function mergeNotificationSnapshot(snapshot, item, unreadCount){
  const base = snapshot && typeof snapshot === 'object' ? clone(snapshot) : { ok:true, items:[], unread_count:0 };
  const items = Array.isArray(base.items) ? base.items : [];
  const id = String(item?.id || '');
  const previous = id ? items.find(entry => String(entry?.id || '') === id) : null;
  const incoming = id ? enrichNotification({ ...(previous || {}), ...clone(item) }) : null;
  const next = id
    ? [incoming, ...items.filter(entry => String(entry?.id || '') !== id)]
    : items;
  base.items = next.slice(0, 30);
  if (Number.isFinite(Number(unreadCount))) base.unread_count = Math.max(0, Math.trunc(Number(unreadCount)));
  return base;
}

export function optimisticReadNotifications(snapshot){
  const next = snapshot && typeof snapshot === 'object' ? clone(snapshot) : { ok:true, items:[], unread_count:0 };
  next.unread_count = 0;
  next.items = (Array.isArray(next.items) ? next.items : []).map(item => ({ ...item, read:true }));
  return next;
}

export function requestPriority(pathname, action, markRead = false){
  const path = String(pathname || '');
  const type = String(action || '');
  if (path.endsWith('/bot/api.php') && ['game_action','make_move','start_search','leave_search','leave_game'].includes(type)) return 'high';
  if (path.endsWith('/bot/invites.php') && ['accept','start','rematch','create_direct','confirm_shared'].includes(type)) return 'high';
  if (path.endsWith('/bot/notifications.php') && markRead) return 'high';
  if (path.endsWith('/bot/api.php') && ['stats','profile','weekly_match_status','shop_status','game_state'].includes(type)) return 'low';
  if (path.endsWith('/bot/notifications.php') || path.endsWith('/bot/shop-history.php') || path.endsWith('/bot/invite-opponents.php')) return 'low';
  return 'auto';
}

export function inviteContextKey(context){
  const value = context && typeof context === 'object' ? context : {};
  return [
    String(value.gameType || 'tictactoe'),
    String(value.room || 'match') === 'gold' ? 'gold' : 'match',
    Number(value.boardSize || 3),
    Number(value.bet || 10),
  ].join(':');
}

export function stableHash(value){
  const input = String(value || '');
  let hash = 2166136261;
  for (let index = 0; index < input.length; index++) {
    hash ^= input.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }
  return (hash >>> 0).toString(36);
}

function enrichNotification(item){
  const next = item && typeof item === 'object' ? { ...item } : {};
  if (Array.isArray(next.actions) && next.actions.length) return next;
  const type = String(next.type || '');
  if (['invite_received','invite_rematch_received'].includes(type)) next.actions = ['accept','decline'];
  else if (type === 'invite_accepted') next.actions = ['start','cancel'];
  return next;
}

function clone(value){
  if (value === undefined) return undefined;
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}
