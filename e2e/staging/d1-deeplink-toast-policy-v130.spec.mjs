import { test, expect } from '@playwright/test';
import { telegramAppRoute, telegramInvitationRoute } from './support/telegram-launch-route.mjs';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = telegramAppRoute(STAGING_ORIGIN);
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const INVITES_ROUTE = `${STAGING_ORIGIN}/bot/invites.php`;
const TEST_COOKIE = 'mgw_staging_test_session';

async function requestOidcToken(){
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

async function authorizeContext(context, slot){
  const response = await context.request.post(AUTH_ROUTE, {
    headers:{
      Authorization:`Bearer ${await requestOidcToken()}`,
      Accept:'application/json',
      'Content-Type':'application/json',
    },
    data:{ action:'issue', slot },
    timeout:35_000,
  });
  expect(response.status(), `Player ${slot} auth status`).toBe(200);
  expect(await response.json()).toMatchObject({ ok:true, action:'issued', player_slot:slot });
  expect((await context.cookies(STAGING_ORIGIN)).some(item => item.name === TEST_COOKIE)).toBe(true);
}

function requestAction(response){
  try { return String(response.request().postDataJSON()?.action || ''); }
  catch { return ''; }
}

async function openPlayer(browser, slot, route = APP_ROUTE){
  const context = await browser.newContext({
    locale:'ru-RU',
    timezoneId:'Europe/Vilnius',
    viewport:{ width:390, height:844 },
    deviceScaleFactor:1,
    isMobile:true,
    hasTouch:true,
  });
  await authorizeContext(context, slot);
  const page = await context.newPage();
  const bootstrap = page.waitForResponse(response => response.url() === API_ROUTE
    && response.request().method() === 'POST'
    && requestAction(response) === 'bootstrap', { timeout:35_000 });
  const response = await page.goto(route, { waitUntil:'domcontentloaded' });
  expect(response?.ok(), `Player ${slot} app response`).toBe(true);
  expect((await bootstrap).status(), `Player ${slot} bootstrap status`).toBe(200);
  await expect(page.locator('#preloader')).toBeHidden({ timeout:20_000 });
  return { context, page };
}

async function postFromPlayer(page, path, data){
  return page.evaluate(async ({ path, data }) => {
    const response = await fetch(path, {
      method:'POST',
      headers:{ 'Content-Type':'application/json', Accept:'application/json' },
      body:JSON.stringify({
        ...data,
        initData:'',
        sessionId:localStorage.getItem('mgw_device_session_id'),
        deviceId:localStorage.getItem('mgw_device_id'),
      }),
      cache:'no-store',
    });
    return { status:response.status, payload:await response.json().catch(() => null) };
  }, { path, data });
}

async function cleanupPlayer(player){
  if (!player?.page) return;
  try {
    const sync = await postFromPlayer(player.page, '/bot/invites.php', { action:'sync', token:'' });
    const invite = sync.payload?.invite || sync.payload?.tracked_invite || null;
    if (sync.status === 200 && invite?.token
      && ['pending', 'accepted', 'awaiting_start'].includes(String(invite.status || ''))) {
      const action = String(invite.status || '') === 'pending' && !invite.is_owner
        ? 'decline'
        : 'cancel';
      await postFromPlayer(player.page, '/bot/invites.php', { action, token:invite.token });
    }
  } catch {}
  await player.page.keyboard.press('Escape').catch(() => null);
}

async function closePlayer(player){
  if (!player?.context) return;
  try { await player.context.request.post(AUTH_ROUTE, { data:{ action:'revoke' }, timeout:15_000 }); }
  catch {}
  await player.context.close();
}

test('D1 v130: invitation link opens one decision sheet without a duplicate blue toast', async ({ browser }) => {
  let playerA;
  let playerB;
  let token = '';
  try {
    playerA = await openPlayer(browser, 'A');
    await cleanupPlayer(playerA);

    const draft = await postFromPlayer(playerA.page, '/bot/invites.php', {
      action:'create_link_draft',
      gameType:'tictactoe',
      room:'match',
      bet:10,
      boardSize:3,
    });
    expect(draft.status).toBe(200);
    expect(draft.payload?.ok).toBe(true);
    token = String(draft.payload?.invite?.token || '');
    expect(token).toMatch(/^[A-Za-z0-9_-]{12,80}$/);

    playerB = await openPlayer(browser, 'B', telegramInvitationRoute(STAGING_ORIGIN, token));
    await expect(playerB.page.locator('#sheet .sheet-head h2')).toHaveText('Вас приглашают сыграть', {
      timeout:25_000,
    });
    await expect(playerB.page.locator('#sheetOverlay')).toHaveClass(/active/);
    await expect(playerB.page.locator(`[data-invite-action="accept"][data-invite-token="${token}"]`)).toBeVisible();
    await expect(playerB.page.locator('#notificationToast')).not.toHaveClass(/show/);
    await playerB.page.waitForTimeout(1_200);
    await expect(playerB.page.locator('#notificationToast')).not.toHaveClass(/show/);

    const decline = playerB.page.locator(`[data-invite-action="decline"][data-invite-token="${token}"]`);
    const declineResponse = playerB.page.waitForResponse(response => response.url() === INVITES_ROUTE
      && requestAction(response) === 'decline', { timeout:30_000 });
    await decline.click();
    expect((await declineResponse).status()).toBe(200);
  } finally {
    if (playerA) await cleanupPlayer(playerA);
    if (playerB) await cleanupPlayer(playerB);
    await closePlayer(playerA);
    await closePlayer(playerB);
  }
});
