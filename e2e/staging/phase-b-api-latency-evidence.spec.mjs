import { test, expect } from '@playwright/test';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = `${STAGING_ORIGIN}/app/`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const TEST_COOKIE = 'mgw_staging_test_session';

function requestAction(response) {
  try {
    return String(response.request().postDataJSON()?.action || '');
  } catch {
    return '';
  }
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
  expect(payload?.ok, `Player ${slot} auth payload`).toBe(true);
  expect(payload?.authorization_mode).toBe('github_actions_oidc');

  const cookies = await context.cookies(STAGING_ORIGIN);
  const cookie = cookies.find((item) => item.name === TEST_COOKIE);
  expect(cookie, `Player ${slot} auth cookie`).toBeTruthy();
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
  const bootstrapPromise = page.waitForResponse((response) => (
    response.url() === API_ROUTE
    && response.request().method() === 'POST'
    && requestAction(response) === 'bootstrap'
  ), { timeout: 35_000 });

  const response = await page.goto(APP_ROUTE, { waitUntil: 'domcontentloaded' });
  expect(response, `Player ${slot} app response`).not.toBeNull();
  expect(response.ok(), `Player ${slot} app status`).toBe(true);

  const bootstrap = await bootstrapPromise;
  expect(bootstrap.status(), `Player ${slot} bootstrap status`).toBe(200);
  const bootstrapPayload = await bootstrap.json().catch(() => null);
  expect(bootstrapPayload?.ok, `Player ${slot} bootstrap payload`).toBe(true);

  await page.waitForFunction(() => (
    typeof localStorage.getItem('mgw_device_session_id') === 'string'
    && localStorage.getItem('mgw_device_session_id').length > 0
    && typeof localStorage.getItem('mgw_device_id') === 'string'
    && localStorage.getItem('mgw_device_id').length > 0
  ), null, { timeout: 20_000 });

  return { context, page, slot };
}

async function timedApi(page, action, extra = {}) {
  return page.evaluate(async ({ requestActionName, requestExtra }) => {
    const sessionId = localStorage.getItem('mgw_device_session_id');
    const deviceId = localStorage.getItem('mgw_device_id');
    const startedAt = performance.now();
    const response = await fetch('/bot/api.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        action: requestActionName,
        ...requestExtra,
        initData: '',
        sessionId,
        deviceId,
      }),
    });
    const payload = await response.json().catch(() => null);
    const finishedAt = performance.now();
    return {
      action: requestActionName,
      duration_ms: Math.round((finishedAt - startedAt) * 10) / 10,
      status: response.status,
      ok: payload?.ok === true,
      error: typeof payload?.error === 'string' ? payload.error.slice(0, 160) : null,
      game_status: typeof payload?.game?.status === 'string' ? payload.game.status : null,
      user_status: typeof payload?.user?.status === 'string' ? payload.user.status : null,
    };
  }, { requestActionName: action, requestExtra: extra });
}

async function cleanupPlayer(player) {
  if (!player?.page) return;
  try {
    const state = await timedApi(player.page, 'game_state');
    if (state.status === 200 && state.ok && state.game_status === 'active') {
      // A game id is intentionally not exposed by the evidence helper, so normal
      // full-suite cleanup remains the authoritative owner for unexpected residue.
      return;
    }
    if (state.status === 200 && state.ok && state.user_status === 'searching') {
      await timedApi(player.page, 'leave_search');
    }
  } catch {
    // Staging test-session TTL and the normal suite cleanup remain final fallback.
  }
}

async function revokeContext(context) {
  try {
    await context.request.post(AUTH_ROUTE, {
      data: { action: 'revoke' },
      timeout: 15_000,
    });
  } catch {
    // Test auth expires server-side if revoke cannot complete.
  }
}

function summarize(samples) {
  const grouped = new Map();
  for (const sample of samples) {
    const list = grouped.get(sample.action) || [];
    list.push(Number(sample.duration_ms || 0));
    grouped.set(sample.action, list);
  }

  const result = {};
  for (const [action, values] of grouped.entries()) {
    const total = values.reduce((sum, value) => sum + value, 0);
    result[action] = {
      count: values.length,
      average_ms: Math.round((total / values.length) * 10) / 10,
      max_ms: Math.round(Math.max(...values) * 10) / 10,
      min_ms: Math.round(Math.min(...values) * 10) / 10,
    };
  }
  return result;
}

test('Phase B records concurrent API latency evidence for both staging players', async ({ browser }, testInfo) => {
  let playerA;
  let playerB;
  const samples = [];

  try {
    [playerA, playerB] = await Promise.all([
      openPlayer(browser, 'A'),
      openPlayer(browser, 'B'),
    ]);

    for (const action of ['profile', 'game_state', 'stats', 'weekly_match_status', 'history']) {
      const [sampleA, sampleB] = await Promise.all([
        timedApi(playerA.page, action),
        timedApi(playerB.page, action),
      ]);

      for (const [slot, sample] of [['A', sampleA], ['B', sampleB]]) {
        expect(sample.status, `Player ${slot} ${action} status`).toBe(200);
        expect(sample.ok, `Player ${slot} ${action} payload`).toBe(true);
        samples.push({
          slot,
          action,
          duration_ms: sample.duration_ms,
          status: sample.status,
          ok: sample.ok,
        });
      }
    }

    const report = {
      ok: true,
      scenario: 'phase_b_concurrent_api_latency_evidence',
      source: 'official_staging_playwright_push',
      players: 2,
      samples,
      summary: summarize(samples),
      production_changed: false,
      sensitive_identifiers_exposed: false,
    };

    console.log(`[MGW_PHASE_B_API_LATENCY] ${JSON.stringify(report.summary)}`);
    await testInfo.attach('phase-b-api-latency-evidence', {
      body: Buffer.from(`${JSON.stringify(report, null, 2)}\n`, 'utf8'),
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
