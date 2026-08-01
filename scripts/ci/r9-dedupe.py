from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text()
    if old not in text:
        if new in text:
            return
        raise RuntimeError(f'Expected duplicate block not found in {path}: {old[:120]!r}')
    file.write_text(text.replace(old, new, 1))


invites = 'app/assets/js/games/game-invites-v110.js'
replace_once(
    invites,
    "    scheduleVisibleShareWarm(0);\n    scheduleVisibleShareWarm(0);",
    "    scheduleVisibleShareWarm(0);",
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
replace_once(invites, visibility_block + "\n" + visibility_block, visibility_block)

room_block = """  const roomButton = event.target.closest('[data-room]');
  if (roomButton) window.setTimeout(() => scheduleVisibleShareWarm(0), 0);
"""
replace_once(invites, room_block + "\n" + room_block, room_block)

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
replace_once(invites, warm_helpers + "\n" + warm_helpers, warm_helpers)

notifications = 'app/assets/js/screens/notifications-screen-v110r5.js'
storage_constants = """const LIVE_STORAGE_KEY_PREFIX = 'mgw_notification_live_items_v1';
const LIVE_STORAGE_TTL_MS = 900000;
"""
replace_once(notifications, storage_constants + storage_constants, storage_constants)

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
replace_once(notifications, storage_helpers + "\n" + storage_helpers, storage_helpers)
