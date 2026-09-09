import { test, expect } from '@playwright/test';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = `${STAGING_ORIGIN}/app/`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const INVITES_ROUTE = `${STAGING_ORIGIN}/bot/invites.php`;
const NOTIFICATIONS_ROUTE = `${STAGING_ORIGIN}/bot/notifications.php`;
const OPPONENTS_ROUTE = `${STAGING_ORIGIN}/bot/invite-opponents.php`;
const TEST_COOKIE = 'mgw_staging_test_session';

async function requestOidcToken() {
  const requestUrl = process.env.ACTIONS_ID_TOKEN_REQUEST_URL || '';
  const requestToken = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN || '';
  if (!requestUrl || !requestToken) throw new Error('GitHub Actions OIDC environment is unavailable.');

  const url = new URL(requestUrl);
  url.searchParams.set('audience', OIDC_AUDIENCE);
  const response = await fetch(url, {
    headers: { Authorization:`bearer ${requestToken}`, Accept:'application/json' },
  });
  if (!response.ok) throw new Error(`GitHub Actions OIDC request failed: ${response.status}`);
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
      Authorization:`Bearer ${oidcToken}`,
      Accept:'application/json',
      'Content-Type':'application/json',
    },
    data:{ action:'issue', slot },
    timeout:35_000,
  });
  expect(response.status(), `Player ${slot} auth status`).toBe(200);
  const payload = await response.json();
  expect(payload).toMatchObject({ ok:true, action:'issued', player_slot:slot });
  const cookie = (await context.cookies(STAGING_ORIGIN)).find(item => item.name === TEST_COOKIE);
  expect(cookie, `Player ${slot} auth cookie`).toBeTruthy();
}

function requestAction(response) {
  try { return String(response.request().postDataJSON()?.action || ''); }
  catch { return ''; }
}

function isActionResponse(route, action) {
  return response => response.url() === route
    && response.request().method() === 'POST'
    && requestAction(response) === action;
}

function diagnostics(page) {
  const report = { pageErrors:[], failedRequests:[], serverErrors:[] };
  page.on('pageerror', error => report.pageErrors.push(String(error?.message || error)));
  page.on('requestfailed', request => {
    if (request.url().startsWith(STAGING_ORIGIN)) {
      report.failedRequests.push(`${request.method()} ${new URL(request.url()).pathname}`);
    }
  });
  page.on('response', response => {
    if (response.url().startsWith(STAGING_ORIGIN) && response.status() >= 500) {
      report.serverErrors.push(`${response.status()} ${new URL(response.url()).pathname}`);
    }
  });
  return report;
}

async function openPlayer(browser, slot, options = {}) {
  const isMobile = options.isMobile ?? true;
  const context = await browser.newContext({
    locale:'ru-RU',
    timezoneId:'Europe/Vilnius',
    viewport:options.viewport || (isMobile ? { width:390, height:844 } : { width:1280, height:900 }),
    deviceScaleFactor:1,
    isMobile,
    hasTouch:isMobile,
  });
  await authorizeContext(context, slot);
  const page = await context.newPage();
  const report = diagnostics(page);
  if (typeof options.beforeGoto === 'function') await options.beforeGoto(page);

  const bootstrapPromise = page.waitForResponse(isActionResponse(API_ROUTE, 'bootstrap'), { timeout:35_000 });
  const response = await page.goto(options.route || APP_ROUTE, { waitUntil:'domcontentloaded' });
  expect(response, `Player ${slot} app response`).not.toBeNull();
  expect(response.ok(), `Player ${slot} app status`).toBe(true);
  const bootstrap = await bootstrapPromise;
  expect(bootstrap.status(), `Player ${slot} bootstrap status`).toBe(200);
  expect((await bootstrap.json().catch(() => null))?.ok, `Player ${slot} bootstrap payload`).toBe(true);
  await expect(page.locator('#preloader')).toBeHidden({ timeout:20_000 });
  await page.waitForFunction(() => (
    String(localStorage.getItem('mgw_device_session_id') || '').length > 0
      && String(localStorage.getItem('mgw_device_id') || '').length > 0
  ), null, { timeout:20_000 });
  return { context, page, report };
}

async function postFromPlayer(page, pathname, data) {
  return page.evaluate(async ({ pathname:requestPath, data:requestData }) => {
    const response = await fetch(requestPath, {
      method:'POST',
      headers:{ 'Content-Type':'application/json', Accept:'application/json' },
      body:JSON.stringify({
        ...requestData,
        initData:'',
        sessionId:localStorage.getItem('mgw_device_session_id'),
        deviceId:localStorage.getItem('mgw_device_id'),
      }),
      cache:'no-store',
    });
    return { status:response.status, payload:await response.json().catch(() => null) };
  }, { pathname, data });
}

async function expectPost(page, pathname, data, label) {
  const result = await postFromPlayer(page, pathname, data);
  const publicError = typeof result.payload?.error === 'string'
    ? result.payload.error.slice(0, 300)
    : 'no_public_error';
  expect(result.status, `${label} status; public error: ${publicError}`).toBe(200);
  expect(result.payload?.ok, `${label} payload`).toBe(true);
  return result.payload;
}

async function cleanupPlayer(player) {
  if (!player?.page) return;
  try {
    const state = await postFromPlayer(player.page, '/bot/api.php', { action:'game_state' });
    if (state.status === 200 && state.payload?.game?.id && state.payload.game.status === 'active') {
      await postFromPlayer(player.page, '/bot/api.php', { action:'leave_game', gameId:state.payload.game.id });
    }
  } catch {}
  try {
    const sync = await postFromPlayer(player.page, '/bot/invites.php', { action:'sync', token:'' });
    const invite = sync.payload?.invite || sync.payload?.tracked_invite || null;
    if (sync.status === 200 && invite?.token
      && ['pending', 'accepted', 'awaiting_start'].includes(String(invite.status || ''))) {
      await postFromPlayer(player.page, '/bot/invites.php', { action:'cancel', token:invite.token });
    }
  } catch {}
  await player.page.keyboard.press('Escape').catch(() => null);
}

async function closePlayer(player) {
  if (!player?.context) return;
  try { await player.context.request.post(AUTH_ROUTE, { data:{ action:'revoke' }, timeout:15_000 }); }
  catch {}
  await player.context.close();
}

async function closeSheet(page) {
  const close = page.locator('#sheet [data-close-sheet]').first();
  await expect(close).toBeVisible({ timeout:15_000 });
  await close.click();
  await expect(page.locator('#sheetOverlay')).not.toHaveClass(/active/, { timeout:15_000 });
}

function expectClean(player, label) {
  expect(player.report.pageErrors, `${label} page errors`).toEqual([]);
  expect(player.report.failedRequests, `${label} failed requests`).toEqual([]);
  expect(player.report.serverErrors, `${label} server errors`).toEqual([]);
}

function jsonPayload(request) {
  try { return request.postDataJSON() || {}; }
  catch { return {}; }
}

function delay(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }

async function recordSheetTrace(page, key) {
  await page.evaluate(traceKey => {
    const trace = [];
    const sheet = document.getElementById('sheet');
    const record = () => {
      const text = String(sheet?.innerText || '').replace(/\s+/g, ' ').trim();
      if (text && trace.at(-1) !== text) trace.push(text);
    };
    const observer = new MutationObserver(record);
    if (sheet) observer.observe(sheet, { childList:true, subtree:true, characterData:true });
    record();
    window[`${traceKey}_TRACE`] = trace;
    window[`${traceKey}_OBSERVER`] = observer;
  }, key);
}

async function takeSheetTrace(page, key) {
  return page.evaluate(traceKey => {
    window[`${traceKey}_OBSERVER`]?.disconnect();
    return Array.isArray(window[`${traceKey}_TRACE`]) ? window[`${traceKey}_TRACE`] : [];
  }, key);
}

test('D1 follow-up: declined invitation remains read history without actions or toast', async ({ browser }) => {
  let playerA;
  let playerB;
  try {
    playerA = await openPlayer(browser, 'A');
    playerB = await openPlayer(browser, 'B');
    await cleanupPlayer(playerA);
    await cleanupPlayer(playerB);

    const created = await expectPost(playerB.page, '/bot/invites.php', {
      action:'create_direct', inviteeId:'stg_test_player_a', gameType:'tictactoe',
      room:'match', bet:10, boardSize:3,
    }, 'Create decline-history invitation');
    const token = String(created?.invite?.token || '');
    expect(token).toMatch(/^[A-Za-z0-9_-]{12,80}$/);

    await expect(playerA.page.locator('#notificationToast')).toHaveClass(/show/, { timeout:20_000 });
    await playerA.page.locator('#notificationsOpen').click();
    const decline = playerA.page.locator(`[data-invite-action="decline"][data-invite-token="${token}"]`);
    await expect(decline).toBeVisible({ timeout:20_000 });
    const declineResponse = playerA.page.waitForResponse(isActionResponse(INVITES_ROUTE, 'decline'), { timeout:30_000 });
    await decline.click();
    expect((await declineResponse).status()).toBe(200);
    await expect(playerA.page.locator('#sheetOverlay')).not.toHaveClass(/active/);
    await expect(playerA.page.locator('#toast')).not.toHaveClass(/show/);

    await playerA.page.locator('#notificationsOpen').click();
    const historyCard = playerA.page.locator('article.notification-card').filter({ hasText:'Приглашение отклонено' }).first();
    await expect(historyCard).toBeVisible({ timeout:20_000 });
    await expect(historyCard).toContainText('TEST PLAYER B');
    await expect(historyCard).toContainText('Крестики-нолики');
    await expect(historyCard.locator('[data-invite-action]')).toHaveCount(0);
    await expect(historyCard.locator('.notification-head span')).not.toHaveText('');
    await expect(playerA.page.locator('#toast')).not.toHaveClass(/show/);

    expectClean(playerA, 'Player A declined history');
    expectClean(playerB, 'Player B declined history');
  } finally {
    if (playerA) await cleanupPlayer(playerA);
    if (playerB) await cleanupPlayer(playerB);
    await closePlayer(playerA);
    await closePlayer(playerB);
  }
});

test('D1 follow-up: mobile cached invitation wins over a delayed false-empty response', async ({ browser }) => {
  let playerA;
  let playerB;
  try {
    playerA = await openPlayer(browser, 'A');
    playerB = await openPlayer(browser, 'B');
    await cleanupPlayer(playerA);
    await cleanupPlayer(playerB);

    const created = await expectPost(playerB.page, '/bot/invites.php', {
      action:'create_direct', inviteeId:'stg_test_player_a', gameType:'tictactoe',
      room:'match', bet:10, boardSize:3,
    }, 'Create mobile delayed-response invitation');
    const token = String(created?.invite?.token || '');
    expect(token).toMatch(/^[A-Za-z0-9_-]{12,80}$/);
    await expect(playerA.page.locator('#notificationToast')).toHaveClass(/show/, { timeout:20_000 });

    let markReadCalls = 0;
    await playerA.page.route(NOTIFICATIONS_ROUTE, async route => {
      const payload = jsonPayload(route.request());
      if (!payload.markRead) return route.continue();
      markReadCalls += 1;
      if (markReadCalls === 1) {
        await delay(700);
        return route.fulfill({
          status:200,
          contentType:'application/json; charset=utf-8',
          body:JSON.stringify({ ok:true, items:[], unread_count:0 }),
        });
      }
      return route.continue();
    });

    await recordSheetTrace(playerA.page, '__MGW_D1_MOBILE_DELAY');
    const openedAt = Date.now();
    await playerA.page.locator('#notificationsOpen').click();
    const accept = playerA.page.locator(`[data-invite-action="accept"][data-invite-token="${token}"]`);
    await expect(accept).toBeVisible({ timeout:1_200 });
    expect(Date.now() - openedAt, 'Cached mobile card first-paint latency').toBeLessThan(650);
    await playerA.page.waitForTimeout(1_000);

    const trace = await takeSheetTrace(playerA.page, '__MGW_D1_MOBILE_DELAY');
    expect(trace.some(frame => frame.includes('Пока уведомлений нет'))).toBe(false);
    expect(markReadCalls).toBeGreaterThanOrEqual(2);

    const declineResponse = playerA.page.waitForResponse(isActionResponse(INVITES_ROUTE, 'decline'), { timeout:30_000 });
    await playerA.page.locator(`[data-invite-action="decline"][data-invite-token="${token}"]`).click();
    expect((await declineResponse).status()).toBe(200);
    await playerA.page.unroute(NOTIFICATIONS_ROUTE);

    expectClean(playerA, 'Player A mobile delayed response');
    expectClean(playerB, 'Player B mobile delayed response');
  } finally {
    if (playerA) await cleanupPlayer(playerA);
    if (playerB) await cleanupPlayer(playerB);
    await closePlayer(playerA);
    await closePlayer(playerB);
  }
});

test('D1 follow-up: desktop bell opens during an unfinished request and ignores its stale finish', async ({ browser }) => {
  let playerA;
  try {
    playerA = await openPlayer(browser, 'A', { isMobile:false });
    await cleanupPlayer(playerA);

    let markReadCalls = 0;
    let firstStartedResolve;
    const firstStarted = new Promise(resolve => { firstStartedResolve = resolve; });
    await playerA.page.route(NOTIFICATIONS_ROUTE, async route => {
      const payload = jsonPayload(route.request());
      if (!payload.markRead) return route.continue();
      markReadCalls += 1;
      if (markReadCalls === 1) {
        firstStartedResolve();
        await delay(2_000);
      }
      return route.continue();
    });

    await playerA.page.locator('#notificationsOpen').click();
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveText('Уведомления', { timeout:600 });
    await firstStarted;
    await closeSheet(playerA.page);

    const secondOpenedAt = Date.now();
    await playerA.page.locator('#notificationsOpen').click();
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveText('Уведомления', { timeout:600 });
    expect(Date.now() - secondOpenedAt, 'Second desktop click while first request is pending').toBeLessThan(650);
    await closeSheet(playerA.page);

    for (let iteration = 0; iteration < 8; iteration += 1) {
      const startedAt = Date.now();
      await playerA.page.locator('#notificationsOpen').click();
      await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveText('Уведомления', { timeout:700 });
      expect(Date.now() - startedAt, `Desktop bell iteration ${iteration + 1}`).toBeLessThan(750);
      await closeSheet(playerA.page);
    }

    await playerA.page.waitForTimeout(2_300);
    await expect(playerA.page.locator('#sheetOverlay')).not.toHaveClass(/active/);
    expect(markReadCalls).toBeGreaterThanOrEqual(2);
    await playerA.page.unroute(NOTIFICATIONS_ROUTE);
    expectClean(playerA, 'Player A desktop in-flight bell');
  } finally {
    if (playerA) await cleanupPlayer(playerA);
    await closePlayer(playerA);
  }
});

test('D1 follow-up: desktop player picker confirms transient empty snapshots before rendering', async ({ browser }) => {
  let playerA;
  let playerB;
  let stressPhase = false;
  let stressCalls = 0;
  try {
    playerB = await openPlayer(browser, 'B', { isMobile:false });
    await cleanupPlayer(playerB);

    playerA = await openPlayer(browser, 'A', {
      isMobile:false,
      beforeGoto:async page => {
        await page.route(OPPONENTS_ROUTE, async route => {
          if (!stressPhase) {
            return route.fulfill({
              status:200,
              contentType:'application/json; charset=utf-8',
              body:JSON.stringify({ ok:true, items:[] }),
            });
          }
          stressCalls += 1;
          if (stressCalls <= 4) {
            return route.fulfill({
              status:200,
              contentType:'application/json; charset=utf-8',
              body:JSON.stringify({ ok:true, items:[] }),
            });
          }
          return route.continue();
        });
      },
    });
    await cleanupPlayer(playerA);

    await playerA.page.locator('[data-invite-friend="tictactoe"]').click();
    await expect(playerA.page.locator('[data-open-player-picker]')).toBeVisible({ timeout:15_000 });
    stressPhase = true;
    stressCalls = 0;
    await recordSheetTrace(playerA.page, '__MGW_D1_OPPONENT_EMPTY');
    await playerA.page.locator('[data-open-player-picker]').click();

    const opponent = playerA.page.locator('[data-direct-opponent="stg_test_player_b"]');
    await expect(opponent).toBeVisible({ timeout:20_000 });
    const trace = await takeSheetTrace(playerA.page, '__MGW_D1_OPPONENT_EMPTY');
    expect(trace.some(frame => frame.includes('Недавних соперников пока нет'))).toBe(false);
    expect(stressCalls).toBeGreaterThanOrEqual(5);

    await playerA.page.unroute(OPPONENTS_ROUTE);
    expectClean(playerA, 'Player A opponent empty confirmations');
    expectClean(playerB, 'Player B opponent empty confirmations');
  } finally {
    if (playerA) await cleanupPlayer(playerA);
    if (playerB) await cleanupPlayer(playerB);
    await closePlayer(playerA);
    await closePlayer(playerB);
  }
});
