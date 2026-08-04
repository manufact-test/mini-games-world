import { test, expect } from '@playwright/test';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = `${STAGING_ORIGIN}/app/`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const INVITES_ROUTE = `${STAGING_ORIGIN}/bot/invites.php`;
const TEST_COOKIE = 'mgw_staging_test_session';

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
      Authorization: `Bearer ${oidcToken}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    data: { action: 'issue', slot },
    timeout: 35_000,
  });
  expect(response.status(), `Player ${slot} auth status`).toBe(200);
  const payload = await response.json();
  expect(payload).toMatchObject({ ok:true, action:'issued', player_slot:slot });
  const cookie = (await context.cookies(STAGING_ORIGIN)).find(item => item.name === TEST_COOKIE);
  expect(cookie, `Player ${slot} auth cookie`).toBeTruthy();
}

function requestAction(response) {
  try {
    return String(response.request().postDataJSON()?.action || '');
  } catch {
    return '';
  }
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
  const bootstrapPromise = page.waitForResponse(isActionResponse(API_ROUTE, 'bootstrap'), {
    timeout:35_000,
  });
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
  expect(result.status, `${label} status`).toBe(200);
  expect(result.payload?.ok, `${label} payload`).toBe(true);
  return result.payload;
}

async function cleanupPlayer(player) {
  if (!player?.page) return;
  try {
    const state = await postFromPlayer(player.page, '/bot/api.php', { action:'game_state' });
    if (state.status === 200 && state.payload?.game?.id && state.payload.game.status === 'active') {
      await postFromPlayer(player.page, '/bot/api.php', {
        action:'leave_game',
        gameId:state.payload.game.id,
      });
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
  try {
    await player.context.request.post(AUTH_ROUTE, { data:{ action:'revoke' }, timeout:15_000 });
  } catch {}
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

test('D1 feedback 1 and 6: invitation link opens one decision sheet and keeps both players online', async ({ browser }) => {
  let playerA;
  let playerB;
  let token = '';
  try {
    playerA = await openPlayer(browser, 'A');
    await cleanupPlayer(playerA);

    const draft = await expectPost(playerA.page, '/bot/invites.php', {
      action:'create_link_draft',
      gameType:'tictactoe',
      room:'match',
      bet:10,
      boardSize:3,
    }, 'Create invitation link draft');
    token = String(draft?.invite?.token || '');
    expect(token).toMatch(/^[A-Za-z0-9_-]{12,80}$/);

    playerB = await openPlayer(browser, 'B', {
      route:`${APP_ROUTE}?invite=${encodeURIComponent(token)}`,
    });

    await expect(playerB.page.locator('#sheet .sheet-head h2')).toHaveText('Вас приглашают сыграть', {
      timeout:25_000,
    });
    const accept = playerB.page.locator(`[data-invite-action="accept"][data-invite-token="${token}"]`);
    const decline = playerB.page.locator(`[data-invite-action="decline"][data-invite-token="${token}"]`);
    await expect(accept).toBeVisible();
    await expect(decline).toBeVisible();
    await expect(playerB.page.locator('#sheet')).not.toContainText('Понятно');

    await playerB.page.waitForFunction(() => Number(window.__MGW_V115_PRESENCE_ONLINE__ || 0) >= 2, null, {
      timeout:20_000,
    });
    await playerB.page.waitForFunction(() => {
      const value = Number(document.querySelector('#activityGrid .activity-card .num')?.textContent || 0);
      return value >= 2;
    }, null, { timeout:20_000 });

    const declineResponse = playerB.page.waitForResponse(isActionResponse(INVITES_ROUTE, 'decline'), {
      timeout:30_000,
    });
    await decline.click();
    expect((await declineResponse).status()).toBe(200);
    await expect(playerB.page.locator('#sheetOverlay')).not.toHaveClass(/active/, { timeout:15_000 });
    await expect(playerB.page.locator('#toast')).not.toHaveClass(/show/);
    await playerB.page.waitForTimeout(900);
    await expect(playerB.page.locator('#sheetOverlay')).not.toHaveClass(/active/);
    await expect(playerB.page.locator('body')).not.toContainText('Понятно');

    expectClean(playerA, 'Player A link/presence');
    expectClean(playerB, 'Player B link/presence');
  } finally {
    if (token && playerA?.page) {
      await postFromPlayer(playerA.page, '/bot/invites.php', { action:'discard_draft', token }).catch(() => null);
    }
    if (playerA) await cleanupPlayer(playerA);
    if (playerB) await cleanupPlayer(playerB);
    await closePlayer(playerA);
    await closePlayer(playerB);
  }
});

test('D1 feedback 3: mobile notification opening never paints a false empty state', async ({ browser }) => {
  let playerA;
  let playerB;
  let token = '';
  try {
    playerA = await openPlayer(browser, 'A');
    playerB = await openPlayer(browser, 'B');
    await cleanupPlayer(playerA);
    await cleanupPlayer(playerB);

    const created = await expectPost(playerB.page, '/bot/invites.php', {
      action:'create_direct',
      inviteeId:'stg_test_player_a',
      gameType:'tictactoe',
      room:'match',
      bet:10,
      boardSize:3,
    }, 'Create mobile first-frame invitation');
    token = String(created?.invite?.token || '');
    expect(token).toMatch(/^[A-Za-z0-9_-]{12,80}$/);

    await playerA.page.evaluate(() => {
      const trace = [];
      const sheet = document.getElementById('sheet');
      const record = () => {
        const text = String(sheet?.innerText || '').replace(/\s+/g, ' ').trim();
        if (text && trace.at(-1) !== text) trace.push(text);
      };
      const observer = new MutationObserver(record);
      if (sheet) observer.observe(sheet, { childList:true, subtree:true, characterData:true });
      window.__MGW_D1_MOBILE_NOTIFICATION_TRACE__ = trace;
      window.__MGW_D1_MOBILE_NOTIFICATION_OBSERVER__ = observer;
    });

    await playerA.page.locator('#notificationsOpen').click();
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveText('Уведомления', { timeout:20_000 });
    await expect(playerA.page.locator(
      `[data-invite-action="accept"][data-invite-token="${token}"]`,
    )).toBeVisible({ timeout:25_000 });

    const trace = await playerA.page.evaluate(() => {
      window.__MGW_D1_MOBILE_NOTIFICATION_OBSERVER__?.disconnect();
      return Array.isArray(window.__MGW_D1_MOBILE_NOTIFICATION_TRACE__)
        ? window.__MGW_D1_MOBILE_NOTIFICATION_TRACE__
        : [];
    });
    expect(trace.some(frame => frame.includes('Пока уведомлений нет'))).toBe(false);

    const decline = playerA.page.locator(
      `[data-invite-action="decline"][data-invite-token="${token}"]`,
    );
    const declineResponse = playerA.page.waitForResponse(isActionResponse(INVITES_ROUTE, 'decline'), {
      timeout:30_000,
    });
    await decline.click();
    expect((await declineResponse).status()).toBe(200);

    expectClean(playerA, 'Player A mobile first frame');
    expectClean(playerB, 'Player B mobile first frame');
  } finally {
    if (playerA) await cleanupPlayer(playerA);
    if (playerB) await cleanupPlayer(playerB);
    await closePlayer(playerA);
    await closePlayer(playerB);
  }
});

test('D1 feedback 2, 4 and 5: desktop bell, opponent picker and cancellation stay stable', async ({ browser }) => {
  let playerA;
  let playerB;
  let token = '';
  try {
    playerA = await openPlayer(browser, 'A', { isMobile:false });
    playerB = await openPlayer(browser, 'B', { isMobile:false });
    await cleanupPlayer(playerA);
    await cleanupPlayer(playerB);

    for (let iteration = 0; iteration < 5; iteration += 1) {
      await playerA.page.locator('#notificationsOpen').click();
      await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveText('Уведомления', {
        timeout:12_000,
      });
      await closeSheet(playerA.page);
      await playerA.page.waitForTimeout(180);
      await expect(playerA.page.locator('#sheetOverlay')).not.toHaveClass(/active/);
    }

    await playerA.page.locator('[data-invite-friend="tictactoe"]').click();
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toContainText('Пригласить в', {
      timeout:20_000,
    });

    await playerA.page.evaluate(() => {
      const trace = [];
      const sheet = document.getElementById('sheet');
      const record = () => {
        const text = String(sheet?.innerText || '').replace(/\s+/g, ' ').trim();
        if (text && trace.at(-1) !== text) trace.push(text);
      };
      const observer = new MutationObserver(record);
      if (sheet) observer.observe(sheet, { childList:true, subtree:true, characterData:true });
      window.__MGW_D1_OPPONENT_TRACE__ = trace;
      window.__MGW_D1_OPPONENT_OBSERVER__ = observer;
    });

    await playerA.page.locator('[data-open-player-picker]').click();
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveText('Выберите игрока', {
      timeout:20_000,
    });
    const opponent = playerA.page.locator('[data-direct-opponent="stg_test_player_b"]');
    await expect(opponent).toBeVisible({ timeout:25_000 });

    const pickerTrace = await playerA.page.evaluate(() => {
      window.__MGW_D1_OPPONENT_OBSERVER__?.disconnect();
      return Array.isArray(window.__MGW_D1_OPPONENT_TRACE__) ? window.__MGW_D1_OPPONENT_TRACE__ : [];
    });
    expect(pickerTrace.some(frame => frame.includes('Недавних соперников пока нет'))).toBe(false);

    const createResponse = playerA.page.waitForResponse(isActionResponse(INVITES_ROUTE, 'create_direct'), {
      timeout:30_000,
    });
    await opponent.click();
    const response = await createResponse;
    const created = await response.json().catch(() => null);
    expect(response.status()).toBe(200);
    expect(created?.ok).toBe(true);
    token = String(created?.invite?.token || '');
    expect(token).toMatch(/^[A-Za-z0-9_-]{12,80}$/);
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveText('Приглашение отправлено', {
      timeout:20_000,
    });

    await playerA.page.evaluate(() => {
      const toast = document.getElementById('toast');
      toast?.classList.remove('show');
      if (toast) toast.textContent = '';
    });
    const cancel = playerA.page.locator(
      `[data-invite-action="cancel"][data-invite-token="${token}"]`,
    );
    const cancelResponse = playerA.page.waitForResponse(isActionResponse(INVITES_ROUTE, 'cancel'), {
      timeout:30_000,
    });
    await cancel.click();
    expect((await cancelResponse).status()).toBe(200);
    await expect(playerA.page.locator('#sheetOverlay')).not.toHaveClass(/active/, { timeout:15_000 });
    await expect(playerA.page.locator('#toast')).not.toHaveClass(/show/);
    await expect(playerA.page.locator('#toast')).not.toContainText('Приглашение отменено');
    await playerA.page.waitForTimeout(900);
    await expect(playerA.page.locator('#sheetOverlay')).not.toHaveClass(/active/);

    expectClean(playerA, 'Player A desktop stability');
    expectClean(playerB, 'Player B desktop stability');
  } finally {
    if (playerA) await cleanupPlayer(playerA);
    if (playerB) await cleanupPlayer(playerB);
    await closePlayer(playerA);
    await closePlayer(playerB);
  }
});
