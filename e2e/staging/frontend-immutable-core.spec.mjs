import { test, expect } from '@playwright/test';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = `${STAGING_ORIGIN}/app/?mgw_e2e_frontend=v115`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const TEST_COOKIE = 'mgw_staging_test_session';
const EXPECTED_BUILD = 'v115-mvp14-d1-feedback-integration';

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

async function authorizeContext(context) {
  const oidcToken = await requestOidcToken();
  const response = await context.request.post(AUTH_ROUTE, {
    headers: {
      Authorization: `Bearer ${oidcToken}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    data: { action: 'issue', slot: 'A' },
    timeout: 35_000,
  });

  expect(response.status(), 'Frontend smoke auth status').toBe(200);
  const payload = await response.json();
  expect(payload).toMatchObject({
    ok: true,
    service: 'mini-games-world-staging-test-auth',
    action: 'issued',
    authorization_mode: 'github_actions_oidc',
    player_slot: 'A',
  });

  const cookies = await context.cookies(STAGING_ORIGIN);
  const cookie = cookies.find((item) => item.name === TEST_COOKIE);
  expect(cookie, 'Frontend smoke auth cookie').toBeTruthy();
  expect(cookie.httpOnly).toBe(true);
  expect(cookie.secure).toBe(true);
  expect(cookie.sameSite).toBe('Strict');
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

async function revokeContext(context) {
  try {
    await context.request.post(AUTH_ROUTE, {
      data: { action: 'revoke' },
      timeout: 15_000,
    });
  } catch {
    // The 15-minute server TTL remains the final fallback.
  }
}

test('staging app root serves one integrated v115 frontend graph', async ({ browser }) => {
  const context = await browser.newContext({
    locale: 'ru-RU',
    timezoneId: 'Europe/Vilnius',
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 1,
    isMobile: true,
    hasTouch: true,
  });

  try {
    await authorizeContext(context);
    const page = await context.newPage();
    const pageErrors = [];
    const consoleErrors = [];
    const failedRequests = [];

    page.on('pageerror', (error) => pageErrors.push(String(error?.message || error)));
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });
    page.on('requestfailed', (request) => {
      if (request.url().startsWith(STAGING_ORIGIN)) {
        failedRequests.push(`${request.method()} ${request.url()} ${request.failure()?.errorText || ''}`);
      }
    });

    const fatalModuleError = new Promise((_, reject) => {
      page.once('pageerror', (error) => {
        reject(new Error(`Frontend module graph failed before bootstrap: ${error?.message || error}`));
      });
    });
    const bootstrapResponse = page.waitForResponse(isBootstrapResponse, { timeout: 35_000 });
    const response = await page.goto(APP_ROUTE, { waitUntil: 'domcontentloaded' });

    expect(response, 'Frontend entry response').not.toBeNull();
    expect(response.ok(), 'Frontend entry status').toBe(true);
    expect(response.headers()['x-mgw-frontend-build']).toBe(EXPECTED_BUILD);
    await expect(page.locator('#app')).toHaveAttribute('data-hotfix-build', EXPECTED_BUILD);

    const bootstrap = await Promise.race([bootstrapResponse, fatalModuleError]);
    const bootstrapPayload = await bootstrap.json().catch(() => null);
    expect(bootstrap.status(), 'Frontend bootstrap status').toBe(200);
    expect(bootstrapPayload?.ok, 'Frontend bootstrap payload').toBe(true);
    expect(bootstrapPayload?.user, 'Frontend bootstrap user').toBeTruthy();
    await page.waitForFunction(() => window.__MGW_FIRST_INTERACTION_READY__ !== undefined, null, {
      timeout: 25_000,
    });
    await expect(page.locator('#preloader')).toBeHidden({ timeout: 10_000 });

    const graph = await page.evaluate(() => ({
      residual: window.__MGW_RESIDUAL_V114__ || null,
      presence: Boolean(window.__MGW_V115_PRESENCE__?.initialized),
      resources: performance.getEntriesByType('resource').map((entry) => entry.name),
    }));

    expect(graph.residual).toEqual({
      uiOwner: false,
      notificationOwner: false,
      shareOwner: false,
      inviteActionOwner: false,
      gameMoveOwner: false,
      gameStateCoalescing: true,
    });
    expect(graph.presence).toBe(true);

    const requiredFreshResources = [
      '/assets/js/main.js?v=115',
      '/assets/js/api/client.js?v=114',
      '/assets/js/session.js?v=114',
      '/assets/js/first-interaction-readiness-v103.js?v=114',
      '/assets/js/residual-ui-game-race-fix-v114.js?v=114',
      '/assets/js/interaction-latency-coordinator-v101.js?v=114',
      '/assets/js/screens/notifications-screen-v99.js?v=114',
      '/assets/js/games/game-invites.js?v=114',
      '/assets/js/screens/notification-empty-frame-guard-v115.js?v=115',
      '/assets/js/screens/notification-bell-first-click-v116.js?v=116',
      '/assets/js/opponents-native-fetch-v115.js?v=115',
      '/assets/js/opponents-empty-cache-guard-v115.js?v=115',
      '/assets/js/presence-v115.js?v=115',
      '/assets/js/games/invite-terminal-actions-v115.js?v=115',
      '/assets/js/games/invite-link-entry-v115.js?v=115',
    ];
    for (const suffix of requiredFreshResources) {
      expect(
        graph.resources.some((url) => new URL(url).pathname.concat(new URL(url).search).endsWith(suffix)),
        `Fresh module graph must include ${suffix}`,
      ).toBe(true);
    }

    expect(pageErrors).toEqual([]);
    expect(consoleErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
  } finally {
    await revokeContext(context);
    await context.close();
  }
});
