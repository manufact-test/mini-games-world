import { createHash } from 'node:crypto';
import { test, expect } from '@playwright/test';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = `${STAGING_ORIGIN}/app/`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
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
    data: {
      action: 'issue',
      slot,
    },
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
    ttl_seconds: 900,
    cookie: {
      http_only: true,
      secure: true,
      same_site: 'Strict',
    },
  });

  const cookies = await context.cookies(STAGING_ORIGIN);
  const cookie = cookies.find((item) => item.name === TEST_COOKIE);
  expect(cookie, `Player ${slot} auth cookie`).toBeTruthy();
  expect(cookie.httpOnly).toBe(true);
  expect(cookie.secure).toBe(true);
  expect(cookie.sameSite).toBe('Strict');
  expect(cookie.value).toMatch(/^mgwstg_[A-Za-z0-9_-]{40,80}$/);
  return cookie;
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
    if (message.type() === 'error') {
      report.consoleErrors.push(message.text().slice(0, 500));
    }
  });
  page.on('pageerror', (error) => {
    report.pageErrors.push(String(error?.message || error).slice(0, 500));
  });
  page.on('requestfailed', (request) => {
    if (request.url().startsWith(STAGING_ORIGIN)) {
      report.failedRequests.push({
        method: request.method(),
        url: new URL(request.url()).pathname,
        error: String(request.failure()?.errorText || 'request_failed').slice(0, 200),
      });
    }
  });
  page.on('response', (response) => {
    if (response.url().startsWith(STAGING_ORIGIN) && response.status() >= 500) {
      report.serverErrors.push({
        status: response.status(),
        url: new URL(response.url()).pathname,
      });
    }
  });

  return report;
}

async function readPlayerSnapshot(page) {
  await page.waitForFunction(() => (
    typeof localStorage.getItem('mgw_device_session_id') === 'string'
    && localStorage.getItem('mgw_device_session_id').length > 0
    && typeof localStorage.getItem('mgw_device_id') === 'string'
    && localStorage.getItem('mgw_device_id').length > 0
  ));

  return page.evaluate(async () => {
    const sessionId = localStorage.getItem('mgw_device_session_id');
    const deviceId = localStorage.getItem('mgw_device_id');
    const response = await fetch('/bot/api.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        action: 'profile',
        initData: '',
        sessionId,
        deviceId,
      }),
    });
    const payload = await response.json();
    return {
      status: response.status,
      ok: payload?.ok === true,
      user: payload?.user || null,
      session: payload?.session || null,
      sessionId,
      deviceId,
      localStorageKeys: Object.keys(localStorage).sort(),
      sessionStorageKeys: Object.keys(sessionStorage).sort(),
    };
  });
}

async function openPlayer(browser, slot, testInfo) {
  const context = await browser.newContext({
    locale: 'ru-RU',
    timezoneId: 'Europe/Vilnius',
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 1,
    isMobile: true,
    hasTouch: true,
  });
  const cookie = await authorizeContext(context, slot);
  const page = await context.newPage();
  const diagnostics = collectDiagnostics(page, slot);

  const response = await page.goto(APP_ROUTE, { waitUntil: 'domcontentloaded' });
  expect(response, `Player ${slot} app response`).not.toBeNull();
  expect(response.ok(), `Player ${slot} app status`).toBe(true);
  await expect(page).toHaveTitle(/Mini Games World/i);
  await expect(page.locator('body')).toBeVisible();

  const snapshot = await readPlayerSnapshot(page);
  expect(snapshot.status).toBe(200);
  expect(snapshot.ok).toBe(true);
  expect(snapshot.user).toBeTruthy();
  expect(snapshot.session?.locked ?? false).toBe(false);

  await page.screenshot({
    path: testInfo.outputPath(`player-${slot.toLowerCase()}-home.png`),
    fullPage: true,
  });

  return { context, page, cookie, diagnostics, snapshot };
}

async function revokeContext(context) {
  try {
    await context.request.post(AUTH_ROUTE, {
      data: { action: 'revoke' },
      timeout: 15_000,
    });
  } catch {
    // The context is still closed below; the 15-minute server TTL is the fallback.
  }
}

test('TEST PLAYER A and B run in isolated browser contexts', async ({ browser }, testInfo) => {
  let playerA;
  let playerB;
  let replayContext;

  try {
    playerA = await openPlayer(browser, 'A', testInfo);
    playerB = await openPlayer(browser, 'B', testInfo);

    expect(playerA.snapshot.user.id).toBe('stg_test_player_a');
    expect(playerB.snapshot.user.id).toBe('stg_test_player_b');
    expect(playerA.snapshot.user.id).not.toBe(playerB.snapshot.user.id);
    expect(playerA.snapshot.user.username).toBe('mgw_test_player_a');
    expect(playerB.snapshot.user.username).toBe('mgw_test_player_b');

    expect(playerA.snapshot.sessionId).not.toBe(playerB.snapshot.sessionId);
    expect(playerA.snapshot.deviceId).not.toBe(playerB.snapshot.deviceId);
    expect(playerA.cookie.value).not.toBe(playerB.cookie.value);

    expect(playerA.diagnostics.pageErrors).toEqual([]);
    expect(playerB.diagnostics.pageErrors).toEqual([]);
    expect(playerA.diagnostics.failedRequests).toEqual([]);
    expect(playerB.diagnostics.failedRequests).toEqual([]);
    expect(playerA.diagnostics.serverErrors).toEqual([]);
    expect(playerB.diagnostics.serverErrors).toEqual([]);

    replayContext = await browser.newContext();
    await replayContext.addCookies([playerA.cookie]);
    const replay = await replayContext.request.post(API_ROUTE, {
      data: {
        action: 'profile',
        initData: '',
        sessionId: 'sess_replay_context',
        deviceId: 'device_replay_context',
      },
      timeout: 20_000,
    });
    expect(replay.status()).toBeGreaterThanOrEqual(400);
    const replayPayload = await replay.json();
    expect(replayPayload?.ok).toBe(false);

    const safeReport = {
      ok: true,
      stagingOrigin: STAGING_ORIGIN,
      players: {
        A: {
          id: playerA.snapshot.user.id,
          sessionIdSha256: sha256(playerA.snapshot.sessionId),
          deviceIdSha256: sha256(playerA.snapshot.deviceId),
          localStorageKeys: playerA.snapshot.localStorageKeys,
          sessionStorageKeys: playerA.snapshot.sessionStorageKeys,
        },
        B: {
          id: playerB.snapshot.user.id,
          sessionIdSha256: sha256(playerB.snapshot.sessionId),
          deviceIdSha256: sha256(playerB.snapshot.deviceId),
          localStorageKeys: playerB.snapshot.localStorageKeys,
          sessionStorageKeys: playerB.snapshot.sessionStorageKeys,
        },
      },
      isolation: {
        accountsDistinct: true,
        cookiesDistinct: true,
        sessionsDistinct: true,
        devicesDistinct: true,
        copiedCookieReplayRejected: true,
      },
      diagnostics: {
        A: playerA.diagnostics,
        B: playerB.diagnostics,
      },
      productionChanged: false,
      livePaymentsUsed: false,
    };

    await testInfo.attach('staging-two-context-report', {
      body: Buffer.from(`${JSON.stringify(safeReport, null, 2)}\n`, 'utf8'),
      contentType: 'application/json',
    });
  } finally {
    if (replayContext) await replayContext.close();
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
