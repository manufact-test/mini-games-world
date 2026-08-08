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
  };

  // A transition owns two precisely bounded request groups:
  // 1) requests that start after its explicit allow flag is acquired;
  // 2) requests already in flight when the transition explicitly adopts them.
  // This preserves request-object ownership without reverting to failure-time
  // booleans: a request that is neither started nor adopted by the transition
  // remains strict even if requestfailed happens during that transition.
  const allowedInviteSyncAbortRequests = new WeakSet();
  const allowedBackgroundProfileAbortRequests = new WeakSet();
  const allowedBackgroundShopHistoryAbortRequests = new WeakSet();
  const allowedInviteWatchAbortRequests = new WeakSet();
  const activeInviteSyncRequests = new Set();
  const activeBackgroundProfileRequests = new Set();
  const activeBackgroundShopHistoryRequests = new Set();
  const activeInviteWatchRequests = new Set();

  report.adoptActiveBackgroundAborts = ({
    inviteSync = false,
    backgroundProfile = false,
    backgroundShopHistory = false,
    inviteWatch = false,
  } = {}) => {
    if (inviteSync) {
      for (const request of activeInviteSyncRequests) allowedInviteSyncAbortRequests.add(request);
    }
    if (backgroundProfile) {
      for (const request of activeBackgroundProfileRequests) allowedBackgroundProfileAbortRequests.add(request);
    }
    if (backgroundShopHistory) {
      for (const request of activeBackgroundShopHistoryRequests) allowedBackgroundShopHistoryAbortRequests.add(request);
    }
    if (inviteWatch) {
      for (const request of activeInviteWatchRequests) allowedInviteWatchAbortRequests.add(request);
    }
  };

  page.on('pageerror', error => {
    report.pageErrors.push(String(error?.message || error).slice(0, 500));
  });
  page.on('request', request => {
    if (!request.url().startsWith(STAGING_ORIGIN)) return;
    if (isInviteSyncRequest(request)) {
      activeInviteSyncRequests.add(request);
      if (report.allowInviteSyncAbort) allowedInviteSyncAbortRequests.add(request);
    }
    if (isBackgroundProfileRequest(request)) {
      activeBackgroundProfileRequests.add(request);
      if (report.allowBackgroundProfileAbort) allowedBackgroundProfileAbortRequests.add(request);
    }
    if (isBackgroundShopHistoryRequest(request)) {
      activeBackgroundShopHistoryRequests.add(request);
      if (report.allowBackgroundShopHistoryAbort) allowedBackgroundShopHistoryAbortRequests.add(request);
    }
    if (isInviteWatchRequest(request)) {
      activeInviteWatchRequests.add(request);
      if (report.allowInviteWatchAbort) allowedInviteWatchAbortRequests.add(request);
    }
  });
  page.on('requestfinished', request => {
    activeInviteSyncRequests.delete(request);
    activeBackgroundProfileRequests.delete(request);
    activeBackgroundShopHistoryRequests.delete(request);
    activeInviteWatchRequests.delete(request);
  });
  page.on('requestfailed', request => {
    if (!request.url().startsWith(STAGING_ORIGIN)) return;
    activeInviteSyncRequests.delete(request);
    activeBackgroundProfileRequests.delete(request);
    activeBackgroundShopHistoryRequests.delete(request);
    activeInviteWatchRequests.delete(request);
    if (isExpectedPresenceResumeAbort(request)) return;
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

export async function cleanupPlayer(page) {
  if (!page || page.isClosed()) return;
  try {
    const state = await postFromPlayer(page, '/bot/api.php', { action: 'game_state' });
    const game = state.payload?.game || null;
    if (state.status === 200 && game?.id && game.status === 'active') {
      await postFromPlayer(page, '/bot/api.php', { action: 'leave_game', gameId: game.id });
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
