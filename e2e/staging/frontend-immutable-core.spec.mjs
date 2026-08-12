import { test, expect } from '@playwright/test';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = `${STAGING_ORIGIN}/app/?mgw_e2e_frontend=d1-canonical`;
const STALE_APP_ROUTE = `${STAGING_ORIGIN}/app/?v=85`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const TEST_COOKIE = 'mgw_staging_test_session';
const EXPECTED_BUILD = 'd1-bootstrap-authoritative-owner';
const EXPECTED_PHASE_B_BUILD = 'phase-b-current-v124-ttt-turn-ready-clock';
const EXPECTED_ENTRY_VERSION = 'v122';

async function requestOidcToken() {
  const requestUrl = process.env.ACTIONS_ID_TOKEN_REQUEST_URL || '';
  const requestToken = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN || '';
  if (!requestUrl || !requestToken) throw new Error('GitHub Actions OIDC environment is unavailable.');
  const url = new URL(requestUrl);
  url.searchParams.set('audience', OIDC_AUDIENCE);
  const response = await fetch(url, { headers:{ Authorization:`bearer ${requestToken}`, Accept:'application/json' } });
  if (!response.ok) throw new Error(`OIDC token request failed: ${response.status}`);
  const payload = await response.json();
  if (typeof payload?.value !== 'string') throw new Error('OIDC token is unavailable.');
  return payload.value;
}

async function authorizeContext(context) {
  const token = await requestOidcToken();
  const response = await context.request.post(AUTH_ROUTE, {
    headers:{ Authorization:`Bearer ${token}`, Accept:'application/json', 'Content-Type':'application/json' },
    data:{ action:'issue', slot:'A' }, timeout:35_000,
  });
  expect(response.status()).toBe(200);
  expect(await response.json()).toMatchObject({ ok:true, action:'issued', player_slot:'A' });
  expect((await context.cookies(STAGING_ORIGIN)).some(item => item.name === TEST_COOKIE)).toBe(true);
}

function requestAction(response) {
  try { return String(response.request().postDataJSON()?.action || ''); }
  catch { return ''; }
}

test('staging app serves one canonical notification, player-picker and Phase B launch graph', async ({ browser }) => {
  const context = await browser.newContext({
    locale:'ru-RU', timezoneId:'Europe/Vilnius', viewport:{ width:390, height:844 },
    deviceScaleFactor:1, isMobile:true, hasTouch:true,
  });
  try {
    await authorizeContext(context);

    const staleEntry = await context.request.get(STALE_APP_ROUTE, { maxRedirects:0, timeout:35_000 });
    expect(staleEntry.status()).toBe(302);
    expect(staleEntry.headers().location).toBe('./?v=122');
    expect(staleEntry.headers()['cache-control']).toContain('no-store');

    const page = await context.newPage();
    const pageErrors = [];
    const failedRequests = [];
    page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
    page.on('requestfailed', request => {
      if (request.url().startsWith(STAGING_ORIGIN)) failedRequests.push(`${request.method()} ${request.url()} ${request.failure()?.errorText || ''}`);
    });
    const bootstrap = page.waitForResponse(response => response.url() === API_ROUTE
      && response.request().method() === 'POST' && requestAction(response) === 'bootstrap', { timeout:35_000 });
    const response = await page.goto(APP_ROUTE, { waitUntil:'domcontentloaded' });
    expect(response?.ok()).toBe(true);
    expect(response.headers()['x-mgw-frontend-build']).toBe(EXPECTED_BUILD);
    expect(response.headers()['x-mgw-phase-b-build']).toBe(EXPECTED_PHASE_B_BUILD);
    expect(response.headers()['x-mgw-entry-version']).toBe(EXPECTED_ENTRY_VERSION);
    await expect(page.locator('#app')).toHaveAttribute('data-hotfix-build', EXPECTED_BUILD);
    expect((await bootstrap).status()).toBe(200);
    await expect(page.locator('#preloader')).toBeHidden({ timeout:20_000 });
    expect(await page.evaluate(() => window.__MGW_PHASE_B_BUILD__)).toBe(EXPECTED_PHASE_B_BUILD);
    await expect(page.locator('#mgwPhaseBLaunchOverlay')).toBeHidden();

    const presentation = await page.evaluate(() => {
      const overlay = document.getElementById('mgwPhaseBLaunchOverlay');
      const card = overlay?.querySelector('.mgw-phase-b-launch-card');
      const countdown = overlay?.querySelector('[data-phase-b-countdown]');
      const title = overlay?.querySelector('[data-phase-b-title]');
      const progress = overlay?.querySelector('[data-phase-b-progress]');
      if (!overlay || !card || !countdown || !title || !progress) return null;
      overlay.hidden = false;
      countdown.hidden = false;
      countdown.dataset.loading = '1';
      countdown.dataset.ready = '0';
      progress.dataset.visible = '1';
      const cardStyle = getComputedStyle(card);
      const countdownStyle = getComputedStyle(countdown);
      const titleStyle = getComputedStyle(title);
      const numbered = {
        width: Math.round(countdown.getBoundingClientRect().width),
        height: Math.round(countdown.getBoundingClientRect().height),
      };
      countdown.dataset.loading = '0';
      countdown.dataset.ready = '1';
      progress.dataset.visible = '0';
      const ready = {
        width: Math.round(countdown.getBoundingClientRect().width),
        height: Math.round(countdown.getBoundingClientRect().height),
        progressVisibility: getComputedStyle(progress).visibility,
      };
      const result = {
        cardHeight: Math.round(card.getBoundingClientRect().height),
        cardDisplay: cardStyle.display,
        countdownBackground: countdownStyle.backgroundColor,
        titleWhiteSpace: titleStyle.whiteSpace,
        numbered,
        ready,
      };
      countdown.hidden = true;
      progress.dataset.visible = '1';
      overlay.hidden = true;
      return result;
    });
    expect(presentation).toEqual({
      cardHeight:336,
      cardDisplay:'grid',
      countdownBackground:'rgba(6, 8, 13, 0.96)',
      titleWhiteSpace:'nowrap',
      numbered:{ width:108, height:108 },
      ready:{ width:108, height:108, progressVisibility:'hidden' },
    });

    const resources = await page.evaluate(() => performance.getEntriesByType('resource').map(entry => entry.name));
    const has = suffix => resources.some(url => new URL(url).pathname.concat(new URL(url).search).endsWith(suffix));
    for (const required of [
      '/assets/js/main.js?v=d1-bootstrap-authoritative-owner',
      '/assets/js/api/client.js?v=114',
      '/assets/js/session.js?v=114',
      '/assets/js/first-interaction-readiness.js?v=d1',
      '/assets/js/screens/notifications-screen-v99.js?v=d1-bell-single-owner',
      '/assets/js/games/game-invites.js?v=d1',
      '/assets/js/games/invite-link-entry-v115.js?v=d1',
      '/assets/js/presence-v115.js?v=115',
      '/assets/js/games/invite-terminal-actions-v115.js?v=115',
      '/assets/js/phase-b-current-entry.js?v=124&ttt=turn-ready-clock',
      '/assets/js/phase-b-current-runtime.js?v=124&ttt=turn-ready-clock',
      '/assets/js/screens/game-screen-phase-b-current.js?v=118&ttt=turn-ready-clock',
    ]) expect(has(required), `Canonical graph must include ${required}`).toBe(true);

    for (const retired of [
      '/assets/js/first-interaction-readiness-v103.js',
      '/assets/js/screens/notifications-passive-v130.js',
      '/assets/js/notification-deeplink-toast-policy-v131.js',
      '/assets/js/screens/notification-window-owner-v121.js',
      '/assets/js/notification-compat-click-guard-v127.js',
      '/assets/js/opponents-native-fetch-v115.js',
      '/assets/js/opponents-empty-cache-guard-v115.js',
      '/assets/js/opponents-authoritative-confirm-v122.js',
      '/assets/js/opponents-fresh-user-action-v128.js',
      '/assets/js/main-v110.js',
      '/assets/js/production-v110-readonly-game-sync.js',
    ]) expect(resources.some(url => new URL(url).pathname.endsWith(retired)), `Canonical graph must exclude ${retired}`).toBe(false);

    expect(pageErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
  } finally {
    try { await context.request.post(AUTH_ROUTE, { data:{ action:'revoke' }, timeout:15_000 }); } catch {}
    await context.close();
  }
});