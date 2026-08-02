export function createInviteControllerState(entryToken = '') {
  return {
    entryToken:String(entryToken || ''),
    entryPending:Boolean(entryToken),
    entryResolved:!entryToken,
    activeInvite:null,
    entryInvite:null,
    notifications:new Map(),
    localAuthority:new Map(),
    announcedIds:new Set(),
    suppressedToastTokens:new Set(entryToken ? [String(entryToken)] : []),
    requestIssued:{ invites:0, notifications:0 },
    requestApplied:{ invites:0, notifications:0 },
    notificationsLoaded:false,
    unreadCount:0,
  };
}

export function beginControllerRequest(state, channel) {
  const key = normalizeChannel(channel);
  state.requestIssued[key] += 1;
  return Object.freeze({ channel:key, sequence:state.requestIssued[key] });
}

export function canApplyControllerResponse(state, ticket) {
  const key = normalizeChannel(ticket?.channel);
  const sequence = Number(ticket?.sequence || 0);
  if (!sequence || sequence < state.requestApplied[key]) return false;
  state.requestApplied[key] = sequence;
  return true;
}

export function applyInviteSnapshot(state, result, { announce = false } = {}) {
  const active = selectActiveInvite(result);
  state.activeInvite = active;
  const events = normalizeItems(result?.invite_events);
  const fresh = [];

  for (const item of events) {
    const previous = state.notifications.get(item.id) || {};
    const merged = normalizeNotification({ ...previous, ...item });
    state.notifications.set(merged.id, merged);
    rememberLocalAuthority(state, merged);
    if (announce && shouldAnnounceNotification(state, merged)) fresh.push(cloneValue(merged));
  }

  if (Number.isFinite(Number(result?.unread_count))) {
    state.unreadCount = Math.max(0, Number(result.unread_count));
  }

  return { activeInvite:cloneValue(active), fresh };
}

export function applyNotificationSnapshot(state, result, { announce = false } = {}) {
  const serverItems = normalizeItems(result?.items);
  pruneLocalAuthority(state);
  const next = new Map();

  for (const item of serverItems) {
    const previous = state.notifications.get(item.id) || equivalentNotification(state, item) || {};
    const merged = normalizeNotification({ ...previous, ...item });
    next.set(merged.id, merged);
  }

  // Fresh invite events received from the invite channel are locally authoritative
  // until the notification endpoint has caught up. Do not let an older list erase them.
  for (const entry of state.localAuthority.values()) {
    const item = normalizeNotification(entry?.item);
    if (!isInviteNotification(item)) continue;
    if (next.has(item.id) || equivalentNotificationMap(next, item)) continue;
    next.set(item.id, cloneValue(item));
  }

  state.notifications = next;
  state.notificationsLoaded = true;
  if (Number.isFinite(Number(result?.unread_count))) {
    state.unreadCount = Math.max(0, Number(result.unread_count));
  }

  const fresh = announce
    ? sortedNotifications(state).filter(item => shouldAnnounceNotification(state, item))
    : [];
  return { fresh };
}

export function beginEntryResolution(state) {
  state.entryPending = Boolean(state.entryToken);
  state.entryResolved = !state.entryToken;
  if (state.entryToken) state.suppressedToastTokens.add(state.entryToken);
}

export function applyEntrySnapshot(state, result) {
  const invite = normalizeInvite(result?.opened_invite);
  state.entryInvite = isReceivedPendingInvite(invite) ? invite : null;
  state.entryPending = false;
  state.entryResolved = true;
  applyInviteSnapshot(state, result, { announce:false });
  if (invite?.token) state.suppressedToastTokens.add(String(invite.token));
  return cloneValue(state.entryInvite);
}

export function failEntryResolution(state) {
  state.entryPending = false;
  state.entryResolved = true;
  state.entryInvite = null;
}

export function shouldStartBackgroundLoops(state) {
  return !state.entryPending && state.entryResolved;
}

export function shouldAnnounceNotification(state, value) {
  const item = normalizeNotification(value);
  if (!item.id || item.read || state.announcedIds.has(item.id)) return false;
  if (item.invite_token && state.suppressedToastTokens.has(item.invite_token)) return false;
  return true;
}

export function markNotificationAnnounced(state, id) {
  const value = String(id || '');
  if (value) state.announcedIds.add(value);
}

export function removeInviteNotifications(state, token) {
  const target = String(token || '');
  if (!target) return;
  for (const [id, item] of state.notifications.entries()) {
    if (String(item?.invite_token || '') === target) state.notifications.delete(id);
  }
  for (const [key, entry] of state.localAuthority.entries()) {
    if (String(entry?.item?.invite_token || '') === target) state.localAuthority.delete(key);
  }
  if (String(state.entryInvite?.token || '') === target) state.entryInvite = null;
  if (String(state.activeInvite?.token || '') === target) state.activeInvite = null;
  state.unreadCount = Math.max(0, state.unreadCount - 1);
}

export function upsertNotification(state, value) {
  const item = normalizeNotification(value);
  if (!item.id) return null;
  const equivalent = equivalentNotification(state, item);
  if (equivalent?.id && equivalent.id !== item.id) state.notifications.delete(equivalent.id);
  const previous = state.notifications.get(item.id) || equivalent || {};
  const merged = normalizeNotification({ ...previous, ...item });
  state.notifications.set(merged.id, merged);
  rememberLocalAuthority(state, merged);
  return cloneValue(merged);
}

export function sortedNotifications(state, limit = 40) {
  return [...state.notifications.values()]
    .map(normalizeNotification)
    .filter(item => item.id)
    .sort((a, b) => itemTime(b) - itemTime(a) || b.id.localeCompare(a.id))
    .slice(0, Math.max(0, Number(limit || 0)))
    .map(cloneValue);
}

export function findNotificationByToken(state, token) {
  const target = String(token || '');
  if (!target) return null;
  return sortedNotifications(state).find(item => String(item.invite_token || '') === target) || null;
}

export function isReceivedPendingInvite(value) {
  const invite = normalizeInvite(value);
  return Boolean(
    invite?.token
      && String(invite.status || '') === 'pending'
      && invite.is_invitee
      && !invite.is_owner
  );
}

export function isActionableActiveInvite(value) {
  const invite = normalizeInvite(value);
  if (!invite?.token) return false;
  if (isReceivedPendingInvite(invite)) return false;
  return ['pending', 'accepted'].includes(String(invite.status || ''));
}

export function normalizeNotification(value) {
  if (!value || typeof value !== 'object') return emptyNotification();
  const item = {
    ...cloneValue(value),
    id:String(value.id || ''),
    type:String(value.type || ''),
    title:String(value.title || ''),
    message:String(value.message || ''),
    tone:String(value.tone || 'info'),
    invite_token:String(value.invite_token || ''),
    invite_status:String(value.invite_status || ''),
    invite_is_owner:Boolean(value.invite_is_owner),
    actions:Array.isArray(value.actions) ? value.actions.map(String) : [],
    created_at:String(value.created_at || ''),
    read:Boolean(value.read),
  };
  item.actions = completeInviteActions(item);
  return item;
}

export function normalizeInvite(value) {
  if (!value || typeof value !== 'object') return null;
  const invite = cloneValue(value);
  invite.token = String(value.token || '');
  invite.status = String(value.status || '');
  invite.is_owner = Boolean(value.is_owner);
  invite.is_invitee = Boolean(value.is_invitee);
  return invite.token ? invite : null;
}

function selectActiveInvite(result) {
  const active = normalizeInvite(result?.invite);
  if (isActionableActiveInvite(active)) return active;
  const tracked = normalizeInvite(result?.tracked_invite);
  if (isActionableActiveInvite(tracked)) return tracked;
  return null;
}

function completeInviteActions(item) {
  if (Array.isArray(item.actions) && item.actions.length) return item.actions;
  if (!item.invite_token) return [];
  const status = String(item.invite_status || '');
  const type = String(item.type || '');
  if (status === 'pending' && !item.invite_is_owner
      && ['invite_received', 'invite_rematch_received'].includes(type)) {
    return ['accept', 'decline'];
  }
  if (status === 'accepted') return item.invite_is_owner ? ['start', 'cancel'] : ['cancel'];
  return [];
}

function rememberLocalAuthority(state, item) {
  if (!isInviteNotification(item)) return;
  state.localAuthority.set(notificationIdentity(item) || item.id, {
    item:cloneValue(item),
    expiresAt:Date.now() + 12000,
  });
}

function pruneLocalAuthority(state) {
  const now = Date.now();
  for (const [key, entry] of state.localAuthority.entries()) {
    if (!entry || Number(entry.expiresAt || 0) <= now) state.localAuthority.delete(key);
  }
}

function equivalentNotification(state, item) {
  const identity = notificationIdentity(item);
  if (!identity) return null;
  for (const value of state.notifications.values()) {
    if (notificationIdentity(value) === identity) return value;
  }
  return null;
}

function equivalentNotificationMap(map, item) {
  const identity = notificationIdentity(item);
  if (!identity) return null;
  for (const value of map.values()) {
    if (notificationIdentity(value) === identity) return value;
  }
  return null;
}

function notificationIdentity(item) {
  const token = String(item?.invite_token || '');
  const type = String(item?.type || '');
  if (token && type.startsWith('invite_')) return `${token}|${type}`;
  return String(item?.id || '');
}

function isInviteNotification(item) {
  return String(item?.type || '').startsWith('invite_') && Boolean(item?.invite_token);
}

function normalizeItems(values) {
  return (Array.isArray(values) ? values : []).map(normalizeNotification).filter(item => item.id);
}

function normalizeChannel(channel) {
  return String(channel || '') === 'notifications' ? 'notifications' : 'invites';
}

function itemTime(item) {
  const value = Date.parse(String(item?.created_at || ''));
  return Number.isFinite(value) ? value : 0;
}

function emptyNotification() {
  return {
    id:'', type:'', title:'', message:'', tone:'info', invite_token:'', invite_status:'',
    invite_is_owner:false, actions:[], created_at:'', read:false,
  };
}

function cloneValue(value) {
  if (!value || typeof value !== 'object') return value;
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}
