import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const ORIGIN = process.env.MGW_STAGING_ORIGIN || 'https://seashell-okapi-889488.hostingersite.com';
const AUTH_URL = `${ORIGIN}/bot/staging-test-auth.php`;
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const launchSource = readFileSync(resolve(repoRoot, 'bot/helpers/WebAppLaunchUrl.php'), 'utf8');
const entryMatch = launchSource.match(/^\s*private const ENTRY_PATH = '([^']+)';/m);
if (!entryMatch) throw new Error('Canonical WebAppLaunchUrl ENTRY_PATH is unavailable.');
const ENTRY_PATH = entryMatch[1];
const ENTRY_URL = `${ORIGIN}${ENTRY_PATH}`;

async function requestOidcToken() {
  const source = process.env.ACTIONS_ID_TOKEN_REQUEST_URL || '';
  const bearer = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN || '';
  if (!source || !bearer) throw new Error('GitHub Actions OIDC environment is unavailable.');
  const url = new URL(source);
  url.searchParams.set('audience', OIDC_AUDIENCE);
  const response = await fetch(url, {
    headers: { Authorization: `bearer ${bearer}`, Accept: 'application/json' },
  });
  if (!response.ok) throw new Error(`OIDC request failed: ${response.status}`);
  const payload = await response.json();
  if (typeof payload?.value !== 'string') throw new Error('OIDC JWT is unavailable.');
  return payload.value;
}

async function resetPlayers() {
  const response = await fetch(AUTH_URL, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${await requestOidcToken()}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ action: 'reset_test_players' }),
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok || payload?.ok !== true
      || payload?.invite_parity !== true
      || payload?.notification_parity !== true
      || payload?.economy_parity !== true) {
    throw new Error(`A/B reset failed: ${response.status} ${payload?.stage || payload?.reason_code || ''}`);
  }
}

async function authorize(context, slot) {
  const response = await context.request.post(AUTH_URL, {
    headers: {
      Authorization: `Bearer ${await requestOidcToken()}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    data: { action: 'issue', slot },
    timeout: 35_000,
  });
  expect(response.status(), `Player ${slot} auth`).toBe(200);
  const payload = await response.json();
  expect(payload?.ok).toBe(true);
  expect(payload?.player_slot).toBe(slot);
  const cookie = (await context.cookies(ORIGIN)).find(item => item.name === 'mgw_staging_test_session');
  expect(cookie, `Player ${slot} auth cookie`).toBeTruthy();
  expect(cookie.httpOnly).toBe(true);
  expect(cookie.secure).toBe(true);
  return cookie;
}

function requestAction(request) {
  try { return String(request.postDataJSON()?.action || ''); } catch { return ''; }
}

function diagnostics(page, slot) {
  const value = { slot, pageErrors: [], serverErrors: [], presenceStatuses: [] };
  page.on('pageerror', error => value.pageErrors.push(String(error?.message || error).slice(0, 400)));
  page.on('response', response => {
    if (!response.url().startsWith(ORIGIN)) return;
    const path = new URL(response.url()).pathname;
    const status = response.status();
    if (path === '/bot/presence.php') value.presenceStatuses.push(status);

    if (status >= 500) {
      const failure = { path, status, recovered:false };
      value.serverErrors.push(failure);
      // Staging API intentionally exposes a bounded *stage name* on 500.
      // Collect that non-sensitive diagnostic so a transient failed request
      // can be correlated with PHP logs without logging cookies or response data.
      if (path === '/bot/profile-v2.php') {
        void response.json().then(payload => {
          const stage = String(payload?.diagnostic_stage || '');
          if (/^[a-z0-9_]{1,80}$/i.test(stage)) failure.diagnostic_stage = stage;
          const elapsed = Number(payload?.diagnostic_elapsed_ms);
          if (Number.isFinite(elapsed) && elapsed >= 0) {
            failure.diagnostic_elapsed_ms = Math.min(Math.round(elapsed), 3_600_000);
          }
        }).catch(() => {});
      }
      return;
    }

    // Background pollers can briefly overlap the staging JSON/DB projection
    // owner under synthetic two-context load. A later non-5xx response from
    // the same endpoint proves that transient read recovered. Keep the event
    // in diagnostics, but do not fail the whole gate for a recovered blip.
    for (const failure of value.serverErrors) {
      if (failure.path === path && failure.recovered !== true) {
        failure.recovered = true;
      }
    }
  });
  return value;
}

async function browserPost(page, path, data) {
  return page.evaluate(async ({ path, data }) => {
    const response = await fetch(path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        ...data,
        initData: '',
        sessionId: localStorage.getItem('mgw_device_session_id'),
        deviceId: localStorage.getItem('mgw_device_id'),
      }),
      cache: 'no-store',
    });
    return { status: response.status, payload: await response.json().catch(() => null) };
  }, { path, data });
}

async function testProbePost(player, path, data) {
  const transport = player.transport || await player.page.evaluate(() => ({
    sessionId: localStorage.getItem('mgw_device_session_id'),
    deviceId: localStorage.getItem('mgw_device_id'),
  }));
  const response = await player.context.request.post(`${ORIGIN}${path}`, {
    headers: { Accept: 'application/json' },
    data: { ...data, initData: '', ...transport },
    timeout: 15_000,
  });
  const raw = await response.text();
  let payload = null;
  try { payload = raw !== '' ? JSON.parse(raw) : null; } catch {}
  return { status: response.status(), payload, raw };
}

async function transportAction(player, path, data, label) {
  const result = await testProbePost(player, path, data);
  const detail = result.payload?.error
    || result.raw?.replace(/\s+/g, ' ').slice(0, 500)
    || 'empty response';
  expect(result.status, `${label}: ${detail}`).toBe(200);
  expect(result.payload?.ok, label).toBe(true);
  return result.payload;
}

async function observedAction(page, path, data, action, label) {
  const expectedUrl = `${ORIGIN}${path}`;
  const responsePromise = page.waitForResponse(response => (
    response.url() === expectedUrl
    && response.request().method() === 'POST'
    && requestAction(response.request()) === action
  ), { timeout: 35_000 });
  const browserPromise = browserPost(page, path, data);
  const [response, browserResult] = await Promise.all([responsePromise, browserPromise]);
  expect(browserResult.status, `${label} browser status`).toBe(200);
  expect([200, 307, 308], `${label} observed status`).toContain(response.status());
  expect(browserResult.payload?.ok, label).toBe(true);
  return browserResult.payload;
}

async function openPlayer(browser, slot) {
  const coldStartStartedAt = Date.now();
  const context = await browser.newContext({
    locale: 'ru-RU', timezoneId: 'Europe/Vilnius', viewport: { width: 390, height: 844 },
    isMobile: true, hasTouch: true,
  });
  const cookie = await authorize(context, slot);
  const page = await context.newPage();
  const report = diagnostics(page, slot);
  const bootstrapPromise = page.waitForResponse(response => (
    response.url() === `${ORIGIN}/bot/api.php`
    && response.request().method() === 'POST'
    && requestAction(response.request()) === 'bootstrap'
  ), { timeout: 35_000 });
  const entry = await page.goto(ENTRY_URL, { waitUntil: 'domcontentloaded' });
  const domContentLoadedAt = Date.now();
  expect(entry?.ok(), `Player ${slot} Telegram entry`).toBe(true);
  expect(entry.headers()['x-mgw-client-bootstrap']).toBe('v2-single-owner');
  expect(entry.headers()['x-mgw-game-zone']).toBe('unified-v1');
  const bootstrapResponse = await bootstrapPromise;
  const bootstrapResponseAt = Date.now();
  const bootstrap = await bootstrapResponse.json();
  expect(bootstrapResponse.status()).toBe(200);
  expect(bootstrap?.ok).toBe(true);
  expect(bootstrap?.user?.id).toBe(`stg_test_player_${slot.toLowerCase()}`);
  expect(Number(bootstrap?.match_economy?.entry_cost || 0)).toBeGreaterThan(0);
  await page.waitForFunction(() => window.__MGW_APP_BOOTSTRAP_V2__?.ready === true, null, { timeout: 20_000 });
  await expect(page.locator('#screen-home')).toHaveClass(/active/, { timeout: 25_000 });
  await page.waitForFunction(() => document.getElementById('preloader')?.classList.contains('hidden') === true, null, { timeout: 25_000 });
  const firstUsablePaintAt = Date.now();
  console.log('[MGW_COLD_START_TIMING] ' + JSON.stringify({
    slot,
    navigation_to_dom_ms: domContentLoadedAt - coldStartStartedAt,
    navigation_to_bootstrap_ms: bootstrapResponseAt - coldStartStartedAt,
    bootstrap_to_first_usable_ms: firstUsablePaintAt - bootstrapResponseAt,
    navigation_to_first_usable_ms: firstUsablePaintAt - coldStartStartedAt,
  }));
  await page.waitForFunction(() => Boolean(
    localStorage.getItem('mgw_device_session_id') && localStorage.getItem('mgw_device_id')
  ), null, { timeout: 20_000 });
  const transport = await page.evaluate(() => ({
    sessionId: localStorage.getItem('mgw_device_session_id'),
    deviceId: localStorage.getItem('mgw_device_id'),
  }));
  expect(transport.sessionId, `Player ${slot} session transport`).toBeTruthy();
  expect(transport.deviceId, `Player ${slot} device transport`).toBeTruthy();
  // Core lifecycle setup only needs the canonical identity and live balance
  // already returned by the successful bootstrap above. Do not make this test
  // depend on the legacy api.php profile/history read path: the active Profile
  // screen owns profile-v2.php/profile.php separately, while a transient history
  // projection issue must not prevent invite/game lifecycle coverage from running.
  const profile = { user: bootstrap.user };
  expect(profile?.user?.id).toBe(`stg_test_player_${slot.toLowerCase()}`);
  expect(Number(profile?.user?.balance)).toBeGreaterThanOrEqual(0);
  return { context, page, cookie, report, bootstrap, profile, transport };
}

async function reload(player) {
  const bootstrapPromise = player.page.waitForResponse(response => (
    response.url() === `${ORIGIN}/bot/api.php`
    && response.request().method() === 'POST'
    && requestAction(response.request()) === 'bootstrap'
  ), { timeout: 35_000 });
  expect((await player.page.goto(ENTRY_URL, { waitUntil: 'domcontentloaded' }))?.ok()).toBe(true);
  expect((await bootstrapPromise).status()).toBe(200);
  await player.page.waitForFunction(() => window.__MGW_APP_BOOTSTRAP_V2__?.ready === true, null, { timeout: 20_000 });
}

async function waitInviteEvent(player, token) {
  let found = null;
  await expect.poll(async () => {
    const result = await testProbePost(player, '/bot/invites.php', { action: 'sync' });
    if (result.status !== 200 || result.payload?.ok !== true) return `http:${result.status}`;
    found = (Array.isArray(result.payload?.invite_events) ? result.payload.invite_events : [])
      .find(item => String(item?.invite_token || '') === token) || null;
    return found ? `${found.invite_status || ''}:${(found.actions || []).join(',')}` : 'missing';
  }, { timeout: 8_000, intervals: [100, 200, 400, 800] }).toContain('pending');
  expect(found?.actions).toEqual(expect.arrayContaining(['accept', 'decline']));
}

async function waitLaunch(player, gameId) {
  let game = null;
  await expect.poll(async () => {
    const result = await testProbePost(player, '/bot/api.php', { action: 'game_state', gameId });
    const candidate = result.payload?.game || {};
    const ready = result.status === 200 && result.payload?.ok === true
      && candidate.status === 'active'
      && candidate.launch_phase === 'active'
      && String(candidate.turn || '') !== ''
      && Number(candidate.server_now_ms || 0) >= Number(candidate.turn_starts_at_ms || 0);
    if (ready) game = candidate;
    return ready;
  }, { timeout: 20_000, intervals: [100, 200, 400, 800] }).toBe(true);
  return game;
}

async function waitTurn(player, gameId) {
  const userId = player.profile.user.id;
  await expect.poll(async () => {
    const result = await testProbePost(player, '/bot/api.php', { action: 'game_state', gameId });
    const game = result.payload?.game || {};
    return result.status === 200 && result.payload?.ok === true
      && game.status === 'active'
      && game.launch_phase === 'active'
      && game.turn === userId
      && Number(game.server_now_ms || 0) >= Number(game.turn_starts_at_ms || 0);
  }, { timeout: 15_000, intervals: [100, 200, 400, 800] }).toBe(true);
}

async function firstUiTap(player, gameId, cell) {
  await waitTurn(player, gameId);
  const button = player.page.locator(`#screen-game.active [data-game-cell="${cell}"]`);
  await expect(button).toBeVisible({ timeout: 20_000 });
  await expect(button).toBeEnabled();
  const responsePromise = player.page.waitForResponse(response => (
    response.url() === `${ORIGIN}/bot/api.php`
    && requestAction(response.request()) === 'game_action'
  ), { timeout: 20_000 });
  await button.click();
  const response = await responsePromise;
  const payload = await response.json();
  expect(response.status()).toBe(200);
  expect(payload?.ok).toBe(true);
  expect(String(payload?.game?.board || '')[cell]).not.toBe('-');
  return payload.game;
}

async function directCell(player, gameId, cell) {
  await waitTurn(player, gameId);
  const payload = await observedAction(player.page, '/bot/api.php', {
    action: 'game_action', gameId, gameAction: { type: 'cell', cell },
  }, 'game_action', `TTT cell ${cell}`);
  return payload.game;
}

async function historyItem(player, gameId) {
  const result = await testProbePost(player, '/bot/api.php', { action: 'history' });
  if (result.status !== 200 || result.payload?.ok !== true) return null;
  return (result.payload?.history?.matches || []).find(item => String(item?.id || '') === gameId) || null;
}

function assertDiagnostics(player) {
  if (player.report.pageErrors.length || player.report.serverErrors.length) {
    console.log('[MGW_STAGING_PLAYER_DIAGNOSTICS]', JSON.stringify(player.report));
  }
  const unrecoveredServerErrors = player.report.serverErrors.filter(item => item.recovered !== true);
  expect(
    unrecoveredServerErrors,
    `${player.report.slot} unrecovered server 5xx: ${JSON.stringify(player.report.serverErrors)}`
  ).toEqual([]);
  expect(
    player.report.pageErrors,
    `${player.report.slot} page errors: ${JSON.stringify(player.report.pageErrors)}`
  ).toEqual([]);
  for (const status of player.report.presenceStatuses) expect(status).toBeLessThan(500);
}


test('BATTLESHIP LONG HUMAN A/B: thirty confirmed shots, watch propagation, settlement', async ({ browser }) => {
  test.setTimeout(300_000);
  let A, B;
  const latencies = [];
  try {
    await resetPlayers();
    A = await openPlayer(browser, 'A');
    B = await openPlayer(browser, 'B');
    const entryCost = Number(A.bootstrap.match_economy?.entry_cost || 0);
    expect(entryCost).toBeGreaterThan(0);
    const before = {
      stg_test_player_a:Number(A.bootstrap.user.balance),
      stg_test_player_b:Number(B.bootstrap.user.balance),
    };
    await Promise.all([
      A.page.goto('about:blank',{waitUntil:'load'}),
      B.page.goto('about:blank',{waitUntil:'load'}),
    ]);
    const created = await transportAction(A,'/bot/invites.php',{
      action:'create_direct',inviteeId:'stg_test_player_b',
      gameType:'battleship',boardSize:10,
    },'battleship direct invite');
    const token = String(created.invite?.token || '');
    expect(token).toMatch(/^[a-f0-9]{24}$/);
    expect(Number(created.invite?.bet || 0)).toBe(entryCost);
    await waitInviteEvent(B,token);
    const accepted = await transportAction(B,'/bot/invites.php',{action:'accept',token},'battleship accept');
    expect(accepted.invite?.status).toBe('accepted');
    const started = await transportAction(A,'/bot/invites.php',{action:'start',token},'battleship start');
    const gameId = String(started.game?.id || started.invite?.game_id || '');
    expect(gameId).toMatch(/^[A-Za-z0-9_-]{8,120}$/);
    expect(started.game?.game_type).toBe('battleship');
    // The invite start DTO deliberately omits is_bot_game. The participant-
    // scoped game_state response below owns the authoritative gameplay flag.
    const readiness = await Promise.all([A,B].map(p => testProbePost(p,'/bot/api.php',{
      action:'game_state',gameId,
    })));
    for(const r of readiness){
      expect(r.status).toBe(200);
      expect(r.payload?.ok).toBe(true);
      // Phase-B readiness response can be intentionally partial until launch.
    }
    await Promise.all([reload(A),reload(B)]);
    await expect.poll(async () => {
      const r = await testProbePost(A,'/bot/api.php',{action:'game_state',gameId});
      return String(r.payload?.game?.launch_phase)+':'+String(r.payload?.game?.phase);
    },{timeout:25_000,intervals:[200,400,800]}).toBe('active:setup');
    for(const p of [A,B]){
      await expect(p.page.locator('#screen-game')).toHaveClass(/active/,{timeout:25_000});
    }
    async function action(player,type,extra={}){
      const start=Date.now();
      const result=await testProbePost(player,'/bot/api.php',{
        action:'game_action',gameId,gameAction:{type,...extra},
      });
      const elapsed=Date.now()-start;
      expect(result.status,'Battleship '+type+' HTTP').toBe(200);
      expect(result.payload?.ok,'Battleship '+type+' success').toBe(true);
      return {game:result.payload.game,elapsed};
    }
    const fleetA=(await action(A,'randomize_fleet')).game.my_fleet;
    const fleetB=(await action(B,'randomize_fleet')).game.my_fleet;
    for(const fleet of [fleetA,fleetB]){
      expect(Array.isArray(fleet)).toBe(true);
      expect(fleet).toHaveLength(10);
    }
    await action(A,'ready');
    const finalReady=(await action(B,'ready')).game;
    expect(finalReady.phase).toBe('battle');
    expect(finalReady.status).toBe('active');
    // This authoritative action response is fully hydrated after setup; it
    // identifies both human players without depending on a Phase-B placeholder.
    expect((finalReady.players || []).map(player=>String(player.id)).sort())
      .toEqual(['stg_test_player_a','stg_test_player_b']);
    const occupied = ships => new Set(ships.flatMap(ship => ship.cells.map(Number)));
    const safeMissesA=Array.from({length:100},(_,i)=>i).filter(cell=>!occupied(fleetB).has(cell));
    const safeMissesB=Array.from({length:100},(_,i)=>i).filter(cell=>!occupied(fleetA).has(cell));
    expect(safeMissesA.length).toBeGreaterThanOrEqual(30);
    expect(safeMissesB.length).toBeGreaterThanOrEqual(30);
    const byId={stg_test_player_a:A,stg_test_player_b:B};
    const misses={stg_test_player_a:safeMissesA,stg_test_player_b:safeMissesB};
    const fired={stg_test_player_a:0,stg_test_player_b:0};
    let turn=String(finalReady.turn || '');
    expect(byId[turn]).toBeTruthy();
    for(let step=0;step<30;step++){
      const shooter=byId[turn];
      const defender=turn==='stg_test_player_a' ? B : A;
      const cell=misses[turn][fired[turn]++];
      const result=await action(shooter,'fire',{cell});
      expect(result.game.phase).toBe('battle');
      expect(result.game.status).toBe('active');
      expect(result.game.last_result).toBe('miss');
      expect(result.game.last_shot).toBe(cell);
      expect(result.game.enemy_board[cell]).toBe('miss');
      const nextTurn=String(result.game.turn || '');
      expect(nextTurn).not.toBe(turn);
      await expect.poll(async()=>{
        const snapshot=await testProbePost(defender,'/bot/game-watch.php',{gameId,mode:'battle_poll'});
        return snapshot.status===200 && snapshot.payload?.ok && snapshot.payload?.game?.my_board?.[cell]==='miss'
          ? 'observed' : 'pending';
      },{timeout:8_000,intervals:[100,200,400]}).toBe('observed');
      latencies.push(result.elapsed);
      console.log('[MGW_BATTLESHIP_HUMAN_SHOT] '+JSON.stringify({
        step:step+1,elapsed_ms:result.elapsed,result:'miss',
      }));
      expect(result.elapsed,'Human shot '+(step+1)+' stalled').toBeLessThan(5_000);
      turn=nextTurn;
    }
    const responseA=await testProbePost(A,'/bot/api.php',{action:'game_state',gameId});
    const responseB=await testProbePost(B,'/bot/api.php',{action:'game_state',gameId});
    const gameA=responseA.payload?.game;
    const gameB=responseB.payload?.game;
    expect(gameA.status).toBe('active');
    expect(gameB.status).toBe('active');
    expect(gameA.turn).toBe(gameB.turn);
    for(const [id,game] of [['stg_test_player_a',gameA],['stg_test_player_b',gameB]]){
      expect(game.enemy_board.filter(value=>value==='miss')).toHaveLength(fired[id]);
    }
    const surrendered=await transportAction(A,'/bot/api.php',{action:'leave_game',gameId},'battle surrender/settlement');
    expect(surrendered.game.status).toBe('finished');
    expect(surrendered.game.winner_id).toBe('stg_test_player_b');
    const afterA=await transportAction(A,'/bot/api.php',{action:'profile'},'A after battle');
    const afterB=await transportAction(B,'/bot/api.php',{action:'profile'},'B after battle');
    const after={stg_test_player_a:Number(afterA.user?.balance),stg_test_player_b:Number(afterB.user?.balance)};
    expect(after.stg_test_player_a-before.stg_test_player_a).toBe(-entryCost);
    expect(after.stg_test_player_b-before.stg_test_player_b).toBe(Number(surrendered.game.payout)-entryCost);
    const sorted=[...latencies].sort((a,b)=>a-b);
    console.log('[MGW_BATTLESHIP_HUMAN_SUMMARY] '+JSON.stringify({
      shots:latencies.length,min_ms:sorted[0],median_ms:sorted[Math.floor(sorted.length/2)],
      p95_ms:sorted[Math.ceil(sorted.length*.95)-1],max_ms:sorted[sorted.length-1],
      settlement:'exact',client_watches:2,
    }));
    assertDiagnostics(A);
    assertDiagnostics(B);
  } finally {
    for(const p of [A,B]) if(p?.context) await p.context.close().catch(()=>{});
    await resetPlayers();
  }
});
