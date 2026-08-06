import { test, expect } from '@playwright/test';
import { openOrdinaryStartReady } from './support/ordinary-start-readiness.mjs';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = `${STAGING_ORIGIN}/app/v110.php?v=1123`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const INVITES_ROUTE = `${STAGING_ORIGIN}/bot/invites.php`;
const NOTIFICATIONS_ROUTE = `${STAGING_ORIGIN}/bot/notifications.php`;
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
    service: 'mini-games-world-staging-test-auth',
    action: 'issued',
    authorization_mode: 'github_actions_oidc',
    player_slot: slot,
  });

  const cookies = await context.cookies(STAGING_ORIGIN);
  const cookie = cookies.find(item => item.name === TEST_COOKIE);
  expect(cookie, `Player ${slot} auth cookie`).toBeTruthy();
  expect(cookie.httpOnly).toBe(true);
  expect(cookie.secure).toBe(true);
  expect(cookie.sameSite).toBe('Strict');
}

function isExpectedPresenceResumeAbort(request) {
  if (!request.url().startsWith(STAGING_ORIGIN)) return false;
  const failure = String(request.failure()?.errorText || '');
  return request.method() === 'POST'
    && new URL(request.url()).pathname === '/bot/presence.php'
    && failure === 'net::ERR_ABORTED';
}

function collectDiagnostics(page, slot) {
  const report = {
    slot,
    consoleErrors: [],
    pageErrors: [],
    failedRequests: [],
    serverErrors: [],
  };

  page.on('console', message => {
    if (message.type() === 'error') report.consoleErrors.push(message.text().slice(0, 500));
  });
  page.on('pageerror', error => {
    report.pageErrors.push(String(error?.message || error).slice(0, 500));
  });
  page.on('requestfailed', request => {
    if (!request.url().startsWith(STAGING_ORIGIN)) return;
    // A forced resume intentionally aborts the superseded presence ping and
    // immediately replaces it with a fresh request. Every other failure remains fatal.
    if (isExpectedPresenceResumeAbort(request)) return;
    report.failedRequests.push({
      method: request.method(),
      path: new URL(request.url()).pathname,
      error: String(request.failure()?.errorText || 'request_failed').slice(0, 200),
    });
  });
  page.on('response', response => {
    if (response.url().startsWith(STAGING_ORIGIN) && response.status() >= 500) {
      report.serverErrors.push({ status: response.status(), path: new URL(response.url()).pathname });
    }
  });

  return report;
}

function requestAction(response) {
  try {
    return String(response.request().postDataJSON()?.action || '');
  } catch {
    return '';
  }
}

function isActionResponse(route, action) {
  return response => response.url() === route
    && response.request().method() === 'POST'
    && requestAction(response) === action;
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

async function notificationByInviteToken(page, inviteToken) {
  return page.evaluate(async ({ inviteToken: expectedToken }) => {
    const sessionId = localStorage.getItem('mgw_device_session_id');
    const deviceId = localStorage.getItem('mgw_device_id');
    const response = await fetch('/bot/notifications.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        markRead: true,
        initData: '',
        sessionId,
        deviceId,
      }),
    });
    const payload = await response.json().catch(() => null);
    const items = Array.isArray(payload?.items) ? payload.items : [];
    const item = items.find(candidate =>
      String(candidate?.invite_token || '') === String(expectedToken || '')
    ) || null;
    return {
      status: response.status,
      ok: payload?.ok === true,
      publicError: typeof payload?.error === 'string' ? payload.error.slice(0, 300) : null,
      item,
      availableTokens: items.slice(0, 8).map(candidate => String(candidate?.invite_token || '')),
    };
  }, { inviteToken });
}

async function openPlayer(browser, slot) {
  const context = await browser.newContext({
    locale: 'ru-RU',
    timezoneId: 'Europe/Vilnius',
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 1,
    isMobile: true,
    hasTouch: true,
  });
  await authorizeContext(context, slot);
  const page = await context.newPage();
  const diagnostics = collectDiagnostics(page, slot);
  await openOrdinaryStartReady(page, {
    appRoute: APP_ROUTE,
    apiRoute: API_ROUTE,
    label: `Player ${slot}`,
  });

  return { context, page, diagnostics };
}

async function revokeContext(context) {
  try {
    await context.request.post(AUTH_ROUTE, { data: { action: 'revoke' }, timeout: 15_000 });
  } catch {
    // The short server TTL remains the fallback.
  }
}

async function cleanupPlayer(player) {
  if (!player?.page) return;
  const { page } = player;

  try {
    const gameState = await postFromPlayer(page, '/bot/api.php', { action: 'game_state' });
    const game = gameState.payload?.game || null;
    if (gameState.status === 200 && game?.id && game.status === 'active') {
      await postFromPlayer(page, '/bot/api.php', { action: 'leave_game', gameId: game.id });
    } else if (gameState.status === 200 && gameState.payload?.user?.status === 'searching') {
      await postFromPlayer(page, '/bot/api.php', { action: 'leave_search' });
    }
  } catch {
    // Invitation cleanup below remains independent.
  }

  try {
    const sync = await postFromPlayer(page, '/bot/invites.php', { action: 'sync', token: '' });
    const invite = sync.payload?.invite || sync.payload?.tracked_invite || null;
    if (sync.status === 200
      && invite?.token
      && ['pending', 'accepted', 'awaiting_start'].includes(String(invite.status || ''))) {
      await postFromPlayer(page, '/bot/invites.php', { action: 'cancel', token: invite.token });
    }
  } catch {
    // Server expiry is the final fallback.
  }

  await page.keyboard.press('Escape').catch(() => null);
}

async function clickInviteAction(page, button, action) {
  const responsePromise = page.waitForResponse(isActionResponse(INVITES_ROUTE, action), {
    timeout: 30_000,
  });
  await button.click();
  const response = await responsePromise;
  const payload = await response.json().catch(() => null);
  const publicError = typeof payload?.error === 'string' ? payload.error.slice(0, 300) : 'no_public_error';
  expect(response.status(), `${action} invitation status; public error: ${publicError}`).toBe(200);
  expect(payload?.ok, `${action} invitation payload`).toBe(true);
  return payload;
}

test('D2-D3-D5 integration: Share, picker and owner self-cancel return home while participant history stays terminal', async ({ browser }, testInfo) => {
  test.setTimeout(120_000);
  let playerA;
  let playerB;
  let directToken = '';

  try {
    playerA = await openPlayer(browser, 'A');
    playerB = await openPlayer(browser, 'B');
    await cleanupPlayer(playerA);
    await cleanupPlayer(playerB);

    await playerA.page.evaluate(() => {
      if (window.Telegram?.WebApp) window.Telegram.WebApp.shareMessage = undefined;
    });

    await playerA.page.locator('[data-invite-friend="tictactoe"]').click();
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toContainText('Пригласить в', {
      timeout: 20_000,
    });

    await playerA.page.locator('[data-create-link-invite]').click();
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveText('Ссылка подготовлена', {
      timeout: 25_000,
    });
    await expect(playerA.page.locator('[data-copy-invite-link]')).toBeVisible();
    await expect(playerA.page.locator('[data-discard-draft]')).toBeVisible();

    const discardPromise = playerA.page.waitForResponse(isActionResponse(INVITES_ROUTE, 'discard_draft'), {
      timeout: 30_000,
    });
    await playerA.page.locator('[data-discard-draft]').click();
    const discardResponse = await discardPromise;
    expect(discardResponse.status()).toBe(200);
    expect((await discardResponse.json().catch(() => null))?.ok).toBe(true);
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toContainText('Пригласить в', {
      timeout: 20_000,
    });

    await playerA.page.locator('[data-open-player-picker]').click();
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveText('Выберите игрока', {
      timeout: 25_000,
    });
    const opponent = playerA.page.locator('[data-direct-opponent="stg_test_player_b"]');
    await expect(opponent).toBeVisible({ timeout: 25_000 });
    await expect(opponent).toBeEnabled();

    const createPromise = playerA.page.waitForResponse(isActionResponse(INVITES_ROUTE, 'create_direct'), {
      timeout: 30_000,
    });
    await opponent.click();
    const createResponse = await createPromise;
    const created = await createResponse.json().catch(() => null);
    const createPublicError = typeof created?.error === 'string'
      ? created.error.slice(0, 300)
      : 'no_public_error';
    expect(
      createResponse.status(),
      `create_direct status; public error: ${createPublicError}`,
    ).toBe(200);
    expect(created?.ok, `create_direct payload; public error: ${createPublicError}`).toBe(true);
    directToken = String(created?.invite?.token || '');
    expect(directToken).toMatch(/^[A-Za-z0-9_-]{12,80}$/);
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveText('Приглашение отправлено', {
      timeout: 20_000,
    });

    const cancelButton = playerA.page.locator(
      `[data-invite-action="cancel"][data-invite-token="${directToken}"]`,
    );
    await expect(cancelButton).toBeVisible();
    await expect(cancelButton).toBeEnabled();
    const cancelled = await clickInviteAction(playerA.page, cancelButton, 'cancel');
    expect(['cancelled', 'canceled']).toContain(String(cancelled?.invite?.status || ''));

    const overlay = playerA.page.locator('#sheetOverlay');
    await expect(overlay).not.toHaveClass(/active/, { timeout: 15_000 });
    await expect.poll(async () => playerA.page.evaluate(() => (
      document.querySelector('.screen.active')?.dataset.screen || ''
    )), { timeout: 10_000 }).toBe('home');
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveCount(0);
    await expect(playerA.page.locator(
      `#sheet [data-invite-sheet][data-invite-token="${directToken}"]`,
    )).toHaveCount(0);
    await expect(playerA.page.locator('#notificationToast')).not.toHaveClass(/show/);

    const bNotification = await notificationByInviteToken(playerB.page, directToken);
    expect(
      bNotification.status,
      `Player B terminal notification status; public error: ${bNotification.publicError || 'no_public_error'}`,
    ).toBe(200);
    expect(bNotification.ok, 'Player B terminal notification payload').toBe(true);
    expect(
      bNotification.item,
      `Expected terminal token ${directToken}; first tokens: ${bNotification.availableTokens.join(', ')}`,
    ).toBeTruthy();
    expect(String(bNotification.item?.invite_status || '')).toMatch(/cancelled|canceled/);
    expect(Array.isArray(bNotification.item?.actions) ? bNotification.item.actions : []).toEqual([]);

    expect(playerA.diagnostics.pageErrors).toEqual([]);
    expect(playerB.diagnostics.pageErrors).toEqual([]);
    expect(playerA.diagnostics.failedRequests).toEqual([]);
    expect(playerB.diagnostics.failedRequests).toEqual([]);
    expect(playerA.diagnostics.serverErrors).toEqual([]);
    expect(playerB.diagnostics.serverErrors).toEqual([]);

    await testInfo.attach('d2-d3-d5-integration-report', {
      body: Buffer.from(`${JSON.stringify({
        ok: true,
        ordinaryStartRoute: '/app/v110.php?v=1123',
        shareDraftPreparedAndDiscarded: true,
        playerPickerUsed: true,
        ownerSelfCancelReturnedHome: true,
        ownerTerminalConfirmationAbsent: true,
        actorSelfToastAbsent: true,
        otherParticipantTerminalStatusPresent: true,
        productionChanged: false,
        livePaymentsUsed: false,
      }, null, 2)}\n`, 'utf8'),
      contentType: 'application/json',
    });
  } finally {
    if (playerA) await cleanupPlayer(playerA);
    if (playerB) await cleanupPlayer(playerB);
    if (playerA?.context) {
      await revokeContext(playerA.context);
      await playerA.context.close();
    }
    if (playerB?.context) {
      await revokeContext(playerB.context);
      await playerB.context.close();
    }
  }
});
