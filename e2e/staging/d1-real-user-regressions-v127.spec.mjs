import { test, expect } from '@playwright/test';
import { telegramAppRoute } from './support/telegram-launch-route.mjs';
import { openOrdinaryStartReady } from './support/ordinary-start-readiness.mjs';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = telegramAppRoute(STAGING_ORIGIN);
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const OPPONENTS_ROUTE = `${STAGING_ORIGIN}/bot/invite-opponents.php`;
const TEST_COOKIE = 'mgw_staging_test_session';

async function requestOidcToken() {
  const requestUrl = process.env.ACTIONS_ID_TOKEN_REQUEST_URL || '';
  const requestToken = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN || '';
  if (!requestUrl || !requestToken) throw new Error('GitHub Actions OIDC environment is unavailable.');
  const url = new URL(requestUrl);
  url.searchParams.set('audience', OIDC_AUDIENCE);
  const response = await fetch(url, {
    headers:{ Authorization:`bearer ${requestToken}`, Accept:'application/json' },
  });
  if (!response.ok) throw new Error(`OIDC request failed: ${response.status}`);
  const payload = await response.json();
  if (typeof payload?.value !== 'string') throw new Error('OIDC token is unavailable.');
  return payload.value;
}

async function authorizeContext(context, slot = 'A') {
  const oidcToken = await requestOidcToken();
  const response = await context.request.post(AUTH_ROUTE, {
    headers:{
      Authorization:`Bearer ${oidcToken}`,
      Accept:'application/json',
      'Content-Type':'application/json',
    },
    data:{ action:'issue', slot },
    timeout:35_000,
  });
  expect(response.status()).toBe(200);
  expect(await response.json()).toMatchObject({ ok:true, action:'issued', player_slot:slot });
  expect((await context.cookies(STAGING_ORIGIN)).some(item => item.name === TEST_COOKIE)).toBe(true);
}

async function openOrdinaryStart(browser) {
  const context = await browser.newContext({
    locale:'ru-RU',
    timezoneId:'Europe/Vilnius',
    viewport:{ width:1280, height:900 },
    deviceScaleFactor:1,
  });
  await authorizeContext(context, 'A');
  const page = await context.newPage();
  await openOrdinaryStartReady(page, {
    appRoute: APP_ROUTE,
    apiRoute: API_ROUTE,
    label: 'Player A',
  });
  await expect(page.locator('#sheetOverlay')).not.toHaveClass(/active/);
  return { context, page };
}

async function revokeAndClose(context) {
  try {
    await context.request.post(AUTH_ROUTE, { data:{ action:'revoke' }, timeout:15_000 });
  } catch {}
  await context.close();
}

test('canonical ordinary Start bell reopens immediately for 25 click cycles', async ({ browser }) => {
  const player = await openOrdinaryStart(browser);
  try {
    for (let cycle = 0; cycle < 25; cycle += 1) {
      await player.page.locator('#notificationsOpen').click({ timeout:5_000 });
      await expect(player.page.locator('#sheetOverlay')).toHaveClass(/active/);
      await expect(player.page.locator('#sheet .sheet-head h2')).toHaveText('Уведомления');
      await expect(player.page.locator('#sheet [data-notifications-sheet]')).toHaveCount(1);
      await player.page.locator('#sheet [data-close-sheet]').click({ timeout:5_000 });
      await expect(player.page.locator('#sheetOverlay')).not.toHaveClass(/active/);
    }
  } finally {
    await revokeAndClose(player.context);
  }
});

test('canonical manual player picker performs no boot fetch and one fresh request', async ({ browser }) => {
  const context = await browser.newContext({
    locale:'ru-RU',
    timezoneId:'Europe/Vilnius',
    viewport:{ width:1280, height:900 },
    deviceScaleFactor:1,
  });
  let opponentCalls = 0;

  await context.route(OPPONENTS_ROUTE, async route => {
    opponentCalls += 1;
    await route.fulfill({
      status:200,
      contentType:'application/json; charset=utf-8',
      body:JSON.stringify({
        ok:true,
        authoritative:true,
        storage_driver:'database',
        items:[{
          id:'stg_test_player_b',
          name:'@mgw_test_player_b',
          activity:'онлайн',
          online:true,
          busy:false,
          last_game_at:'',
          last_seen_at:new Date().toISOString(),
        }],
      }),
    });
  });

  await authorizeContext(context, 'A');
  const page = await context.newPage();
  try {
    await openOrdinaryStartReady(page, {
      appRoute: APP_ROUTE,
      apiRoute: API_ROUTE,
      label: 'Player A picker readiness',
    });
    expect(opponentCalls).toBe(0);

    await page.locator('[data-invite-friend="tictactoe"]').click();
    await expect(page.locator('[data-open-player-picker]')).toBeVisible();
    await page.locator('[data-open-player-picker]').click();

    await expect(page.locator('[data-direct-opponent="stg_test_player_b"]')).toBeVisible({ timeout:5_000 });
    await expect(page.locator('#sheet')).toContainText('@mgw_test_player_b');
    expect(opponentCalls).toBe(1);
  } finally {
    await context.unroute(OPPONENTS_ROUTE);
    await revokeAndClose(context);
  }
});
