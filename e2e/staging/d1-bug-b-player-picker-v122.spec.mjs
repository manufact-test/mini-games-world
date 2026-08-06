import { test, expect } from '@playwright/test';
import { openOrdinaryStartReady } from './support/ordinary-start-readiness.mjs';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = `${STAGING_ORIGIN}/app/v110.php?v=1123`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const OPPONENTS_ROUTE = `${STAGING_ORIGIN}/bot/invite-opponents.php`;
const TEST_COOKIE = 'mgw_staging_test_session';
const PLAYER_B_VISIBLE_NAME = '@mgw_test_player_b';
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

async function openPlayer(browser, slot, isMobile) {
  const context = await browser.newContext({
    locale:'ru-RU', timezoneId:'Europe/Vilnius',
    viewport:isMobile ? { width:390, height:844 } : { width:1280, height:900 },
    deviceScaleFactor:1, isMobile, hasTouch:isMobile,
  });
  await authorizeContext(context, slot);
  const page = await context.newPage();
  await openOrdinaryStartReady(page, {
    appRoute: APP_ROUTE,
    apiRoute: API_ROUTE,
    label: `Player ${slot}`,
  });
  return { context, page };
}

async function closePlayer(player) {
  if (!player) return;
  try { await player.context.request.post(AUTH_ROUTE, { data:{ action:'revoke' }, timeout:15_000 }); } catch {}
  await player.context.close();
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
  const playerB = await openPlayer(browser, 'B', false);
  const playerA = await openPlayer(browser, 'A', isMobile);
  let requests = 0;
  const countRequest = request => {
    if (request.url() === OPPONENTS_ROUTE && request.method() === 'POST') requests += 1;
  };
  playerA.page.on('request', countRequest);
  const delayLiveOpponentRequest = async route => {
    await new Promise(resolve => setTimeout(resolve, 1500));
    await route.continue();
  };
  await playerA.page.route(OPPONENTS_ROUTE, delayLiveOpponentRequest);

  try {
    const resources = await playerA.page.evaluate(() => performance.getEntriesByType('resource').map(entry => entry.name));
    expect(resources.some(rawUrl => {
      const url = new URL(rawUrl);
      return url.pathname.endsWith('/assets/js/games/game-invites-v110.js') && url.searchParams.get('v') === '1132';
    }), 'Ordinary Start must execute the canonical v110 player-picker owner.').toBe(true);
    expect(requests).toBe(0);

    await playerA.page.locator('[data-invite-friend="tictactoe"]').click();
    await expect(playerA.page.locator('[data-open-player-picker]')).toBeVisible();
    await startVisibleFrameTrace(playerA.page);

    const opponentResponse = playerA.page.waitForResponse(response =>
      response.url() === OPPONENTS_ROUTE && response.request().method() === 'POST',
    { timeout:15_000 });
    await playerA.page.locator('[data-open-player-picker]').click();

    const response = await opponentResponse;
    expect(response.status()).toBe(200);
    const payload = await response.json();
    expect(payload?.ok).toBe(true);
    expect(payload?.authoritative).toBe(true);
    expect(payload?.storage_driver).toBe('json');
    expect((Array.isArray(payload?.items) ? payload.items : []).map(item => String(item?.id || '')))
      .toContain('stg_test_player_b');

    await expect(playerA.page.locator('[data-direct-opponent="stg_test_player_b"]')).toBeVisible({ timeout:10_000 });
    await expect(playerA.page.locator('#sheet')).toContainText(PLAYER_B_VISIBLE_NAME);

    const frames = await stopVisibleFrameTrace(playerA.page);
    expect(frames.length).toBeGreaterThan(0);
    expect(frames.filter(frame => /Загружаем соперников/i.test(String(frame.text)))).toEqual([]);
    expect(frames.filter(frame => FALSE_EMPTY_PATTERN.test(String(frame.text)))).toEqual([]);
    const pickerFrames = frames.filter(frame => String(frame.text).includes('Выберите игрока'));
    expect(pickerFrames.length).toBeGreaterThan(0);
    expect(String(pickerFrames[0].text)).toContain(PLAYER_B_VISIBLE_NAME);
    expect(requests).toBe(1);
  } finally {
    await playerA.page.unroute(OPPONENTS_ROUTE, delayLiveOpponentRequest);
    playerA.page.off('request', countRequest);
    await closePlayer(playerA);
    await closePlayer(playerB);
  }
}

test('actual Start desktop picker uses live storage and opens only when the ready Player B list can paint', async ({ browser }) => {
  await runActualStartPicker(browser, false);
});

test('actual Start mobile picker uses live storage and opens only when the ready Player B list can paint', async ({ browser }) => {
  await runActualStartPicker(browser, true);
});
