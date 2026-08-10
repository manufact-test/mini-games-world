import { test, expect } from '@playwright/test';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const INVITES_ROUTE = `${STAGING_ORIGIN}/bot/invites.php`;
const APP_ROUTE = `${STAGING_ORIGIN}/app/v110.php?v=1123`;

const GAMES = [
  { type:'tictactoe', title:'Крестики-нолики' },
  { type:'four_in_a_row', title:'4 в ряд' },
  { type:'battleship', title:'Морской бой' },
  { type:'checkers', title:'Шашки' },
  { type:'reversi', title:'Реверси' },
  { type:'chess', title:'Шахматы' },
  { type:'go', title:'Го' },
  { type:'domino', title:'Домино' },
];

async function requestOidcToken(){
  const requestUrl = process.env.ACTIONS_ID_TOKEN_REQUEST_URL || '';
  const requestToken = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN || '';
  if (!requestUrl || !requestToken) throw new Error('GitHub Actions OIDC environment is unavailable.');

  const url = new URL(requestUrl);
  url.searchParams.set('audience', OIDC_AUDIENCE);
  const response = await fetch(url, {
    headers:{ Authorization:`bearer ${requestToken}`, Accept:'application/json' },
  });
  if (!response.ok) throw new Error(`GitHub Actions OIDC request failed with status ${response.status}.`);
  const payload = await response.json();
  if (typeof payload?.value !== 'string' || payload.value.split('.').length !== 3) {
    throw new Error('GitHub Actions OIDC response did not contain a JWT.');
  }
  return payload.value;
}

async function resetTestPlayers(){
  const oidcToken = await requestOidcToken();
  const response = await fetch(AUTH_ROUTE, {
    method:'POST',
    headers:{
      Authorization:`Bearer ${oidcToken}`,
      Accept:'application/json',
      'Content-Type':'application/json',
    },
    body:JSON.stringify({ action:'reset_test_players' }),
  });
  const payload = await response.json().catch(() => null);
  expect(response.status, `test-player reset: ${payload?.error || 'no_public_error'}`).toBe(200);
  expect(payload).toMatchObject({ ok:true, match_balance:100, economy_parity:true });
}

async function authorizeContext(context, slot){
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
  expect(response.status(), `Player ${slot} auth status`).toBe(200);
  const payload = await response.json();
  expect(payload).toMatchObject({ ok:true, action:'issued', player_slot:slot });
}

function isActionResponse(route, action){
  return response => {
    if (response.url() !== route || response.request().method() !== 'POST') return false;
    try {
      return String(response.request().postDataJSON()?.action || '') === action;
    } catch {
      return false;
    }
  };
}

async function openPlayer(browser, slot){
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
  const bootstrapPromise = page.waitForResponse(isActionResponse(API_ROUTE, 'bootstrap'), { timeout:35_000 });
  const response = await page.goto(APP_ROUTE, { waitUntil:'domcontentloaded' });
  expect(response, `Player ${slot} v110 response`).not.toBeNull();
  expect(response.ok(), `Player ${slot} v110 status`).toBe(true);
  expect(response.headers()['x-mgw-phase-b-presentation']).toBe('v123-v110-deterministic-loader');

  const bootstrapResponse = await bootstrapPromise;
  expect(bootstrapResponse.status(), `Player ${slot} bootstrap status`).toBe(200);
  const bootstrap = await bootstrapResponse.json().catch(() => null);
  expect(bootstrap?.ok, `Player ${slot} bootstrap payload`).toBe(true);
  await expect(page.locator('#notificationsOpen')).toBeVisible({ timeout:20_000 });
  return { context, page };
}

async function postFromPlayer(page, pathname, data){
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
    });
    return {
      status:response.status,
      payload:await response.json().catch(() => null),
    };
  }, { pathname, data });
}

async function expectPlayerRequest(page, pathname, data, label){
  const result = await postFromPlayer(page, pathname, data);
  expect(result.status, `${label}; ${result.payload?.error || 'no_public_error'}`).toBe(200);
  expect(result.payload?.ok, `${label} payload`).toBe(true);
  return result.payload;
}

async function openNotificationsAction(page, token, action){
  const overlay = page.locator('#sheetOverlay');
  if (await overlay.evaluate(node => node.classList.contains('active')).catch(() => false)) {
    await page.keyboard.press('Escape').catch(() => null);
    await expect(overlay).not.toHaveClass(/active/, { timeout:15_000 });
  }

  await page.locator('#notificationsOpen').click();
  await expect(page.locator('#sheet .sheet-head h2')).toHaveText('Уведомления', { timeout:20_000 });
  const button = page.locator(`[data-invite-action="${action}"][data-invite-token="${token}"]`);
  await expect(button).toBeVisible({ timeout:25_000 });
  await expect(button).toBeEnabled();
  return button;
}

async function clickInviteAction(page, button, action){
  const responsePromise = page.waitForResponse(isActionResponse(INVITES_ROUTE, action), { timeout:30_000 });
  await button.click();
  const response = await responsePromise;
  const payload = await response.json().catch(() => null);
  expect(response.status(), `${action} invite action; ${payload?.error || 'no_public_error'}`).toBe(200);
  expect(payload?.ok, `${action} invite payload`).toBe(true);
  return payload;
}

async function installLaunchTrace(page){
  await page.evaluate(() => {
    window.__MGW_PHASE_B_ALL_GAME_TRACE_OBSERVER__?.disconnect?.();
    window.__MGW_PHASE_B_ALL_GAME_TRACE__ = [];

    const capture = () => {
      const overlay = document.getElementById('mgwPhaseBLaunchOverlay');
      if (!overlay || overlay.hidden) return;
      const countdown = overlay.querySelector('[data-phase-b-countdown]');
      const gameLabel = overlay.querySelector('[data-phase-b-game]');
      const entry = {
        stage:String(overlay.dataset.stage || ''),
        text:String(countdown?.textContent || '').trim(),
        game:String(gameLabel?.textContent || '').trim(),
        at:performance.now(),
      };
      const trace = window.__MGW_PHASE_B_ALL_GAME_TRACE__;
      const previous = trace[trace.length - 1];
      if (!previous
          || previous.stage !== entry.stage
          || previous.text !== entry.text
          || previous.game !== entry.game) {
        trace.push(entry);
      }
    };

    const observer = new MutationObserver(capture);
    observer.observe(document.documentElement, {
      subtree:true,
      childList:true,
      characterData:true,
      attributes:true,
      attributeFilter:['hidden', 'data-stage', 'class'],
    });
    window.__MGW_PHASE_B_ALL_GAME_TRACE_OBSERVER__ = observer;
    capture();
  });
}

async function readLaunchTrace(page){
  return page.evaluate(() => Array.isArray(window.__MGW_PHASE_B_ALL_GAME_TRACE__)
    ? window.__MGW_PHASE_B_ALL_GAME_TRACE__.map(entry => ({ ...entry }))
    : []);
}

function coreSequence(trace){
  const values = [];
  for (const entry of trace) {
    const value = entry.stage === 'number' ? entry.text : entry.stage;
    if (!['3', '2', '1', 'ready', 'sync', 'prepare'].includes(value)) continue;
    if (values[values.length - 1] !== value) values.push(value);
  }
  return values;
}

function hasOrderedCompletion(trace){
  const values = coreSequence(trace);
  let cursor = -1;
  for (const expected of ['3', '2', '1', 'ready']) {
    cursor = values.indexOf(expected, cursor + 1);
    if (cursor < 0) return false;
  }
  return true;
}

for (const game of GAMES) {
  test(`Phase B shared loader completes for ${game.type}`, async ({ browser }, testInfo) => {
    let playerA;
    let playerB;
    let inviteToken = '';

    try {
      await resetTestPlayers();
      playerA = await openPlayer(browser, 'A');
      playerB = await openPlayer(browser, 'B');

      const created = await expectPlayerRequest(
        playerA.page,
        '/bot/invites.php',
        {
          action:'create_direct',
          inviteeId:'stg_test_player_b',
          gameType:game.type,
          room:'match',
          bet:10,
          boardSize:3,
        },
        `${game.type} direct invite`,
      );
      inviteToken = String(created.invite?.token || '');
      expect(inviteToken).toMatch(/^[a-f0-9]{24}$/);
      expect(created.invite?.game_type).toBe(game.type);

      const acceptButton = await openNotificationsAction(playerB.page, inviteToken, 'accept');
      const accepted = await clickInviteAction(playerB.page, acceptButton, 'accept');
      expect(['accepted', 'awaiting_start']).toContain(String(accepted.invite?.status || ''));

      await installLaunchTrace(playerA.page);
      await installLaunchTrace(playerB.page);

      const startButton = await openNotificationsAction(playerA.page, inviteToken, 'start');
      const started = await clickInviteAction(playerA.page, startButton, 'start');
      const gameId = String(started.game?.id || '');
      expect(gameId).toMatch(/^[A-Za-z0-9_-]{8,120}$/);
      expect(started.game?.game_type).toBe(game.type);
      expect(started.game?.status).toBe('active');

      for (const [slot, player] of [['A', playerA], ['B', playerB]]) {
        await expect(player.page.locator('#screen-game')).toHaveClass(/active/, { timeout:30_000 });
        await expect.poll(async () => hasOrderedCompletion(await readLaunchTrace(player.page)), {
          message:`${game.type} Player ${slot} sees 3 -> 2 -> 1 -> ready`,
          timeout:25_000,
          intervals:[100, 150, 250, 400],
        }).toBe(true);
        await expect(player.page.locator('#mgwPhaseBLaunchOverlay')).toBeHidden({ timeout:15_000 });
        await expect(player.page.locator('#screen-game')).toHaveAttribute('data-game-type', game.type);

        const trace = await readLaunchTrace(player.page);
        const sequence = coreSequence(trace);
        expect(sequence.indexOf('3')).toBeLessThan(sequence.indexOf('2'));
        expect(sequence.indexOf('2')).toBeLessThan(sequence.indexOf('1'));
        expect(sequence.indexOf('1')).toBeLessThan(sequence.indexOf('ready'));
        expect(trace.some(entry => entry.game === game.title)).toBe(true);
        expect(trace.some(entry => /VS|СТАРТ/i.test(entry.text))).toBe(false);
      }

      await testInfo.attach(`phase-b-${game.type}-loader-trace`, {
        body:Buffer.from(`${JSON.stringify({
          game:game.type,
          title:game.title,
          playerA:await readLaunchTrace(playerA.page),
          playerB:await readLaunchTrace(playerB.page),
          productionChanged:false,
          livePaymentsUsed:false,
        }, null, 2)}\n`, 'utf8'),
        contentType:'application/json',
      });
    } finally {
      if (playerA?.context) await playerA.context.close().catch(() => null);
      if (playerB?.context) await playerB.context.close().catch(() => null);
      await resetTestPlayers();
    }
  });
}
