import { test, expect } from '@playwright/test';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = `${STAGING_ORIGIN}/app/v110.php?v=1123`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const OPPONENTS_ROUTE = `${STAGING_ORIGIN}/bot/invite-opponents.php`;
const TEST_COOKIE = 'mgw_staging_test_session';
const FALSE_EMPTY_PATTERN = /(недавних соперников пока нет|игроков нет|соперников нет|никого нет)/i;

async function requestOidcToken() {
  const requestUrl = process.env.ACTIONS_ID_TOKEN_REQUEST_URL || '';
  const requestToken = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN || '';
  if (!requestUrl || !requestToken) throw new Error('GitHub Actions OIDC environment is unavailable.');
  const url = new URL(requestUrl);
  url.searchParams.set('audience', OIDC_AUDIENCE);
  const response = await fetch(url, { headers:{ Authorization:`bearer ${requestToken}`, Accept:'application/json' } });
  if (!response.ok) throw new Error(`OIDC request failed: ${response.status}`);
  const payload = await response.json();
  if (typeof payload?.value !== 'string') throw new Error('OIDC token is unavailable.');
  return payload.value;
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

async function startVisibleFrameTrace(page) {
  await page.evaluate(() => {
    const frames = [];
    let running = true;
    const capture = () => {
      if (!running) return;
      const overlay = document.getElementById('sheetOverlay');
      const sheet = document.getElementById('sheet');
      if (overlay?.classList.contains('active') && sheet) {
        frames.push({
          at:performance.now(),
          text:String(sheet.innerText || '').replace(/\s+/g, ' ').trim(),
        });
      }
      requestAnimationFrame(capture);
    };
    window.__MGW_PLAYER_PICKER_FRAMES__ = frames;
    window.__MGW_STOP_PLAYER_PICKER_FRAMES__ = () => { running = false; };
    requestAnimationFrame(capture);
  });
}

async function stopVisibleFrameTrace(page) {
  return page.evaluate(() => {
    window.__MGW_STOP_PLAYER_PICKER_FRAMES__?.();
    return Array.isArray(window.__MGW_PLAYER_PICKER_FRAMES__)
      ? window.__MGW_PLAYER_PICKER_FRAMES__.slice()
      : [];
  });
}

async function runActualStartPicker(browser, isMobile) {
  const player = await openPlayer(browser, 'A', isMobile);
  let requests = 0;
  try {
    await player.page.route(OPPONENTS_ROUTE, async route => {
      requests += 1;
      await new Promise(resolve => setTimeout(resolve, 700));
      await route.fulfill({
        status:200,
        contentType:'application/json; charset=utf-8',
        body:JSON.stringify({
          ok:true, authoritative:true, storage_driver:'database',
          items:[{
            id:'stg_test_player_b',
            name:'TEST PLAYER B',
            activity:'онлайн',
            online:true,
            busy:false,
            last_game_at:'',
            last_seen_at:new Date().toISOString(),
          }],
        }),
      });
    });

    const resources = await player.page.evaluate(() => performance.getEntriesByType('resource').map(entry => entry.name));
    expect(resources.some(rawUrl => {
      const url = new URL(rawUrl);
      return url.pathname.endsWith('/assets/js/games/game-invites-v110.js') && url.searchParams.get('v') === '1127';
    }), 'Ordinary Start must execute the freshly published canonical v110 player-picker owner.').toBe(true);
    expect(requests).toBe(0);

    await player.page.locator('[data-invite-friend="tictactoe"]').click();
    await expect(player.page.locator('[data-open-player-picker]')).toBeVisible();
    await startVisibleFrameTrace(player.page);
    await player.page.locator('[data-open-player-picker]').click();

    await expect(player.page.locator('#sheet')).toContainText('Загружаем соперников…');
    await expect(player.page.locator('[data-direct-opponent="stg_test_player_b"]')).toBeVisible({ timeout:5_000 });
    await expect(player.page.locator('#sheet')).toContainText('TEST PLAYER B');

    const frames = await stopVisibleFrameTrace(player.page);
    expect(frames.length).toBeGreaterThan(0);
    expect(frames.some(frame => String(frame.text).includes('Загружаем соперников'))).toBe(true);
    expect(frames.some(frame => String(frame.text).includes('TEST PLAYER B'))).toBe(true);
    expect(frames.filter(frame => FALSE_EMPTY_PATTERN.test(String(frame.text)))).toEqual([]);
    expect(requests).toBe(1);
  } finally {
    try { await player.context.request.post(AUTH_ROUTE, { data:{ action:'revoke' }, timeout:15_000 }); } catch {}
    await player.context.close();
  }
}

test('actual Start desktop picker never paints false empty before the real player list', async ({ browser }) => {
  await runActualStartPicker(browser, false);
});

test('actual Start mobile picker never paints false empty before the real player list', async ({ browser }) => {
  await runActualStartPicker(browser, true);
});
