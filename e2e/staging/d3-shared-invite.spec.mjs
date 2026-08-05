import { test, expect } from '@playwright/test';
import { openOrdinaryStartReady } from './support/ordinary-start-readiness.mjs';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = `${STAGING_ORIGIN}/app/v110.php?v=1123`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const INVITES_ROUTE = `${STAGING_ORIGIN}/bot/invites.php`;
const TEST_COOKIE = 'mgw_staging_test_session';

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

async function authorizeContext(context, slot) {
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
  const payload = await response.json();
  expect(payload).toMatchObject({
    ok: true,
    action: 'issued',
    player_slot: slot,
  });

  const cookies = await context.cookies(STAGING_ORIGIN);
  const cookie = cookies.find(item => item.name === TEST_COOKIE);
  expect(cookie, `Player ${slot} auth cookie`).toBeTruthy();
  expect(cookie.httpOnly).toBe(true);
  expect(cookie.secure).toBe(true);
  expect(cookie.sameSite).toBe('Strict');
}

async function installTelegramShareMock(context) {
  await context.addInitScript(() => {
    const listeners = new Map();
    const state = {
      mode: 'decline',
      calls: [],
      results: [],
    };

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
      onEvent(name, callback) {
        if (!listeners.has(name)) listeners.set(name, []);
        listeners.get(name).push(callback);
      },
      shareMessage(preparedId, callback) {
        const mode = String(state.mode || 'decline');
        state.calls.push({ preparedId: String(preparedId || ''), mode });
        queueMicrotask(() => {
          if (mode === 'sent') {
            state.results.push('sent');
            callback?.(true);
            return;
          }
          state.results.push('declined');
          callback?.(false);
        });
      },
      openTelegramLink() {},
    };

    const telegram = { WebApp: webApp };
    Object.defineProperty(window, 'Telegram', {
      configurable: true,
      get: () => telegram,
      set: () => {},
    });
    window.__MGW_D3_TELEGRAM_SHARE__ = state;
  });
}

function requestAction(request) {
  try {
    return String(request.postDataJSON()?.action || '');
  } catch {
    return '';
  }
}

function isActionResponse(route, action) {
  return response => response.url() === route
    && response.request().method() === 'POST'
    && requestAction(response.request()) === action;
}

function isExpectedPresenceResumeAbort(request) {
  if (!request.url().startsWith(STAGING_ORIGIN)) return false;
  return request.method() === 'POST'
    && new URL(request.url()).pathname === '/bot/presence.php'
    && String(request.failure()?.errorText || '') === 'net::ERR_ABORTED';
}

function collectDiagnostics(page, slot) {
  const report = {
    slot,
    pageErrors: [],
    failedRequests: [],
    serverErrors: [],
  };

  page.on('pageerror', error => {
    report.pageErrors.push(String(error?.message || error).slice(0, 500));
  });
  page.on('requestfailed', request => {
    if (!request.url().startsWith(STAGING_ORIGIN)) return;
    if (isExpectedPresenceResumeAbort(request)) return;
    report.failedRequests.push({
      method: request.method(),
      path: new URL(request.url()).pathname,
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

function createActionCounter(page) {
  const counts = new Map();
  const listener = request => {
    if (request.url() !== INVITES_ROUTE || request.method() !== 'POST') return;
    const action = requestAction(request);
    if (!action) return;
    counts.set(action, Number(counts.get(action) || 0) + 1);
  };
  page.on('request', listener);
  return {
    count(action) {
      return Number(counts.get(action) || 0);
    },
    stop() {
      page.off('request', listener);
    },
  };
}

async function openPlayerPage(context, slot, appRoute = APP_ROUTE, beforeOpen = null) {
  const page = await context.newPage();
  const diagnostics = collectDiagnostics(page, slot);
  if (typeof beforeOpen === 'function') await beforeOpen(page);
  await openOrdinaryStartReady(page, {
    appRoute,
    apiRoute: API_ROUTE,
    label: `Player ${slot}`,
  });
  return { page, diagnostics };
}

async function postFromPlayer(page, pathname, data) {
  return page.evaluate(async ({ pathname: requestPath, data: requestData }) => {
    const sessionId = localStorage.getItem('mgw_device_session_id');
    const deviceId = localStorage.getItem('mgw_device_id');
    const response = await fetch(requestPath, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        ...requestData,
        initData: '',
        sessionId,
        deviceId,
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

async function expectPlayerRequest(page, pathname, data, label) {
  const result = await postFromPlayer(page, pathname, data);
  expect(
    result.status,
    `${label}; public error: ${result.publicError || 'no_public_error'}`,
  ).toBe(200);
  expect(result.payload?.ok, `${label} payload`).toBe(true);
  return result.payload;
}

async function clickInviteAction(page, action, token) {
  const button = page.locator(
    `[data-invite-action="${action}"][data-invite-token="${token}"]`,
  );
  await expect(button, `${action} action`).toBeVisible({ timeout: 30_000 });
  await expect(button).toBeEnabled();
  const responsePromise = page.waitForResponse(isActionResponse(INVITES_ROUTE, action), {
    timeout: 35_000,
  });
  await button.click();
  const response = await responsePromise;
  const payload = await response.json().catch(() => null);
  expect(response.status(), `${action} response`).toBe(200);
  expect(payload?.ok, `${action} payload`).toBe(true);
  return payload;
}

async function cleanupPlayer(page) {
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

async function revokeContext(context) {
  try {
    await context.request.post(AUTH_ROUTE, { data: { action: 'revoke' }, timeout: 15_000 });
  } catch {}
}

test('D3 native share cancellation is quiet and one shared link creates one match', async ({ browser }, testInfo) => {
  test.setTimeout(180_000);
  let contextA;
  let contextB;
  let playerA;
  let playerB;
  let counterA;
  let counterB;
  let token = '';
  let gameId = '';

  try {
    contextA = await browser.newContext({
      locale: 'ru-RU',
      timezoneId: 'Europe/Vilnius',
      viewport: { width: 390, height: 844 },
      deviceScaleFactor: 1,
      isMobile: true,
      hasTouch: true,
    });
    contextB = await browser.newContext({
      locale: 'ru-RU',
      timezoneId: 'Europe/Vilnius',
      viewport: { width: 390, height: 844 },
      deviceScaleFactor: 1,
      isMobile: true,
      hasTouch: true,
    });

    await installTelegramShareMock(contextA);
    await authorizeContext(contextA, 'A');
    await authorizeContext(contextB, 'B');

    playerA = await openPlayerPage(contextA, 'A');
    await cleanupPlayer(playerA.page);
    counterA = createActionCounter(playerA.page);

    await playerA.page.evaluate(() => {
      window.__MGW_D3_TELEGRAM_SHARE__.mode = 'decline';
    });
    await playerA.page.locator('[data-invite-friend="tictactoe"]').click();
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toContainText('Пригласить в', {
      timeout: 20_000,
    });

    const shareButton = playerA.page.locator('[data-create-link-invite]');
    await expect(shareButton).toBeVisible();
    await shareButton.click();
    await playerA.page.waitForFunction(() => (
      window.__MGW_D3_TELEGRAM_SHARE__?.results?.length === 1
    ), null, { timeout: 30_000 });

    await expect(playerA.page.locator('#sheet .sheet-head h2')).toContainText('Пригласить в');
    await expect(shareButton).toBeEnabled();
    await expect(playerA.page.locator('#toast')).not.toHaveClass(/show/);
    await expect(playerA.page.locator('#notificationToast')).not.toHaveClass(/show/);
    await expect.poll(() => counterA.count('create_link_draft')).toBe(1);

    const firstShare = await playerA.page.evaluate(() => {
      const state = window.__MGW_D3_TELEGRAM_SHARE__;
      return {
        preparedId: String(state?.calls?.[0]?.preparedId || ''),
        result: String(state?.results?.[0] || ''),
      };
    });
    expect(firstShare.preparedId).not.toBe('');
    expect(firstShare.result).toBe('declined');

    await playerA.page.evaluate(() => {
      window.__MGW_D3_TELEGRAM_SHARE__.mode = 'sent';
    });
    const confirmPromise = playerA.page.waitForResponse(
      isActionResponse(INVITES_ROUTE, 'confirm_shared'),
      { timeout: 35_000 },
    );
    await shareButton.click();
    const confirmResponse = await confirmPromise;
    expect(confirmResponse.status()).toBe(200);
    expect((await confirmResponse.json().catch(() => null))?.ok).toBe(true);

    await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveText('Приглашение отправлено', {
      timeout: 25_000,
    });
    const marker = playerA.page.locator('#sheet [data-invite-sheet]').first();
    await expect(marker).toHaveCount(1);
    token = String(await marker.getAttribute('data-invite-token') || '');
    expect(token).toMatch(/^[a-f0-9]{24}$/);

    const shareState = await playerA.page.evaluate(() => ({
      calls: window.__MGW_D3_TELEGRAM_SHARE__?.calls || [],
      results: window.__MGW_D3_TELEGRAM_SHARE__?.results || [],
    }));
    expect(shareState.results).toEqual(['declined', 'sent']);
    expect(shareState.calls).toHaveLength(2);
    expect(String(shareState.calls[0]?.preparedId || '')).toBe(firstShare.preparedId);
    expect(String(shareState.calls[1]?.preparedId || '')).toBe(firstShare.preparedId);
    expect(counterA.count('create_link_draft')).toBe(1);
    expect(counterA.count('confirm_shared')).toBe(1);

    playerB = await openPlayerPage(
      contextB,
      'B',
      `${APP_ROUTE}&invite=${encodeURIComponent(token)}`,
      page => {
        counterB = createActionCounter(page);
      },
    );
    await expect(playerB.page.locator('#sheet .sheet-head h2')).toHaveText('Вас приглашают сыграть', {
      timeout: 30_000,
    });
    await expect(playerB.page.locator(
      `#sheet [data-invite-sheet][data-invite-token="${token}"]`,
    )).toHaveCount(1);
    await expect.poll(() => counterB.count('open_link')).toBe(1);

    const accepted = await clickInviteAction(playerB.page, 'accept', token);
    expect(['accepted', 'awaiting_start']).toContain(String(accepted?.invite?.status || ''));
    await expect(playerB.page.locator('#sheet .sheet-head h2')).toHaveText('Приглашение принято', {
      timeout: 25_000,
    });

    const started = await clickInviteAction(playerA.page, 'start', token);
    gameId = String(started?.game?.id || '');
    expect(gameId).toMatch(/^[A-Za-z0-9_-]{8,120}$/);
    expect(started?.game?.status).toBe('active');
    expect(started?.game?.game_type).toBe('tictactoe');

    await expect(playerA.page.locator('#screen-game')).toHaveClass(/active/, { timeout: 30_000 });
    await expect(playerB.page.locator('#screen-game')).toHaveClass(/active/, { timeout: 35_000 });

    const gameA = await expectPlayerRequest(
      playerA.page,
      '/bot/api.php',
      { action: 'game_state', gameId },
      'Player A shared game state',
    );
    const gameB = await expectPlayerRequest(
      playerB.page,
      '/bot/api.php',
      { action: 'game_state', gameId },
      'Player B shared game state',
    );
    expect(String(gameA?.game?.id || '')).toBe(gameId);
    expect(String(gameB?.game?.id || '')).toBe(gameId);
    expect(gameA?.game?.status).toBe('active');
    expect(gameB?.game?.status).toBe('active');
    expect(counterA.count('start')).toBe(1);
    expect(counterB.count('open_link')).toBe(1);

    expect(playerA.diagnostics.pageErrors).toEqual([]);
    expect(playerB.diagnostics.pageErrors).toEqual([]);
    expect(playerA.diagnostics.failedRequests).toEqual([]);
    expect(playerB.diagnostics.failedRequests).toEqual([]);
    expect(playerA.diagnostics.serverErrors).toEqual([]);
    expect(playerB.diagnostics.serverErrors).toEqual([]);

    await testInfo.attach('d3-shared-invite-report', {
      body: Buffer.from(`${JSON.stringify({
        ok: true,
        ordinaryStartRoute: '/app/v110.php?v=1123',
        nativeShareInvoked: true,
        nativeCancellationQuiet: true,
        cancelledDraftReused: true,
        createLinkDraftRequests: counterA.count('create_link_draft'),
        confirmSharedRequests: counterA.count('confirm_shared'),
        openLinkRequests: counterB.count('open_link'),
        startRequests: counterA.count('start'),
        sharedPlayersUseSameGame: true,
        productionChanged: false,
        livePaymentsUsed: false,
      }, null, 2)}\n`, 'utf8'),
      contentType: 'application/json',
    });
  } finally {
    counterA?.stop();
    counterB?.stop();
    await cleanupPlayer(playerA?.page);
    await cleanupPlayer(playerB?.page);
    if (contextA) {
      await revokeContext(contextA);
      await contextA.close();
    }
    if (contextB) {
      await revokeContext(contextB);
      await contextB.close();
    }
  }
});
