import { getInitData } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import {
  cacheDisposition,
  mergeNotificationSnapshot,
  optimisticReadNotifications,
  requestPriority,
  stableHash,
} from './production-v101-speed-models.js?v=101';

const CACHE_RULES = {
  stats:{ ttl:1500, maxStale:5000 },
  profile:{ ttl:15000, maxStale:60000 },
  weekly_match_status:{ ttl:30000, maxStale:120000 },
  shop_status:{ ttl:20000, maxStale:60000 },
  shop_orders:{ ttl:12000, maxStale:30000 },
  notifications:{ ttl:2500, maxStale:7000 },
  invite_opponents:{ ttl:15000, maxStale:30000 },
};

const runtime = window.__MGW_V101_SPEED__ ||= {
  initialized:false,
  rawFetch:null,
  cache:new Map(),
  inFlight:new Map(),
  gamePollControllers:new Set(),
  backgroundControllers:new Set(),
  markReadInFlight:null,
  prefetchScheduled:false,
  currentScope:'anonymous',
  metrics:[],
};

export function initV101SpeedRuntime(){
  if (runtime.initialized) return;
  runtime.initialized = true;
  runtime.rawFetch = window.fetch.bind(window);
  window.fetch = acceleratedFetch;

  document.addEventListener('pointerdown', event => {
    const origin = event.target;
    if (!(origin instanceof Element) || !origin.closest('#gameBoard button')) return;
    abortTracked(runtime.gamePollControllers);
    abortTracked(runtime.backgroundControllers);
  }, true);

  document.addEventListener('mgw:app-ready', () => schedulePassivePrefetch(180), { once:true });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') schedulePassivePrefetch(250);
  });
  document.addEventListener('mgw:game-finished', () => {
    invalidateCurrent(['stats','profile','weekly_match_status','shop_status']);
    schedulePassivePrefetch(220);
  });
  document.addEventListener('mgw:notification-sync', event => {
    mergeNotificationCache(event.detail?.item || null, Number(event.detail?.unreadCount || 0));
  });
  document.addEventListener('mgw:notification-count', event => {
    updateNotificationCount(Number(event.detail?.unreadCount || 0));
  });

  document.addEventListener('pointerdown', event => {
    const origin = event.target;
    if (!(origin instanceof Element) || !origin.closest('[data-invite-friend], [data-open-player-picker]')) return;
    scheduleOpponentPrefetch(20);
  }, true);
}

export function peekV101CachedJson(id, maxAgeMs = Infinity){
  const entry = runtime.cache.get(cacheKey(runtime.currentScope, id));
  if (!entry || Date.now() - entry.storedAt > Number(maxAgeMs)) return null;
  return parseSnapshot(entry.snapshot);
}

export function invalidateV101Cache(ids = []){
  invalidateCurrent(ids);
}

async function acceleratedFetch(input, init = {}){
  const meta = requestMeta(input, init);
  if (!meta.sameOrigin || meta.method !== 'POST' || !meta.body) {
    return runtime.rawFetch(input, cleanInit(init));
  }

  runtime.currentScope = meta.scope;
  const priority = requestPriority(meta.pathname, meta.action, meta.markRead);

  if (isGameAction(meta)) {
    abortTracked(runtime.gamePollControllers);
    abortTracked(runtime.backgroundControllers);
    invalidateForMutation(meta);
    return trackedGameFetch(input, init, meta, priority);
  }

  if (isGamePoll(meta)) {
    return trackedGameFetch(input, init, meta, priority);
  }

  if (isBootstrap(meta)) {
    const snapshot = await fetchSnapshot(input, init, meta, priority, null);
    const data = parseSnapshot(snapshot);
    if (data) seedBootstrapCaches(meta.scope, data);
    return responseFromSnapshot(snapshot);
  }

  const descriptor = cacheDescriptor(meta);
  if (descriptor) {
    if (descriptor.id === 'notifications' && meta.markRead) {
      return optimisticNotificationRead(input, init, meta, priority);
    }
    return cachedFetch(input, init, meta, descriptor, priority);
  }

  invalidateForMutation(meta);
  return trackedPlainFetch(input, init, meta, priority);
}

async function trackedGameFetch(input, init, meta, priority){
  const startedAt = performance.now();
  const set = isGamePoll(meta) ? runtime.gamePollControllers : null;
  try {
    const snapshot = await fetchSnapshot(input, init, meta, priority, set);
    const data = parseSnapshot(snapshot);
    if (data?.game && String(data.game.status || '') === 'finished') {
      document.dispatchEvent(new CustomEvent('mgw:v101-finished-response', {
        detail:{ game:data.game, me:data.me || null, source:meta.action },
      }));
    }
    rememberMetric(meta, startedAt, false);
    return responseFromSnapshot(snapshot);
  } catch (error) {
    rememberMetric(meta, startedAt, true);
    if (isAbort(error)) throw quietAbort();
    throw error;
  }
}

async function trackedPlainFetch(input, init, meta, priority){
  const background = isBackgroundSafe(meta) ? runtime.backgroundControllers : null;
  try {
    return await fetchWithController(input, init, priority, background);
  } catch (error) {
    if (isAbort(error)) throw quietAbort();
    throw error;
  }
}

async function cachedFetch(input, init, meta, descriptor, priority){
  const key = cacheKey(meta.scope, descriptor.id);
  const cached = runtime.cache.get(key);
  if (cached) {
    const disposition = cacheDisposition(Date.now() - cached.storedAt, descriptor.ttl, descriptor.maxStale);
    if (disposition === 'fresh') return responseFromSnapshot(cached.snapshot);
    if (disposition === 'stale') {
      refreshCacheInBackground(input, init, meta, descriptor, priority, key);
      return responseFromSnapshot(cached.snapshot);
    }
  }

  if (runtime.inFlight.has(key)) {
    const snapshot = await runtime.inFlight.get(key);
    return responseFromSnapshot(snapshot);
  }

  const promise = fetchSnapshot(
    input,
    init,
    meta,
    priority,
    meta.prefetch || isBackgroundSafe(meta) ? runtime.backgroundControllers : null,
  ).then(snapshot => {
    if (snapshot.ok) rememberCache(key, descriptor.id, snapshot);
    return snapshot;
  }).finally(() => runtime.inFlight.delete(key));
  runtime.inFlight.set(key, promise);

  try {
    return responseFromSnapshot(await promise);
  } catch (error) {
    if (isAbort(error)) throw quietAbort();
    throw error;
  }
}

function refreshCacheInBackground(input, init, meta, descriptor, priority, key){
  if (runtime.inFlight.has(key)) return;
  const promise = fetchSnapshot(input, { ...init, mgwPrefetch:true }, meta, priority, runtime.backgroundControllers)
    .then(snapshot => {
      if (snapshot.ok) rememberCache(key, descriptor.id, snapshot);
      return snapshot;
    })
    .catch(() => null)
    .finally(() => runtime.inFlight.delete(key));
  runtime.inFlight.set(key, promise);
}

async function optimisticNotificationRead(input, init, meta, priority){
  const key = cacheKey(meta.scope, 'notifications');
  const cached = runtime.cache.get(key);
  if (!cached) {
    const snapshot = await fetchSnapshot(input, init, meta, priority, null);
    if (snapshot.ok) rememberCache(key, 'notifications', snapshot);
    return responseFromSnapshot(snapshot);
  }

  const optimisticData = optimisticReadNotifications(parseSnapshot(cached.snapshot));
  const optimisticSnapshot = snapshotFromJson(optimisticData, 200, 'OK');
  rememberCache(key, 'notifications', optimisticSnapshot);

  if (!runtime.markReadInFlight) {
    runtime.markReadInFlight = fetchSnapshot(input, init, meta, priority, null)
      .then(snapshot => {
        if (snapshot.ok) rememberCache(key, 'notifications', snapshot);
        return snapshot;
      })
      .catch(() => null)
      .finally(() => { runtime.markReadInFlight = null; });
  }

  return responseFromSnapshot(optimisticSnapshot);
}

async function fetchSnapshot(input, init, meta, priority, controllerSet){
  const response = await fetchWithController(input, init, priority, controllerSet);
  const text = await response.text();
  return {
    ok:response.ok,
    status:response.status,
    statusText:response.statusText,
    headers:Array.from(response.headers.entries()),
    body:text,
    url:response.url || String(meta?.url || ''),
  };
}

async function fetchWithController(input, init, priority, controllerSet){
  const controller = controllerSet ? new AbortController() : null;
  const nextInit = cleanInit(init);
  if (priority !== 'auto') nextInit.priority = priority;
  if (controller) {
    if (nextInit.signal && typeof AbortSignal?.any === 'function') {
      nextInit.signal = AbortSignal.any([nextInit.signal, controller.signal]);
    } else if (!nextInit.signal) {
      nextInit.signal = controller.signal;
    }
    controllerSet.add(controller);
  }

  try {
    return await runtime.rawFetch(input, nextInit);
  } finally {
    if (controller) controllerSet.delete(controller);
  }
}

function cacheDescriptor(meta){
  let id = '';
  if (meta.pathname.endsWith('/bot/api.php')) {
    if (['stats','profile','weekly_match_status','shop_status'].includes(meta.action)) id = meta.action;
  } else if (meta.pathname.endsWith('/bot/shop-history.php')) {
    id = 'shop_orders';
  } else if (meta.pathname.endsWith('/bot/notifications.php')) {
    id = 'notifications';
  } else if (meta.pathname.endsWith('/bot/invite-opponents.php')) {
    id = 'invite_opponents';
  }
  return id ? { id, ...CACHE_RULES[id] } : null;
}

function requestMeta(input, init){
  const rawUrl = typeof input === 'string' ? input : String(input?.url || '');
  const url = new URL(rawUrl, window.location.href);
  const method = String(init?.method || input?.method || 'GET').toUpperCase();
  const bodyText = typeof init?.body === 'string' ? init.body : '';
  let body = null;
  try { body = bodyText ? JSON.parse(bodyText) : {}; } catch (error) { body = null; }
  const scope = stableHash(String(body?.initData || getInitData() || 'anonymous'));
  return {
    url:url.href,
    pathname:url.pathname,
    sameOrigin:url.origin === window.location.origin,
    method,
    body,
    action:String(body?.action || ''),
    markRead:Boolean(body?.markRead),
    prefetch:Boolean(init?.mgwPrefetch),
    scope,
  };
}

function isGameAction(meta){
  return meta.pathname.endsWith('/bot/api.php') && ['game_action','make_move'].includes(meta.action);
}

function isGamePoll(meta){
  return meta.pathname.endsWith('/bot/api.php') && meta.action === 'game_state';
}

function isBootstrap(meta){
  return meta.pathname.endsWith('/bot/api.php') && meta.action === 'bootstrap';
}

function isBackgroundSafe(meta){
  if (meta.prefetch) return true;
  if (meta.pathname.endsWith('/bot/api.php') && meta.action === 'stats') return true;
  if (meta.pathname.endsWith('/bot/notifications.php') && !meta.markRead) return true;
  if (meta.pathname.endsWith('/bot/invites.php') && meta.action === 'sync') return true;
  return false;
}

function invalidateForMutation(meta){
  if (meta.pathname.endsWith('/bot/api.php')) {
    if (['game_action','make_move','leave_game'].includes(meta.action)) {
      invalidateScope(meta.scope, ['stats','profile','weekly_match_status','shop_status']);
    }
    if (meta.action === 'shop_order') invalidateScope(meta.scope, ['shop_status','shop_orders','profile','notifications']);
    if (meta.action === 'payment_create_draft') invalidateScope(meta.scope, ['profile','notifications']);
  }
  if (meta.pathname.endsWith('/bot/invites.php') && !['sync','create_link_draft'].includes(meta.action)) {
    invalidateScope(meta.scope, ['notifications','invite_opponents']);
  }
}

function seedBootstrapCaches(scope, data){
  if (data?.stats) {
    rememberCache(cacheKey(scope, 'stats'), 'stats', snapshotFromJson({ ok:true, stats:data.stats, session:data.session || null }));
  }
  if (data?.shop) {
    rememberCache(cacheKey(scope, 'shop_status'), 'shop_status', snapshotFromJson({ ok:true, user:data.user || null, shop:data.shop, session:data.session || null }));
  }
  if (data?.weekly_match) {
    rememberCache(cacheKey(scope, 'weekly_match_status'), 'weekly_match_status', snapshotFromJson({ ok:true, user:data.user || null, weekly_match:data.weekly_match, session:data.session || null }));
  }
}

function rememberCache(key, id, snapshot){
  runtime.cache.set(key, { id, snapshot, storedAt:Date.now() });
  document.dispatchEvent(new CustomEvent('mgw:v101-cache-updated', {
    detail:{ id, data:parseSnapshot(snapshot) },
  }));
}

function mergeNotificationCache(item, unreadCount){
  if (!item?.id) return;
  const key = cacheKey(runtime.currentScope, 'notifications');
  const current = runtime.cache.get(key);
  const data = mergeNotificationSnapshot(current ? parseSnapshot(current.snapshot) : null, item, unreadCount);
  rememberCache(key, 'notifications', snapshotFromJson(data));
}

function updateNotificationCount(unreadCount){
  if (!Number.isFinite(unreadCount)) return;
  const key = cacheKey(runtime.currentScope, 'notifications');
  const current = runtime.cache.get(key);
  if (!current) return;
  const data = parseSnapshot(current.snapshot);
  if (!data) return;
  data.unread_count = Math.max(0, Math.trunc(unreadCount));
  runtime.cache.set(key, { ...current, snapshot:snapshotFromJson(data), storedAt:Date.now() });
}

function schedulePassivePrefetch(delayMs){
  if (runtime.prefetchScheduled) return;
  runtime.prefetchScheduled = true;
  const schedule = window.requestIdleCallback
    ? callback => window.requestIdleCallback(callback, { timeout:Math.max(500, delayMs + 500) })
    : callback => window.setTimeout(callback, delayMs);
  schedule(() => {
    runtime.prefetchScheduled = false;
    if (document.visibilityState !== 'visible' || document.querySelector('#screen-game.active')) return;
    void runPassivePrefetch();
  });
}

async function runPassivePrefetch(){
  const tasks = [
    () => prefetchApiAction('profile'),
    () => prefetchNotifications(),
    () => prefetchShopOrders(),
    () => prefetchApiAction('weekly_match_status'),
    () => prefetchApiAction('shop_status'),
  ];
  const workers = Array.from({ length:2 }, async () => {
    while (tasks.length) {
      const task = tasks.shift();
      if (!task || document.querySelector('#screen-game.active')) return;
      await task().catch(() => null);
    }
  });
  await Promise.all(workers);
}

function scheduleOpponentPrefetch(delayMs){
  window.setTimeout(() => {
    if (document.visibilityState !== 'visible') return;
    void prefetchJson('/bot/invite-opponents.php', {});
  }, Math.max(0, delayMs));
}

function prefetchApiAction(action){
  return prefetchJson('/bot/api.php', { action });
}

function prefetchNotifications(){
  return prefetchJson('/bot/notifications.php', { markRead:false });
}

function prefetchShopOrders(){
  return prefetchJson('/bot/shop-history.php', {});
}

function prefetchJson(path, payload){
  return window.fetch(`${window.location.origin}${path}`, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({
      initData:getInitData(),
      sessionId:getSessionId(),
      ...payload,
    }),
    mgwPrefetch:true,
  }).then(response => response.json().catch(() => null));
}

function invalidateCurrent(ids){
  invalidateScope(runtime.currentScope, ids);
}

function invalidateScope(scope, ids){
  for (const id of ids) runtime.cache.delete(cacheKey(scope, id));
}

function cacheKey(scope, id){
  return `${String(scope || 'anonymous')}:${String(id || '')}`;
}

function snapshotFromJson(data, status = 200, statusText = 'OK'){
  return {
    ok:status >= 200 && status < 300,
    status,
    statusText,
    headers:[['content-type','application/json; charset=utf-8']],
    body:JSON.stringify(data || {}),
    url:'',
  };
}

function responseFromSnapshot(snapshot){
  return new Response(snapshot.body, {
    status:snapshot.status,
    statusText:snapshot.statusText,
    headers:snapshot.headers,
  });
}

function parseSnapshot(snapshot){
  if (!snapshot?.body) return null;
  try { return JSON.parse(snapshot.body); } catch (error) { return null; }
}

function cleanInit(init){
  const next = { ...(init || {}) };
  delete next.mgwPrefetch;
  return next;
}

function abortTracked(set){
  for (const controller of [...set]) {
    try { controller.abort('superseded-by-user-action'); } catch (error) {}
  }
  set.clear();
}

function isAbort(error){
  return error?.name === 'AbortError' || String(error?.message || '').includes('superseded-by-user-action');
}

function quietAbort(){
  return { name:'AbortError', message:'' };
}

function rememberMetric(meta, startedAt, failed){
  const duration = Math.max(0, performance.now() - startedAt);
  runtime.metrics.push({ action:meta.action, duration, failed:Boolean(failed), at:Date.now() });
  if (runtime.metrics.length > 60) runtime.metrics.splice(0, runtime.metrics.length - 60);
}
