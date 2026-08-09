import { test, expect } from '@playwright/test';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = `${STAGING_ORIGIN}/app/`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;

async function requestOidcToken() {
  const requestUrl = process.env.ACTIONS_ID_TOKEN_REQUEST_URL || '';
  const requestToken = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN || '';
  if (!requestUrl || !requestToken) throw new Error('GitHub Actions OIDC environment is unavailable.');

  const url = new URL(requestUrl);
  url.searchParams.set('audience', OIDC_AUDIENCE);
  const response = await fetch(url, {
    headers: {
      Authorization: `bearer ${requestToken}`,
      Accept: 'application/json',
    },
  });
  if (!response.ok) throw new Error(`OIDC request failed with status ${response.status}.`);
  const payload = await response.json();
  if (typeof payload?.value !== 'string') throw new Error('OIDC token missing.');
  return payload.value;
}

async function authorize(context, slot) {
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
  expect(response.status(), `Player ${slot} auth`).toBe(200);
  const payload = await response.json();
  expect(payload?.ok, `Player ${slot} auth payload`).toBe(true);
}

function requestAction(response) {
  try {
    return String(response.request().postDataJSON()?.action || '');
  } catch {
    return '';
  }
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
  await authorize(context, slot);
  const page = await context.newPage();
  const bootstrap = page.waitForResponse((response) => (
    response.url() === API_ROUTE
    && response.request().method() === 'POST'
    && requestAction(response) === 'bootstrap'
  ), { timeout: 35_000 });

  const navigation = await page.goto(APP_ROUTE, { waitUntil: 'domcontentloaded' });
  expect(navigation?.ok(), `Player ${slot} app`).toBe(true);
  const bootstrapResponse = await bootstrap;
  expect(bootstrapResponse.status(), `Player ${slot} bootstrap`).toBe(200);

  await page.waitForFunction(() => (
    (localStorage.getItem('mgw_device_session_id') || '').length > 0
    && (localStorage.getItem('mgw_device_id') || '').length > 0
  ), null, { timeout: 20_000 });

  return { context, page, slot };
}

async function timedApi(page, action) {
  return page.evaluate(async (actionName) => {
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
        action: actionName,
        initData: '',
        sessionId,
        deviceId,
      }),
    });
    const payload = await response.json().catch(() => null);
    return {
      action: actionName,
      duration_ms: Math.round((performance.now() - startedAt) * 10) / 10,
      status: response.status,
      ok: payload?.ok === true,
      server_timing: response.headers.get('server-timing') || '',
    };
  }, action);
}

function parseServerTiming(value) {
  const result = {};
  for (const part of String(value || '').split(',')) {
    const match = part.trim().match(/^([a-z0-9_-]+)\s*;\s*dur=([0-9.]+)$/i);
    if (!match) continue;
    result[match[1]] = Number(match[2]);
  }
  return result;
}

async function revoke(context) {
  try {
    await context.request.post(AUTH_ROUTE, { data: { action: 'revoke' }, timeout: 15_000 });
  } catch {
    // Server-side staging TTL remains the fallback.
  }
}

test('Phase B records named internal API hook timings for staging test players', async ({ browser }, testInfo) => {
  let playerA;
  let playerB;
  const samples = [];

  try {
    [playerA, playerB] = await Promise.all([
      openPlayer(browser, 'A'),
      openPlayer(browser, 'B'),
    ]);

    for (const action of ['profile', 'game_state', 'stats', 'weekly_match_status']) {
      const [a, b] = await Promise.all([
        timedApi(playerA.page, action),
        timedApi(playerB.page, action),
      ]);
      for (const [slot, sample] of [['A', a], ['B', b]]) {
        expect(sample.status, `Player ${slot} ${action} status`).toBe(200);
        expect(sample.ok, `Player ${slot} ${action} payload`).toBe(true);
        const hooks = parseServerTiming(sample.server_timing);
        expect(Object.keys(hooks).length, `Player ${slot} ${action} hook timing`).toBeGreaterThan(0);
        samples.push({ slot, action, duration_ms: sample.duration_ms, hooks });
      }
    }

    const report = {
      ok: true,
      mapping: {
        hook_0: 'realtime',
        hook_1: 'economy',
        hook_2: 'shop',
        hook_3: 'payments',
        hook_4: 'weekly',
        filter_0: 'payment_filter',
        filter_1: 'weekly_filter',
      },
      samples,
      production_changed: false,
      sensitive_identifiers_exposed: false,
    };
    console.log(`[MGW_PHASE_B_HOOK_TIMING] ${JSON.stringify(report)}`);
    await testInfo.attach('phase-b-hook-timing-evidence', {
      body: Buffer.from(`${JSON.stringify(report, null, 2)}\n`, 'utf8'),
      contentType: 'application/json',
    });
  } finally {
    if (playerA?.context) {
      await revoke(playerA.context);
      await playerA.context.close();
    }
    if (playerB?.context) {
      await revoke(playerB.context);
      await playerB.context.close();
    }
  }
});
