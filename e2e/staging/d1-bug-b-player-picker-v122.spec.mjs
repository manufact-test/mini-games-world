import { test, expect } from '@playwright/test';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = `${STAGING_ORIGIN}/app/`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const OPPONENTS_ROUTE = `${STAGING_ORIGIN}/bot/invite-opponents.php`;
const TEST_COOKIE = 'mgw_staging_test_session';

async function requestOidcToken() {
  const requestUrl = process.env.ACTIONS_ID_TOKEN_REQUEST_URL || '';
  const requestToken = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN || '';
  if (!requestUrl || !requestToken) throw new Error('GitHub Actions OIDC environment is unavailable.');
  const url = new URL(requestUrl);
  url.searchParams.set('audience', OIDC_AUDIENCE);
  const response = await fetch(url, { headers:{ Authorization:`bearer ${requestToken}`, Accept:'application/json' } });
  if (!response.ok) throw new Error(`OIDC request failed: ${response.status}`);
  return (await response.json()).value;
}

async function authorizeContext(context, slot) {
  const token = await requestOidcToken();
  const response = await context.request.post(AUTH_ROUTE, {
    headers:{ Authorization:`Bearer ${token}`, Accept:'application/json', 'Content-Type':'application/json' },
    data:{ action:'issue', slot }, timeout:35_000,
  });
  expect(response.status()).toBe(200);
  expect((await context.cookies(STAGING_ORIGIN)).some(item => item.name === TEST_COOKIE)).toBe(true);
}

function requestAction(response) {
  try { return String(response.request().postDataJSON()?.action || ''); }
  catch { return ''; }
}

async function openPlayer(browser, slot, isMobile) {
  const context = await browser.newContext({
    locale:'ru-RU', timezoneId:'Europe/Vilnius',
    viewport:isMobile ? { width:390, height:844 } : { width:1280, height:900 },
    deviceScaleFactor:1, isMobile, hasTouch:isMobile,
  });
  await authorizeContext(context, slot);
  const page = await context.newPage();
  const bootstrap = page.waitForResponse(response => response.url() === API_ROUTE
    && response.request().method() === 'POST' && requestAction(response) === 'bootstrap', { timeout:35_000 });
  const response = await page.goto(APP_ROUTE, { waitUntil:'domcontentloaded' });
  expect(response?.ok()).toBe(true);
  expect((await bootstrap).status()).toBe(200);
  await expect(page.locator('#preloader')).toBeHidden({ timeout:20_000 });
  return { context, page };
}

async function runCanonicalPicker(browser, isMobile) {
  const player = await openPlayer(browser, 'A', isMobile);
  let requests = 0;
  let seenHeaders = null;
  try {
    await player.page.route(OPPONENTS_ROUTE, async route => {
      requests += 1;
      seenHeaders = route.request().headers();
      await new Promise(resolve => setTimeout(resolve, 350));
      await route.fulfill({
        status:200,
        contentType:'application/json; charset=utf-8',
        body:JSON.stringify({
          ok:true, authoritative:true, storage_driver:'database',
          items:[{ id:'stg_test_player_b', name:'TEST PLAYER B', activity:'онлайн', online:true, busy:false }],
        }),
      });
    });

    await player.page.locator('[data-invite-friend="tictactoe"]').click();
    await expect(player.page.locator('[data-open-player-picker]')).toBeVisible();
    await player.page.locator('[data-open-player-picker]').click();

    await expect(player.page.locator('[data-player-picker-state="loading"]')).toBeVisible();
    await expect(player.page.locator('[data-player-picker-state="empty"]')).toHaveCount(0);
    await expect(player.page.locator('[data-direct-opponent="stg_test_player_b"]')).toBeVisible({ timeout:5_000 });
    await expect(player.page.locator('[data-player-picker-state="loaded"]')).toBeVisible();
    expect(requests).toBe(1);
    expect(seenHeaders?.['cache-control']).toContain('no-store');
    expect(seenHeaders?.['x-mgw-opponents-source']).toBe('manual-player-picker');
  } finally {
    try { await player.context.request.post(AUTH_ROUTE, { data:{ action:'revoke' }, timeout:15_000 }); } catch {}
    await player.context.close();
  }
}

test('canonical desktop picker uses one fresh request and never paints empty while loading', async ({ browser }) => {
  await runCanonicalPicker(browser, false);
});

test('canonical mobile Chromium picker uses one fresh request and never paints empty while loading', async ({ browser }) => {
  await runCanonicalPicker(browser, true);
});
