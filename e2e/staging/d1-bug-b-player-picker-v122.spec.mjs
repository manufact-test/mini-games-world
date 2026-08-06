import { test, expect } from '@playwright/test';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const APP_ROUTE = `${STAGING_ORIGIN}/app/v110.php?v=1123`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const OPPONENTS_ROUTE = `${STAGING_ORIGIN}/bot/invite-opponents.php`;
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-e2e-auth.php`;
const TEST_COOKIE = 'mgw_staging_e2e';
const FALSE_EMPTY_PATTERN = /Сейчас никто не в сети|Игроки не найдены|Нет доступных игроков/i;

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
  expect(response.status(), `Player ${slot} auth status`).toBe(200);
  expect(await response.json()).toMatchObject({ ok:true, action:'issued', player_slot:slot });
  const cookie = (await context.cookies(STAGING_ORIGIN)).find(item => item.name === TEST_COOKIE);
  expect(cookie, `Player ${slot} auth cookie`).toBeTruthy();
}

async function openPlayer(browser, slot, isMobile) {
  const context = await browser.newContext({
    locale: 'ru-RU',
    timezoneId: 'Europe/Vilnius',
    viewport: isMobile ? { width:390, height:844 } : { width:1280, height:800 },
    deviceScaleFactor: 1,
    isMobile,
    hasTouch: isMobile,
  });
  await authorize(context, slot);
  const page = await context.newPage();
  await page.goto(APP_ROUTE, { waitUntil:'domcontentloaded', timeout:45_000 });
  await expect(page.locator('#screen-home')).toHaveClass(/active/, { timeout:35_000 });
  await expect(page.locator('#preloader')).not.toBeVisible({ timeout:35_000 });
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
      const open = overlay?.classList.contains('active') === true;
      frames.push({
        open,
        text:open ? String(sheet?.textContent || '').replace(/\s+/g, ' ').trim() : '',
        at:performance.now(),
      });
      requestAnimationFrame(capture);
    };
    window.__MGW_PICKER_FRAME_TRACE__ = frames;
    window.__MGW_STOP_PICKER_FRAME_TRACE__ = () => { running = false; };
    requestAnimationFrame(capture);
  });
}

async function stopVisibleFrameTrace(page) {
  return page.evaluate(() => {
    window.__MGW_STOP_PICKER_FRAME_TRACE__?.();
    return window.__MGW_PICKER_FRAME_TRACE__ || [];
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
      return url.pathname.endsWith('/assets/js/games/game-invites-v110.js') && url.searchParams.get('v') === '1130';
    }), 'Ordinary Start must execute the canonical v110 player-picker owner.').toBe(true);
    expect(requests).toBe(0);

    await playerA.page.locator('[data-invite-friend="tictactoe"]').click();
    await expect(playerA.page.locator('[data-open-player-picker]')).toBeVisible();
    await startVisibleFrameTrace(playerA.page);

    const opponentResponse = playerA.page.waitForResponse(response =>
      response.url() === OPPONENTS_ROUTE && response.request().method() === 'POST',
    { timeout:15_000 });
    await playerA.page.locator('[data-open-player-picker]').click();
    expect(requests).toBe(1);
    await expect(playerA.page.locator('[data-open-player-picker]')).toHaveAttribute('aria-busy', 'true');
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toContainText('Пригласить в');

    const response = await opponentResponse;
    expect(response.status()).toBe(200);
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveText('Выберите игрока');
    await expect(playerA.page.locator('[data-direct-invite]')).toHaveCount(1);
    await expect(playerA.page.locator('[data-direct-invite]')).toContainText('Player B');
    expect(requests).toBe(1);

    const frames = await stopVisibleFrameTrace(playerA.page);
    const pickerFrames = frames.filter(frame => frame.open && /Выберите игрока/.test(frame.text));
    expect(pickerFrames.length).toBeGreaterThan(0);
    expect(pickerFrames[0].text).toMatch(/Player B/);
    expect(frames.some(frame => frame.open && FALSE_EMPTY_PATTERN.test(frame.text))).toBe(false);
  } finally {
    playerA.page.off('request', countRequest);
    await playerA.page.unroute(OPPONENTS_ROUTE, delayLiveOpponentRequest).catch(() => {});
    await playerA.context.request.post(AUTH_ROUTE, { data:{ action:'revoke' } }).catch(() => {});
    await playerB.context.request.post(AUTH_ROUTE, { data:{ action:'revoke' } }).catch(() => {});
    await playerA.context.close();
    await playerB.context.close();
  }
}

test('actual Start desktop picker uses live storage and opens only when the ready Player B list can paint', async ({ browser }) => {
  test.setTimeout(90_000);
  await runActualStartPicker(browser, false);
});

test('actual Start mobile picker uses live storage and opens only when the ready Player B list can paint', async ({ browser }) => {
  test.setTimeout(90_000);
  await runActualStartPicker(browser, true);
});
