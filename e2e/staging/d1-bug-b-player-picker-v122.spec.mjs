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
  const payload = await response.json();
  if (typeof payload?.value !== 'string') throw new Error('OIDC token is unavailable.');
  return payload.value;
}

async function authorizeContext(context, slot) {
  const oidcToken = await requestOidcToken();
  const response = await context.request.post(AUTH_ROUTE, {
    headers:{ Authorization:`Bearer ${oidcToken}`, Accept:'application/json', 'Content-Type':'application/json' },
    data:{ action:'issue', slot }, timeout:35_000,
  });
  expect(response.status(), `Player ${slot} auth status`).toBe(200);
  expect(await response.json()).toMatchObject({ ok:true, action:'issued', player_slot:slot });
  expect((await context.cookies(STAGING_ORIGIN)).some(item => item.name === TEST_COOKIE)).toBe(true);
}

function requestAction(response) {
  try { return String(response.request().postDataJSON()?.action || ''); }
  catch { return ''; }
}

async function openPlayer(browser, slot, isMobile, beforeGoto = null) {
  const context = await browser.newContext({
    locale:'ru-RU', timezoneId:'Europe/Vilnius',
    viewport:isMobile ? { width:390, height:844 } : { width:1280, height:900 },
    deviceScaleFactor:1, isMobile, hasTouch:isMobile,
  });
  await authorizeContext(context, slot);
  const page = await context.newPage();
  if (beforeGoto) await beforeGoto(page);
  const bootstrap = page.waitForResponse(response => response.url() === API_ROUTE
    && response.request().method() === 'POST'
    && requestAction(response) === 'bootstrap', { timeout:35_000 });
  const response = await page.goto(APP_ROUTE, { waitUntil:'domcontentloaded' });
  expect(response?.ok(), `Player ${slot} app response`).toBe(true);
  expect((await bootstrap).status(), `Player ${slot} bootstrap status`).toBe(200);
  await expect(page.locator('#preloader')).toBeHidden({ timeout:20_000 });
  return { context, page };
}

async function recordVisibleSheetFrames(page, key) {
  await page.evaluate(traceKey => {
    const trace = [];
    const sheet = document.getElementById('sheet');
    const overlay = document.getElementById('sheetOverlay');
    const record = () => {
      if (!overlay?.classList.contains('active')) return;
      const text = String(sheet?.innerText || '').replace(/\s+/g, ' ').trim();
      if (text && trace.at(-1) !== text) trace.push(text);
    };
    const observer = new MutationObserver(record);
    if (sheet) observer.observe(sheet, { childList:true, subtree:true, characterData:true });
    if (overlay) observer.observe(overlay, { attributes:true, attributeFilter:['class'] });
    record();
    window[`${traceKey}_TRACE`] = trace;
    window[`${traceKey}_OBSERVER`] = observer;
  }, key);
}

async function takeVisibleSheetFrames(page, key) {
  return page.evaluate(traceKey => {
    window[`${traceKey}_OBSERVER`]?.disconnect();
    return Array.isArray(window[`${traceKey}_TRACE`]) ? window[`${traceKey}_TRACE`] : [];
  }, key);
}

async function closePlayer(player) {
  if (!player?.context) return;
  try { await player.context.request.post(AUTH_ROUTE, { data:{ action:'revoke' }, timeout:15_000 }); } catch {}
  await player.context.close();
}

async function runPickerScenario(browser, isMobile) {
  let playerA;
  let playerB;
  let phase = 'prefetch-empty';
  let prefetchEmptyCalls = 0;
  let stressCalls = 0;
  try {
    playerB = await openPlayer(browser, 'B', isMobile);
    await playerB.page.waitForTimeout(500);

    playerA = await openPlayer(browser, 'A', isMobile, async page => {
      await page.route(OPPONENTS_ROUTE, async route => {
        if (phase === 'prefetch-empty') {
          prefetchEmptyCalls += 1;
          return route.fulfill({
            status:200,
            contentType:'application/json; charset=utf-8',
            body:JSON.stringify({
              ok:true,
              items:[],
              authoritative:true,
              complete:true,
              storage_driver:'json',
              online_opponent_count:0,
              unresolved_online_count:0,
            }),
          });
        }

        stressCalls += 1;
        if (stressCalls <= 6) {
          return route.fulfill({
            status:200,
            contentType:'application/json; charset=utf-8',
            body:JSON.stringify({ ok:true, items:[] }),
          });
        }
        return route.continue();
      });
    });

    // The first-interaction prefetch is allowed to happen before the v122
    // transport guard is installed, so one cold empty prefetch is sufficient.
    await expect.poll(() => prefetchEmptyCalls, { timeout:15_000 }).toBeGreaterThanOrEqual(1);
    await playerA.page.locator('[data-invite-friend="tictactoe"]').click();
    await expect(playerA.page.locator('[data-open-player-picker]')).toBeVisible({ timeout:15_000 });

    phase = 'stress';
    stressCalls = 0;
    const traceKey = isMobile ? '__MGW_D1_B_MOBILE' : '__MGW_D1_B_DESKTOP';
    await recordVisibleSheetFrames(playerA.page, traceKey);
    await playerA.page.locator('[data-open-player-picker]').click();

    await expect(playerA.page.locator('[data-direct-opponent="stg_test_player_b"]'))
      .toBeVisible({ timeout:20_000 });
    await expect(playerA.page.locator('[data-direct-opponent="stg_test_player_b"]'))
      .toContainText(/онлайн|играет|ищет соперника/u);

    const frames = await takeVisibleSheetFrames(playerA.page, traceKey);
    const forbidden = /(Недавних соперников пока нет|игроков нет|соперников нет)/iu;
    expect(frames.some(frame => forbidden.test(frame)), `Visible frames: ${JSON.stringify(frames)}`).toBe(false);
    expect(frames.some(frame => frame.includes('Загружаем соперников'))).toBe(true);
    expect(stressCalls).toBeGreaterThanOrEqual(7);

    const finalResponse = await playerA.page.evaluate(async () => {
      const response = await fetch('/bot/invite-opponents.php', {
        method:'POST',
        headers:{ 'Content-Type':'application/json', Accept:'application/json' },
        body:JSON.stringify({
          initData:'',
          sessionId:localStorage.getItem('mgw_device_session_id'),
          deviceId:localStorage.getItem('mgw_device_id'),
        }),
        cache:'no-store',
      });
      return response.json();
    });
    expect(finalResponse).toMatchObject({
      ok:true,
      authoritative:true,
      complete:true,
      storage_driver:'json',
      unresolved_online_count:0,
    });
    expect(finalResponse.items.some(item => item.id === 'stg_test_player_b')).toBe(true);
  } finally {
    await closePlayer(playerA);
    await closePlayer(playerB);
  }
}

test('D1 bug B v122: desktop never paints false empty before authoritative player B', async ({ browser }) => {
  await runPickerScenario(browser, false);
});

test('D1 bug B v122: mobile never paints false empty before authoritative player B', async ({ browser }) => {
  await runPickerScenario(browser, true);
});
