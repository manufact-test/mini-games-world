import { createHash } from 'node:crypto';
import { test, expect } from '@playwright/test';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = `${STAGING_ORIGIN}/app/`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const INVITES_ROUTE = `${STAGING_ORIGIN}/bot/invites.php`;
const TEST_COOKIE = 'mgw_staging_test_session';

function sha256(value) {
  return createHash('sha256').update(String(value)).digest('hex');
}

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
  const cookie = cookies.find((item) => item.name === TEST_COOKIE);
  expect(cookie, `Player ${slot} auth cookie`).toBeTruthy();
  expect(cookie.httpOnly).toBe(true);
  expect(cookie.secure).toBe(true);
  expect(cookie.sameSite).toBe('Strict');
}

function collectDiagnostics(page, slot) {
  const report = {
    slot,
    consoleErrors: [],
    pageErrors: [],
    failedRequests: [],
    serverErrors: [],
  };

  page.on('console', (message) => {
    if (message.type() === 'error') report.consoleErrors.push(message.text().slice(0, 500));
  });
  page.on('pageerror', (error) => {
    report.pageErrors.push(String(error?.message || error).slice(0, 500));
  });
  page.on('requestfailed', (request) => {
    if (!request.url().startsWith(STAGING_ORIGIN)) return;
    report.failedRequests.push({
      method: request.method(),
      url: new URL(request.url()).pathname,
      error: String(request.failure()?.errorText || 'request_failed').slice(0, 200),
    });
  });
  page.on('response', (response) => {
    if (!response.url().startsWith(STAGING_ORIGIN) || response.status() < 500) return;
    report.serverErrors.push({
      status: response.status(),
      url: new URL(response.url()).pathname,
    });
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

function isBootstrapResponse(response) {
  return response.url() === API_ROUTE
    && response.request().method() === 'POST'
    && requestAction(response) === 'bootstrap';
}

async function waitForApplicationBootstrap(page, slot) {
  const response = await page.waitForResponse(isBootstrapResponse, { timeout: 35_000 });
  const payload = await response.json().catch(() => null);
  expect(response.status(), `Player ${slot} bootstrap status`).toBe(200);
  expect(payload?.ok, `Player ${slot} bootstrap payload`).toBe(true);
  expect(payload?.user, `Player ${slot} bootstrap user`).toBeTruthy();
  return payload;
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
  const bootstrapPromise = waitForApplicationBootstrap(page, slot);
  const response = await page.goto(APP_ROUTE, { waitUntil: 'domcontentloaded' });

  expect(response, `Player ${slot} app response`).not.toBeNull();
  expect(response.ok(), `Player ${slot} app status`).toBe(true);
  await expect(page).toHaveTitle(/Mini Games World/i);
  await bootstrapPromise;
  await page.waitForFunction(() => window.__MGW_FIRST_INTERACTION_READY__ !== undefined, null, {
    timeout: 25_000,
  });

  return { context, page, diagnostics };
}

async function postFromPlayer(page, pathname, data) {
  return page.evaluate(async ({ pathname: requestPath, data: requestData }) => {
    const sessionId = localStorage.getItem('mgw_device_session_id');
    const deviceId = localStorage.getItem('mgw_device_id');
    const response = await fetch(requestPath, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
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

async function cleanupPlayer(player) {
  if (!player?.page) return;

  try {
    const sync = await postFromPlayer(player.page, '/bot/invites.php', {
      action: 'sync',
      token: '',
    });
    const invite = sync.payload?.invite || sync.payload?.tracked_invite || null;
    if (sync.status === 200
      && invite?.token
      && ['pending', 'accepted', 'awaiting_start'].includes(String(invite.status || ''))) {
      await postFromPlayer(player.page, '/bot/invites.php', {
        action: 'cancel',
        token: invite.token,
      });
    }
  } catch {
    // The short staging session TTL is the final cleanup fallback.
  }

  await player.page.keyboard.press('Escape').catch(() => null);
}

async function revokeContext(context) {
  try {
    await context.request.post(AUTH_ROUTE, {
      data: { action: 'revoke' },
      timeout: 15_000,
    });
  } catch {
    // The 15-minute server TTL remains the fallback.
  }
}

async function beginFrameCapture(page, label) {
  await page.evaluate((captureLabel) => {
    window.__MGW_D1_NOTIFICATION_OBSERVER__?.disconnect?.();
    window.__MGW_D1_NOTIFICATION_FRAMES__ = [];

    const capture = () => {
      const sheet = document.getElementById('sheet');
      const overlay = document.getElementById('sheetOverlay');
      window.__MGW_D1_NOTIFICATION_FRAMES__.push({
        label: captureLabel,
        overlayActive: overlay?.classList.contains('active') === true,
        heading: String(sheet?.querySelector('.sheet-head h2')?.textContent || '').trim(),
        empty: String(sheet?.querySelector('.notifications-empty strong')?.textContent || '').trim(),
        bodyText: String(sheet?.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 2000),
        actions: Array.from(sheet?.querySelectorAll('[data-invite-action]') || []).map((element) => ({
          action: String(element.getAttribute('data-invite-action') || ''),
          token: String(element.getAttribute('data-invite-token') || ''),
        })),
      });
    };

    const sheet = document.getElementById('sheet');
    if (!sheet) throw new Error('Notification sheet root is unavailable.');
    const observer = new MutationObserver(capture);
    observer.observe(sheet, {
      childList: true,
      subtree: true,
      characterData: true,
      attributes: true,
    });
    window.__MGW_D1_NOTIFICATION_OBSERVER__ = observer;
    capture();
  }, label);
}

async function finishFrameCapture(page) {
  return page.evaluate(() => {
    window.__MGW_D1_NOTIFICATION_OBSERVER__?.disconnect?.();
    window.__MGW_D1_NOTIFICATION_OBSERVER__ = null;
    return Array.isArray(window.__MGW_D1_NOTIFICATION_FRAMES__)
      ? window.__MGW_D1_NOTIFICATION_FRAMES__
      : [];
  });
}

function expectFreshPendingFrames(frames, token, label) {
  expect(frames.length, `${label} captured frames`).toBeGreaterThan(0);
  expect(
    frames.some((frame) => /Пока уведомлений нет|0 уведомлений/i.test(frame.empty || frame.bodyText)),
    `${label} must not render a false empty notifications frame`,
  ).toBe(false);
  expect(
    frames.some((frame) => /Приглашение отменено|Приглашение отклонено/i.test(frame.bodyText)),
    `${label} must not render a stale terminal invitation`,
  ).toBe(false);
  expect(
    frames.some((frame) => frame.actions.some((item) => (
      item.action === 'accept' && item.token === token
    ))),
    `${label} must render the exact current actionable invitation`,
  ).toBe(true);
}

test('D1 notification toast and bell show one fresh actionable invitation', async ({ browser }, testInfo) => {
  let playerA;
  let playerB;
  let inviteToken = '';

  try {
    playerA = await openPlayer(browser, 'A');
    playerB = await openPlayer(browser, 'B');
    await cleanupPlayer(playerA);
    await cleanupPlayer(playerB);

    const created = await expectPlayerRequest(
      playerA.page,
      INVITES_ROUTE.replace(STAGING_ORIGIN, ''),
      {
        action: 'create_direct',
        inviteeId: 'stg_test_player_b',
        gameType: 'tictactoe',
        room: 'match',
        bet: 10,
        boardSize: 3,
      },
      'Player A D1 direct invitation',
    );

    inviteToken = String(created.invite?.token || '');
    expect(inviteToken).toMatch(/^[A-Za-z0-9_-]{12,80}$/);
    expect(created.invite?.status).toBe('pending');

    await beginFrameCapture(playerB.page, 'blue-toast-open');
    const notificationToast = playerB.page.locator('#notificationToast.show');
    await expect.poll(async () => {
      await playerB.page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
      return notificationToast.isVisible();
    }, {
      timeout: 20_000,
      intervals: [250, 500, 1000],
      message: 'Player B must receive the blue invitation notification toast',
    }).toBe(true);

    await expect(notificationToast.locator('.notification-toast-copy strong')).not.toHaveText('');
    await notificationToast.click();

    const exactAccept = playerB.page.locator(
      `[data-invite-action="accept"][data-invite-token="${inviteToken}"]`,
    );
    await expect(playerB.page.locator('#sheet .sheet-head h2')).toHaveText('Уведомления', {
      timeout: 20_000,
    });
    await expect(exactAccept).toBeVisible({ timeout: 25_000 });
    await expect(exactAccept).toBeEnabled();

    const toastFrames = await finishFrameCapture(playerB.page);
    expectFreshPendingFrames(toastFrames, inviteToken, 'Blue toast first open');

    await playerB.page.locator('#sheet [data-close-sheet]').click();
    await expect(playerB.page.locator('#sheetOverlay')).not.toHaveClass(/active/, {
      timeout: 10_000,
    });

    await playerB.page.evaluate(() => {
      document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await playerB.page.waitForTimeout(750);
    await expect(playerB.page.locator('#sheetOverlay')).not.toHaveClass(/active/);

    await beginFrameCapture(playerB.page, 'bell-reopen');
    await playerB.page.locator('#notificationsOpen').click();
    await expect(playerB.page.locator('#sheet .sheet-head h2')).toHaveText('Уведомления', {
      timeout: 20_000,
    });
    await expect(exactAccept).toBeVisible({ timeout: 25_000 });
    await expect(exactAccept).toBeEnabled();

    const bellFrames = await finishFrameCapture(playerB.page);
    expectFreshPendingFrames(bellFrames, inviteToken, 'Bell deliberate reopen');
    await expect(notificationToast).not.toBeVisible();

    expect(playerA.diagnostics.pageErrors).toEqual([]);
    expect(playerB.diagnostics.pageErrors).toEqual([]);
    expect(playerA.diagnostics.failedRequests).toEqual([]);
    expect(playerB.diagnostics.failedRequests).toEqual([]);
    expect(playerA.diagnostics.serverErrors).toEqual([]);
    expect(playerB.diagnostics.serverErrors).toEqual([]);

    const safeReport = {
      ok: true,
      scenario: 'deferred_d1_notification_first_frame',
      invitationTokenSha256: sha256(inviteToken),
      blueToastVisible: true,
      exactPendingCardVisible: true,
      falseEmptyFrameObserved: false,
      staleTerminalFrameObserved: false,
      remainedClosedAfterDismissal: true,
      deliberateBellReopenFresh: true,
      productionChanged: false,
      livePaymentsUsed: false,
      diagnostics: {
        A: playerA.diagnostics,
        B: playerB.diagnostics,
      },
    };
    await testInfo.attach('staging-deferred-d1-notification-report', {
      body: Buffer.from(`${JSON.stringify(safeReport, null, 2)}\n`, 'utf8'),
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
