from pathlib import Path


def keep_first(text: str, block: str, label: str) -> str:
    first = text.find(block)
    if first < 0:
        raise RuntimeError(f'Missing expected R9 block: {label}')
    search_from = first + len(block)
    while True:
        duplicate = text.find(block, search_from)
        if duplicate < 0:
            break
        text = text[:duplicate] + text[duplicate + len(block):]
    if text.count(block) != 1:
        raise RuntimeError(f'Could not normalize R9 block: {label}')
    return text


def collapse_repeated_lines(text: str, line: str, label: str) -> str:
    while line + line in text:
        text = text.replace(line + line, line)
    if text.count(line) != 1:
        raise RuntimeError(f'Could not normalize R9 line: {label}; count={text.count(line)}')
    return text


invites_path = Path('app/assets/js/games/game-invites-v110.js')
invites = invites_path.read_text()

invites = collapse_repeated_lines(
    invites,
    '    scheduleVisibleShareWarm(0);\n',
    'app-ready visible share warm',
)
invites = collapse_repeated_lines(
    invites,
    "  const roomButton = event.target.closest('[data-room]');\n  if (roomButton) window.setTimeout(() => scheduleVisibleShareWarm(0), 0);\n\n",
    'room share warm',
)

visibility_block = """function initShareVisibilityPrewarm(){
  if (shareVisibilityObserver || typeof IntersectionObserver !== 'function') return;
  shareVisibilityObserver = new IntersectionObserver(entries => {
    if (!entries.some(entry => entry.isIntersecting)) return;
    scheduleVisibleShareWarm(40);
  }, { root:null, rootMargin:SHARE_PREFETCH_ROOT_MARGIN, threshold:[0.01, 0.35] });
  document.querySelectorAll('[data-invite-friend]').forEach(trigger => shareVisibilityObserver.observe(trigger));
}

function scheduleVisibleShareWarm(delay = 0){
  window.clearTimeout(shareVisibleWarmTimer);
  shareVisibleWarmTimer = window.setTimeout(() => {
    if (!appReady || shareAttempt?.nativePending || hasActionableInvite()) return;
    const trigger = nearestVisibleInviteTrigger();
    if (!trigger) return;
    scheduleWarmShareDraft(defaultInviteContext(String(trigger.dataset.inviteFriend || 'tictactoe')), 0);
  }, Math.max(0, Number(delay || 0)));
}

function nearestVisibleInviteTrigger(){
  const viewportCenter = window.innerHeight / 2;
  return [...document.querySelectorAll('[data-invite-friend]')]
    .filter(trigger => {
      const rect = trigger.getBoundingClientRect();
      return rect.bottom >= -240 && rect.top <= window.innerHeight + 240;
    })
    .sort((a, b) => {
      const aRect = a.getBoundingClientRect();
      const bRect = b.getBoundingClientRect();
      const aDistance = Math.abs((aRect.top + aRect.bottom) / 2 - viewportCenter);
      const bDistance = Math.abs((bRect.top + bRect.bottom) / 2 - viewportCenter);
      return aDistance - bDistance;
    })[0] || null;
}
"""
invites = keep_first(invites, visibility_block, 'visibility prewarm functions')

warm_helpers = """function armWarmShareExpiry(entry){
  window.clearTimeout(shareWarmExpiryTimer);
  shareWarmExpiryTimer = window.setTimeout(() => {
    if (shareWarm?.id !== entry?.id || shareAttempt?.nativePending) return;
    const stale = shareWarm;
    shareWarm = null;
    shareWarmExpiryTimer = null;
    if (stale?.status === 'ready' && stale.result?.invite?.token) void discardDraft(stale.result.invite);
  }, SHARE_WARM_KEEPALIVE_MS);
}

function restoreWarmShareDraft(attempt){
  const invite = cloneInvite(attempt?.invite);
  const token = String(invite?.token || '');
  const preparedId = String(invite?.prepared_message_id || '');
  if (!token || !preparedId) {
    scheduleWarmShareDraft(attempt?.context || defaultInviteContext('tictactoe'), 0);
    return;
  }

  const context = normalizeInviteContext(attempt.context);
  const result = { invite };
  const entry = {
    id:++shareWarmSequence,
    key:inviteContextKey(context),
    context,
    status:'ready',
    result,
    promise:Promise.resolve(result),
  };
  shareWarm = entry;
  armWarmShareExpiry(entry);
}
"""
invites = keep_first(invites, warm_helpers, 'warm draft helpers')

for symbol in [
    'function initShareVisibilityPrewarm(){',
    'function scheduleVisibleShareWarm(delay = 0){',
    'function nearestVisibleInviteTrigger(){',
    'function armWarmShareExpiry(entry){',
    'function restoreWarmShareDraft(attempt){',
]:
    if invites.count(symbol) != 1:
        raise RuntimeError(f'Duplicate JS owner remains: {symbol} count={invites.count(symbol)}')

invites_path.write_text(invites)

notifications_path = Path('app/assets/js/screens/notifications-screen-v110r5.js')
notifications = notifications_path.read_text()
storage_constants = """const LIVE_STORAGE_KEY_PREFIX = 'mgw_notification_live_items_v1';
const LIVE_STORAGE_TTL_MS = 900000;
"""
notifications = keep_first(notifications, storage_constants, 'notification cache constants')

storage_helpers = """function notificationCacheKey(){
  let scope = String(getSessionId() || 'anonymous');
  try {
    const rawUser = new URLSearchParams(getInitData()).get('user');
    const user = rawUser ? JSON.parse(rawUser) : null;
    if (user?.id) scope = String(user.id);
  } catch (error) {}
  return `${LIVE_STORAGE_KEY_PREFIX}:${scope}`;
}

function loadLiveItems(){
  try {
    const parsed = JSON.parse(localStorage.getItem(notificationCacheKey()) || 'null');
    if (!parsed || Date.now() - Number(parsed.saved_at || 0) > LIVE_STORAGE_TTL_MS) return new Map();
    const items = Array.isArray(parsed.items) ? parsed.items : [];
    return new Map(
      items
        .slice(0, MAX_LIVE_ITEMS)
        .map(cloneItem)
        .filter(item => String(item?.id || '') !== '')
        .map(item => [String(item.id), item])
    );
  } catch (error) {
    return new Map();
  }
}

function persistLiveItems(){
  try {
    localStorage.setItem(notificationCacheKey(), JSON.stringify({
      saved_at:Date.now(),
      items:currentItems(MAX_LIVE_ITEMS),
    }));
  } catch (error) {}
}
"""
notifications = keep_first(notifications, storage_helpers, 'notification cache helpers')

for symbol in [
    "const LIVE_STORAGE_KEY_PREFIX = 'mgw_notification_live_items_v1';",
    'function notificationCacheKey(){',
    'function loadLiveItems(){',
    'function persistLiveItems(){',
]:
    if notifications.count(symbol) != 1:
        raise RuntimeError(f'Duplicate notification owner remains: {symbol} count={notifications.count(symbol)}')

notifications_path.write_text(notifications)
