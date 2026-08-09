import { expect } from '@playwright/test';
import { openOrdinaryStartReady } from './ordinary-start-readiness.mjs';
import {
  STAGING_ORIGIN,
  OIDC_AUDIENCE,
  AUTH_ROUTE,
  APP_ROUTE,
  API_ROUTE,
  INVITES_ROUTE,
  TEST_COOKIE,
  requestAction,
} from './d3-shared-config.mjs';

async function requestOidcToken() {
  const requestUrl = process.env.ACTIONS_ID_TOKEN_REQUEST_URL || '';
  const requestToken = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN || '';
  if (!requestUrl || !requestToken) {
    throw new Error('GitHub Actions OIDC environment is unavailable.');
  }

  const url = new URL(requestUrl);
  url.searchParams.set('audience', OIDC_AUDIENCE);
  const response = await fetch(url, {
    headers: {
      Authorization: `bearer ${requestToken}`,
      Accept: 'application/json',
    },
  });
  if (!response.ok) {
    throw new Error(`GitHub Actions OIDC request failed with status ${response.status}.`);
  }
  const payload = await response.json();
  if (typeof payload?.value !== 'string' || payload.value.split('.').length !== 3) {
    throw new Error('GitHub Actions OIDC response did not contain a JWT.');
  }
  return payload.value;
}

export async function authorizeContext(context, slot) {
  const oidcToken = await requestOidcToken();
  const response = await context.request.post(AUTH_ROUTE, {
    headers: {
      Authorization: `Bearer ${oidcToken}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    data: { action: 'issue', slot },
    timeout: 35_000,
  });

  expect(response.status(), `Player ${slot} auth status`).toBe(200);
  expect(await response.json()).toMatchObject({
    ok: true,
    action: 'issued',
    player_slot: slot,
  });

  const cookie = (await context.cookies(STAGING_ORIGIN))
    .find(item => item.name === TEST_COOKIE);
  expect(cookie, `Player ${slot} auth cookie`).toBeTruthy();
  expect(cookie.httpOnly).toBe(true);
  expect(cookie.secure).toBe(true);
  expect(cookie.sameSite).toBe('Strict');
}

export async function installTelegramShareMock(context) {
  await context.addInitScript(() => {
    const state = { mode: 'decline', calls: [], results: [] };
    const webApp = {
      initData: '',
      initDataUnsafe: {},
      ready() {},
      expand() {},
      disableVerticalSwipes() {},
      setHeaderColor() {},
      setBackgroundColor() {},
      setBottomBarColor() {},
      HapticFeedback: { impactOccurred() {} },
      onEvent() {},
      shareMessage(preparedId, callback) {
        const mode = String(state.mode || 'decline');
        state.calls.push({ preparedId: String(preparedId || ''), mode });
        queueMicrotask(() => {
          const sent = mode === 'sent';
          state.results.push(sent ? 'sent' : 'declined');
          callback?.(sent);
        });
      },
      openTelegramLink() {},
    };

    const telegram = {};
    Object.defineProperty(telegram, 'WebApp', {
      configurable: false,
      enumerable: true,
      get: () => webApp,
      set: () => {},
    });
    Object.defineProperty(window, 'Telegram', {
      configurable: false,
      get: () => telegram,
      set: () => {},
    });
    window.__MGW_D3_TELEGRAM_SHARE__ = state;
  });
}

function isExpectedPresenceResumeAbort(request) {
  return request.url().startsWith(STAGING_ORIGIN)
    && request.method() === 'POST'
    && new URL(request.url()).pathname === '/bot/presence.php'
    && String(request.failure()?.errorText || '') === 'net::ERR_ABORTED';
}

function isInviteSyncRequest(request) {
  return request.url() === INVITES_ROUTE
    && request.method() === 'POST'
    && requestAction(request) === 'sync';
}

function isExpectedInviteSyncAbort(request) {
  return isInviteSyncRequest(request)
    && String(request.failure()?.errorText || '') === 'net::ERR_ABORTED';
}

function isBackgroundProfileRequest(request) {
  return request.url() === API_ROUTE
    && request.method() === 'POST'
    && requestAction(request) === 'profile';
}

function isExpectedBackgroundProfileAbort(request) {
  return isBackgroundProfileRequest(request)
    && String(request.failure()?.errorText || '') === 'net::ERR_ABORTED';
}

function isBackgroundStatsRequest(request) {
  return request.url() === API_ROUTE
    && request.method() === 'POST'
    && requestAction(request) === 'stats';
}

function isExpectedBackgroundStatsAbort(request) {
  return isBackgroundStatsRequest(request)
    && String(request.failure()?.errorText || '') === 'net::ERR_ABORTED';
}

function isBackgroundShopHistoryRequest(request) {
  return request.url().startsWith(STAGING_ORIGIN)
    && request.method() === 'POST'
    && new URL(request.url()).pathname === '/bot/shop-history.php';
}

function isExpectedBackgroundShopHistoryAbort(request) {
  return isBackgroundShopHistoryRequest(request)
    && String(request.failure()?.errorText || '') === 'net::ERR_ABORTED';
}

function isInviteWatchRequest(request) {
  return request.url().startsWith(STAGING_ORIGIN)
    && request.method() === 'POST'
    && new URL(request.url()).pathname === '/bot/invite-watch.php';
}

function isExpectedInviteWatchAbort(request) {
  return isInviteWatchRequest(request)
    && String(request.failure()?.errorText || '') === 'net::ERR_ABORTED';
}

export function collectDiagnostics(page, slot) {
  const report = {
    slot,
    pageErrors: [],
    failedRequests: [],
    serverErrors: [],
    allowInviteSyncAbort: false,
    ignoredInviteSyncAborts: 0,
    allowBackgroundProfileAbort: false,
    ignoredBackgroundProfileAborts: 0,
    allowBackgroundShopHistoryAbort: false,
    ignoredBackgroundShopHistoryAborts: 0,
    allowInviteWatchAbort: false,
    ignoredInviteWatchAborts: 0,
    allowStartSearchInviteBackgroundAbort: false,
    ignoredStartSearchInviteSyncAborts: 0,
    ignoredStartSearchInviteWatchAborts: 0,
    ignoredStartSearchShopHistoryAborts: 0,
    ignoredStartSearchStatsAborts: 0,
    ignoredAcceptInviteSyncAborts: 0,
  };

  // An allowed transition owns requests from the moment they start. A request
  // that begins while navigation/start handoff explicitly permits a background
  // abort must not become a false failure merely because requestfailed arrives
  // after the transition has already finished and its boolean flag was reset.
  // Conversely, requests that started outside the allowed window remain strict.
  const allowedInviteSyncAbortRequests = new WeakSet();
  const allowedBackgroundProfileAbortRequests = new WeakSet();
  const allowedBackgroundShopHistoryAbortRequests = new WeakSet();
  const allowedInviteWatchAbortRequests = new WeakSet();

  // start_search is different from a navigation-only allowance: the accepted
  // cache-safety owner intentionally aborts background reads already in flight
  // when the state-changing pointer action begins. Keep those exact requests
  // enumerable until completion so this transition can adopt only the reads it
  // truly supersedes. The dedicated WeakSets keep this ownership separate from
  // every existing navigation/reopen abort allowance and from their counters.
  const inFlightInviteSyncRequests = new Set();
  const inFlightInviteWatchRequests = new Set();
  const inFlightBackgroundShopHistoryRequests = new Set();
  const inFlightBackgroundStatsRequests = new Set();
  const startSearchInviteSyncAbortRequests = new WeakSet();
  const startSearchInviteWatchAbortRequests = new WeakSet();
  const startSearchShopHistoryAbortRequests = new WeakSet();
  const startSearchStatsAbortRequests = new WeakSet();
  const acceptInviteSyncAbortRequests = new WeakSet();

  // Accept is a foreground state mutation. The production cache-safety owner
  // aborts invite sync reads that were already in flight at pointerdown. Adopt
  // only those exact Request objects at that exact transition boundary.
  report.beginAcceptInviteSyncAbortOwnership = () => {
    for (const request of inFlightInviteSyncRequests) {
      acceptInviteSyncAbortRequests.add(request);
    }
  };

  report.beginStartSearchInviteBackgroundAbortOwnership = () => {
    report.allowStartSearchInviteBackgroundAbort = true;
    for (const request of inFlightInviteSyncRequests) {
      startSearchInviteSyncAbortRequests.add(request);
    }
    for (const request of inFlightInviteWatchRequests) {
      startSearchInviteWatchAbortRequests.add(request);
    }
  };
  report.endStartSearchInviteBackgroundAbortOwnership = () => {
    report.allowStartSearchInviteBackgroundAbort = false;
  };

  // shop-history is passively prefetched under a background controller. The
  // production start_search cache-safety transition aborts such a read if it is
  // already in flight. Snapshot only those exact requests; do not open a time
  // window in which later shop-history failures could be accepted.
  report.beginStartSearchShopHistoryAbortOwnership = () => {
    for (const request of inFlightBackgroundShopHistoryRequests) {
      startSearchShopHistoryAbortRequests.add(request);
    }
  };

  // stats is another passive API read owned by the same cache-safety boundary.
  // Snapshot only the exact stats request already running at start_search; any
  // request that begins later or fails for another reason stays strict.
  report.beginStartSearchStatsAbortOwnership = () => {
    for (const request of inFlightBackgroundStatsRequests) {
      startSearchStatsAbortRequests.add(request);
    }
  };

  const forgetInFlightTransitionRequest = request => {
    inFlightInviteSyncRequests.delete(request);
    inFlightInviteWatchRequests.delete(request);
    inFlightBackgroundShopHistoryRequests.delete(request);
    inFlightBackgroundStatsRequests.delete(request);
  };

  page.on('pageerror', error => {
    report.pageErrors.push(String(error?.message || error).slice(0, 500));
  });
  page.on('request', request => {
    if (!request.url().startsWith(STAGING_ORIGIN)) return;

    if (isInviteSyncRequest(request)) inFlightInviteSyncRequests.add(request);
    if (isInviteWatchRequest(request)) inFlightInviteWatchRequests.add(request);
    if (isBackgroundShopHistoryRequest(request)) inFlightBackgroundShopHistoryRequests.add(request);
    if (isBackgroundStatsRequest(request)) inFlightBackgroundStatsRequests.add(request);

    if (report.allowInviteSyncAbort && isInviteSyncRequest(request)) {
      allowedInviteSyncAbortRequests.add(request);
    }
    if (report.allowBackgroundProfileAbort && isBackgroundProfileRequest(request)) {
      allowedBackgroundProfileAbortRequests.add(request);
    }
    if (report.allowBackgroundShopHistoryAbort && isBackgroundShopHistoryRequest(request)) {
      allowedBackgroundShopHistoryAbortRequests.add(request);
    }
    if (report.allowInviteWatchAbort && isInviteWatchRequest(request)) {
      allowedInviteWatchAbortRequests.add(request);
    }
    if (report.allowStartSearchInviteBackgroundAbort && isInviteSyncRequest(request)) {
      startSearchInviteSyncAbortRequests.add(request);
    }
    if (report.allowStartSearchInviteBackgroundAbort && isInviteWatchRequest(request)) {
      startSearchInviteWatchAbortRequests.add(request);
    }
  });
  page.on('requestfinished', forgetInFlightTransitionRequest);
  page.on('requestfailed', request => {
    forgetInFlightTransitionRequest(request);
    if (!request.url().startsWith(STAGING_ORIGIN)) return;
    if (isExpectedPresenceResumeAbort(request)) return;
    if (acceptInviteSyncAbortRequests.has(request) && isExpectedInviteSyncAbort(request)) {
      report.ignoredAcceptInviteSyncAborts += 1;
      return;
    }
    if (startSearchInviteSyncAbortRequests.has(request) && isExpectedInviteSyncAbort(request)) {
      report.ignoredStartSearchInviteSyncAborts += 1;
      return;
    }
    if (startSearchInviteWatchAbortRequests.has(request) && isExpectedInviteWatchAbort(request)) {
      report.ignoredStartSearchInviteWatchAborts += 1;
      return;
    }
    if (startSearchShopHistoryAbortRequests.has(request) && isExpectedBackgroundShopHistoryAbort(request)) {
      report.ignoredStartSearchShopHistoryAborts += 1;
      return;
    }
    if (startSearchStatsAbortRequests.has(request) && isExpectedBackgroundStatsAbort(request)) {
      report.ignoredStartSearchStatsAborts += 1;
      return;
    }
    if (allowedInviteSyncAbortRequests.has(request) && isExpectedInviteSyncAbort(request)) {
      report.ignoredInviteSyncAborts += 1;
      return;
    }
    if (allowedBackgroundProfileAbortRequests.has(request) && isExpectedBackgroundProfileAbort(request)) {
      report.ignoredBackgroundProfileAborts += 1;
      return;
    }
    if (allowedBackgroundShopHistoryAbortRequests.has(request) && isExpectedBackgroundShopHistoryAbort(request)) {
      report.ignoredBackgroundShopHistoryAborts += 1;
      return;
    }
    if (allowedInviteWatchAbortRequests.has(request) && isExpectedInviteWatchAbort(request)) {
      report.ignoredInviteWatchAborts += 1;
      return;
    }
    report.failedRequests.push({
      method: request.method(),
      path: new URL(request.url()).pathname,
      action: requestAction(request),
      error: String(request.failure()?.errorText || 'request_failed').slice(0, 200),
    });
  });
  page.on('response', response => {
    if (response.url().startsWith(STAGING_ORIGIN) && response.status() >= 500) {
      report.serverErrors.push({
        status: response.status(),
        path: new URL(response.url()).pathname,
      });
    }
  });

  return report;
}

export async function openPlayerPage(context, slot, appRoute = APP_ROUTE, beforeOpen = null) {
  const page = await context.newPage();
  const diagnostics = collectDiagnostics(page, slot);
  if (typeof beforeOpen === 'function') await beforeOpen(page, diagnostics);
  await openOrdinaryStartReady(page, {
    appRoute,
    apiRoute: API_ROUTE,
    label: `Player ${slot}`,
  });
  return { page, diagnostics };
}

export async function postFromPlayer(page, pathname, data) {
  return page.evaluate(async ({ pathname: requestPath, data: requestData }) => {
    const response = await fetch(requestPath, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        ...requestData,
        initData: '',
        sessionId: localStorage.getItem('mgw_device_session_id'),
        deviceId: localStorage.getItem('mgw_device_id'),
      }),
    });
    const payload = await response.json().catch(() => null);
    return {
      status: response.status,
      payload,
      publicError: typeof payload?.error === 'string' ? payload.error.slice(0, 300) : null,
    };
  }, { pathname, data });
}

function cleanupWaitMs(game) {
  if (String(game?.launch_phase || '') !== 'countdown') return 0;
  const startsAtMs = Number(game?.starts_at_ms || 0);
  const serverNowMs = Number(game?.server_now_ms || 0);
  if (!(startsAtMs > 0) || !(serverNowMs > 0)) return 0;
  return Math.max(0, Math.min(3_500, startsAtMs - serverNowMs + 150));
}

export async function cleanupPlayer(page) {
  if (!page || page.isClosed()) return;
  try {
    let state = await postFromPlayer(page, '/bot/api.php', { action: 'game_state' });
    let game = state.payload?.game || null;
    if (state.status === 200 && game?.id && game.status === 'active') {
      const waitMs = cleanupWaitMs(game);
      if (waitMs > 0) {
        await new Promise(resolve => setTimeout(resolve, waitMs));
        state = await postFromPlayer(page, '/bot/api.php', {
          action: 'game_state',
          gameId: game.id,
        });
        game = state.payload?.game || game;
      }

      const launchPhase = String(game?.launch_phase || '');
      if (state.status === 200
        && game?.id
        && game.status === 'active'
        && (launchPhase === '' || launchPhase === 'active')) {
        await postFromPlayer(page, '/bot/api.php', { action: 'leave_game', gameId: game.id });
      }
    }
  } catch {}

  try {
    const sync = await postFromPlayer(page, '/bot/invites.php', { action: 'sync', token: '' });
    const invite = sync.payload?.invite || sync.payload?.tracked_invite || null;
    if (sync.status === 200
      && invite?.token
      && ['draft', 'pending', 'accepted', 'awaiting_start'].includes(String(invite.status || ''))) {
      const action = String(invite.status || '') === 'draft' ? 'discard_draft' : 'cancel';
      await postFromPlayer(page, '/bot/invites.php', { action, token: invite.token });
    }
  } catch {}
}

export async function revokeContext(context) {
  try {
    await context.request.post(AUTH_ROUTE, { data: { action: 'revoke' }, timeout: 15_000 });
  } catch {}
}