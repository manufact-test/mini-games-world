import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const INVITES_ROUTE = `${STAGING_ORIGIN}/bot/invites.php`;
const NOTIFICATIONS_ROUTE = `${STAGING_ORIGIN}/bot/notifications.php`;
const TEST_COOKIE = 'mgw_staging_test_session';

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const launchSource = readFileSync(resolve(repoRoot, 'bot/helpers/WebAppLaunchUrl.php'), 'utf8');
const entryMatch = launchSource.match(/^\s*private const ENTRY_PATH = '([^']+)';/m);
if (!entryMatch) throw new Error('Canonical WebAppLaunchUrl ENTRY_PATH is unavailable.');
const ACTIVE_ENTRY_PATH = entryMatch[1];
const APP_ROUTE = `${STAGING_ORIGIN}${ACTIVE_ENTRY_PATH}`;

function sha256(value) {
  return createHash('sha256').update(String(value)).digest('hex');
}

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

async function resetTechnicalPlayers() {
  const oidcToken = await requestOidcToken();
  const response = await fetch(AUTH_ROUTE, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${oidcToken}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ action: 'reset_test_players' }),
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok
      || payload?.ok !== true
      || payload?.invite_parity !== true
      || payload?.notification_parity !== true
      || payload?.economy_parity !== true) {
    const detail = [payload?.error, payload?.stage, payload?.reason_code]
      .filter(value => typeof value === 'string' && value !== '')
      .join(' ');
    throw new Error(`Current-core cleanup reset failed: ${response.status} ${detail || 'unknown_error'}`);
  }
  return payload;
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
  expect(payload).toMatchObject({
    ok: true,
    service: 'mini-games-world-staging-test-auth',
    action: 'issued',
    authorization_mode: 'github_actions_oidc',
    player_slot: slot,
  });

  const cookies = await context.cookies(STAGING_ORIGIN);
  const cookie = cookies.find(item => item.name === TEST_COOKIE);
  expect(cookie, `Player ${slot} auth cookie`).toBeTruthy();
  expect(cookie.httpOnly).toBe(true);
  expect(cookie.secure).toBe(true);
  expect(cookie.sameSite).toBe('Strict');
  expect(cookie.value).toMatch(/^mgwstg_[A-Za-z0-9_-]{40,80}$/);
  return cookie;
}

function requestAction(request) {
  try {
    return String(request.postDataJSON()?.action || '');
  } catch {
    return '';
  }
}

function isApiActionResponse(action) {
  return response => response.url() === API_ROUTE
    && response.request().method() === 'POST'
    && requestAction(response.request()) === action;
}

function collectDiagnostics(page, slot) {
  const report = {
    slot,
    pageErrors: [],
    serverErrors: [],
    presenceStatuses: [],
    failedRequests: [],
  };

  page.on('pageerror', error => {
    report.pageErrors.push(String(error?.message || error).slice(0, 500));
  });
  page.on('response', response => {
    if (!response.url().startsWith(STAGING_ORIGIN)) return;
    const pathname = new URL(response.url()).pathname;
    if (pathname === '/bot/presence.php') report.presenceStatuses.push(response.status());
    if (response.status() >= 500) {
      report.serverErrors.push({ status: response.status(), pathname });
    }
  });
  page.on('requestfailed', request => {
    if (!request.url().startsWith(STAGING_ORIGIN)) return;
    report.failedRequests.push({
      pathname: new URL(request.url()).pathname,
      method: request.method(),
      error: String(request.failure()?.errorText || 'request_failed').slice(0, 180),
    });
  });

  return report;
}

async function waitForRuntimeIdentity(page) {
  await page.waitForFunction(() => (
    typeof localStorage.getItem('mgw_device_session_id') === 'string'
    && localStorage.getItem('mgw_device_session_id').length > 0
    && typeof localStorage.getItem('mgw_device_id') === 'string'
    && localStorage.getItem('mgw_device_id').length > 0
  ), null, { timeout: 20_000 });
}

async function postFromPlayer(page, pathname, data) {
  return page.evaluate(async ({ pathname: requestPath, data: requestData }) => {
    const sessionId = localStorage.getItem('mgw_device_session_id');
    const deviceId = localStorage.getItem('mgw_device_id');
    const response = await fetch(requestPath, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        ...requestData,
        initData: '',
        sessionId,
        deviceId,
      }),
    });
    const payload = await response.json().catch(() => null);
    return {
      status: response.status,
      payload,
      publicError: typeof payload?.error === 'string' ? payload.error.slice(0, 300) : null,
    };
  }, { pathname, data });
}

async function expectPlayerRequest(page, pathname, data, label) {
  const result = await postFromPlayer(page, pathname, data);
  expect(
    result.status,
    `${label}; public error: ${result.publicError || 'no_public_error'}`,
  ).toBe(200);
  expect(result.payload?.ok, `${label} payload`).toBe(true);
  return result.payload;
}

async function openPlayer(browser, slot) {
  const context = await browser.newContext({
    locale: 'ru-RU',
    timezoneId: 'Europe/Vilnius',
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 1,
    isMobile: true,
    hasTouch: true,
  });
  const cookie = await authorizeContext(context, slot);
  const page = await context.newPage();
  const diagnostics = collectDiagnostics(page, slot);
  const bootstrapPromise = page.waitForResponse(isApiActionResponse('bootstrap'), { timeout: 35_000 });

  const entryResponse = await page.goto(APP_ROUTE, { waitUntil: 'domcontentloaded' });
  expect(entryResponse, `Player ${slot} active Telegram entry response`).not.toBeNull();
  expect(entryResponse.ok(), `Player ${slot} active Telegram entry status`).toBe(true);
  expect(entryResponse.headers()['x-mgw-client-bootstrap']).toBe('v2-single-owner');
  expect(entryResponse.headers()['x-mgw-query-version-manifest']).toBe('v2-route-scoped-polling');
  expect(entryResponse.headers()['x-mgw-game-zone']).toBe('unified-v1');
  expect(entryResponse.headers()['x-mgw-phase-b-presentation']).toBeTruthy();
  expect(entryResponse.headers()['x-mgw-launch-presentation']).toBeTruthy();

  const bootstrapResponse = await bootstrapPromise;
  const bootstrap = await bootstrapResponse.json().catch(() => null);
  expect(bootstrapResponse.status(), `Player ${slot} bootstrap status`).toBe(200);
  expect(bootstrap?.ok, `Player ${slot} bootstrap payload`).toBe(true);
  expect(bootstrap?.user?.id, `Player ${slot} bootstrap user`).toBe(`stg_test_player_${slot.toLowerCase()}`);
  expect(Number(bootstrap?.match_economy?.entry_cost || 0), `Player ${slot} server entry cost`).toBeGreaterThan(0);

  await page.waitForFunction(() => window.__MGW_APP_BOOTSTRAP_V2__?.ready === true, null, { timeout: 20_000 });
  await expect(page.locator('#screen-home')).toHaveClass(/active/, { timeout: 25_000 });
  await waitForRuntimeIdentity(page);

  const profile = await expectPlayerRequest(page, '/bot/api.php', { action: 'profile' }, `Player ${slot} profile`);
  expect(profile.user?.id).toBe(`stg_test_player_${slot.toLowerCase()}`);
  expect(Number(profile.user?.balance)).toBeGreaterThanOrEqual(Number(bootstrap.match_economy.entry_cost));
  expect(profile.session?.locked ?? false).toBe(false);

  const runtimeIdentity = await page.evaluate(() => ({
    sessionId: localStorage.getItem('mgw_device_session_id'),
    deviceId: localStorage.getItem('mgw_device_id'),
    build: String(window.__MGW_BUILD__ || ''),
    bootstrapReady: window.__MGW_APP_BOOTSTRAP_V2__?.ready === true,
  }));
  expect(runtimeIdentity.build).toMatch(/^v110-/);
  expect(runtimeIdentity.bootstrapReady).toBe(true);

  return { context, page, cookie, diagnostics, bootstrap, profile, runtimeIdentity };
}

async function reloadPlayer(player) {
  const bootstrapPromise = player.page.waitForResponse(isApiActionResponse('bootstrap'), { timeout: 35_000 });
  const response = await player.page.goto(APP_ROUTE, { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBe(true);
  const bootstrapResponse = await bootstrapPromise;
  expect(bootstrapResponse.status()).toBe(200);
  const bootstrap = await bootstrapResponse.json().catch(() => null);
  expect(bootstrap?.ok).toBe(true);
  await player.page.waitForFunction(() => window.__MGW_APP_BOOTSTRAP_V2__?.ready === true, null, { timeout: 20_000 });
  return bootstrap;
}

async function revokeContext(context) {
  try {
    await context.request.post(AUTH_ROUTE, {
      data: { action: 'revoke' },
      timeout: 15_000,
    });
  } catch {
    // Test identities have a bounded server TTL; reset below is the authoritative cleanup.
  }
}

async function waitForLaunch(playerA, playerB, gameId) {
  await expectPlayerRequest(playerA.page, '/bot/api.php', { action: 'game_state', gameId }, 'Player A launch readiness');
  await expectPlayerRequest(playerB.page, '/bot/api.php', { action: 'game_state', gameId }, 'Player B launch readiness');

  let readyGame = null;
  await expect.poll(async () => {
    const result = await postFromPlayer(playerA.page, '/bot/api.php', { action: 'game_state', gameId });
    if (result.status !== 200 || result.payload?.ok !== true) return `http:${result.status}`;
    const game = result.payload?.game || {};
    const serverNowMs = Number(game.server_now_ms || 0);
    const turnStartsAtMs = Number(game.turn_starts_at_ms || 0);
    const ready = String(game.status || '') === 'active'
      && String(game.launch_phase || '') === 'active'
      && String(game.turn || '') !== ''
      && serverNowMs > 0
      && turnStartsAtMs > 0
      && serverNowMs >= turnStartsAtMs;
    if (ready) readyGame = game;
    return ready ? 'ready' : `${game.launch_phase || 'missing'}:${game.turn || 'missing'}`;
  }, {
    message: 'Current-core waits for the authoritative TTT launch window',
    timeout: 20_000,
    intervals: [100, 200, 400, 800],
  }).toBe('ready');
  return readyGame;
}

async function waitForTurn(player, gameId) {
  const expectedTurn = String(player.profile.user.id);
  let game = null;
  await expect.poll(async () => {
    const result = await postFromPlayer(player.page, '/bot/api.php', { action: 'game_state', gameId });
    if (result.status !== 200 || result.payload?.ok !== true) return `http:${result.status}`;
    const candidate = result.payload?.game || {};
    const serverNowMs = Number(candidate.server_now_ms || 0);
    const turnStartsAtMs = Number(candidate.turn_starts_at_ms || 0);
    const ready = String(candidate.status || '') === 'active'
      && String(candidate.launch_phase || '') === 'active'
      && String(candidate.turn || '') === expectedTurn
      && serverNowMs >= turnStartsAtMs;
    if (ready) game = candidate;
    return ready ? 'ready' : String(candidate.turn || 'missing');
  }, {
    message: `Current-core waits for ${expectedTurn} turn`,
    timeout: 15_000,
    intervals: [100, 200, 400, 800],
  }).toBe('ready');
  return game;
}

async function firstUiTap(player, gameId, cell) {
  await waitForTurn(player, gameId);
  const locator = player.page.locator(`#screen-game.active [data-game-cell="${cell}"]`);
  await expect(locator).toBeVisible({ timeout: 25_000 });
  await expect(locator).toBeEnabled({ timeout: 25_000 });

  const responsePromise = player.page.waitForResponse(isApiActionResponse('game_action'), { timeout: 20_000 });
  await locator.click();
  const response = await responsePromise;
  const payload = await response.json().catch(() => null);
  expect(response.status(), `First TTT tap cell ${cell}`).toBe(200);
  expect(payload?.ok).toBe(true);
  expect(String(payload?.game?.id || '')).toBe(gameId);
  expect(String(payload?.game?.board || '')[cell]).not.toBe('-');
  return payload.game;
}

async function directCell(player, gameId, cell) {
  await waitForTurn(player, gameId);
  const payload = await expectPlayerRequest(
    player.page,
    '/bot/api.php',
    { action: 'game_action', gameId, gameAction: { type: 'cell', cell } },
    `TTT cell ${cell}`,
  );
  return payload.game;
}

async function matchHistoryItem(page, gameId) {
  const history = await postFromPlayer(page, '/bot/api.php', { action: 'history' });
  if (history.status !== 200 || history.payload?.ok !== true) return null;
  const matches = Array.isArray(history.payload?.history?.matches)
    ? history.payload.history.matches
    : [];
  return matches.find(match => String(match?.id || '') === gameId) || null;
}

function assertNoCurrentRuntimeServerErrors(player) {
  expect(player.diagnostics.pageErrors, `${player.diagnostics.slot} page errors`).toEqual([]);
  expect(player.diagnostics.serverErrors, `${player.diagnostics.slot} staging 5xx responses`).toEqual([]);
  for (const status of player.diagnostics.presenceStatuses) {
    expect(status, `${player.diagnostics.slot} presence response`).toBeLessThan(500);
  }
}

test('CURRENT CORE: active Telegram v110 entry completes one authoritative two-player TTT lifecycle', async ({ browser }, testInfo) => {
  let playerA;
  let playerB;
  let gameId = '';
  let inviteToken = '';

  try {
    playerA = await openPlayer(browser, 'A');
    playerB = await openPlayer(browser, 'B');

    expect(ACTIVE_ENTRY_PATH).toMatch(/^\/app\/v110\.php\?v=\d+$/);
    expect(playerA.runtimeIdentity.sessionId).not.toBe(playerB.runtimeIdentity.sessionId);
    expect(playerA.runtimeIdentity.deviceId).not.toBe(playerB.runtimeIdentity.deviceId);
    expect(playerA.cookie.value).not.toBe(playerB.cookie.value);

    const entryCost = Number(playerA.bootstrap.match_economy.entry_cost);
    expect(Number(playerB.bootstrap.match_economy.entry_cost)).toBe(entryCost);

    const beforeBalances = {
      stg_test_player_a: Number(playerA.profile.user.balance),
      stg_test_player_b: Number(playerB.profile.user.balance),
    };

    const created = await expectPlayerRequest(
      playerA.page,
      '/bot/invites.php',
      {
        action: 'create_direct',
        inviteeId: 'stg_test_player_b',
        gameType: 'tictactoe',
        boardSize: 3,
      },
      'Current-core direct invitation',
    );
    inviteToken = String(created.invite?.token || '');
    expect(inviteToken).toMatch(/^[A-Za-z0-9_-]{12,80}$/);
    expect(created.invite?.status).toBe('pending');
    expect(Number(created.invite?.bet || 0)).toBe(entryCost);

    const notifications = await expectPlayerRequest(
      playerB.page,
      '/bot/notifications.php',
      { markRead: false },
      'Player B current notifications',
    );
    const received = (Array.isArray(notifications.items) ? notifications.items : [])
      .find(item => String(item?.invite_token || '') === inviteToken);
    expect(received, 'Current direct invite must reach Player B notification projection').toBeTruthy();

    const accepted = await expectPlayerRequest(
      playerB.page,
      '/bot/invites.php',
      { action: 'accept', token: inviteToken },
      'Player B accepts current direct invitation',
    );
    expect(String(accepted.invite?.status || '')).toBe('accepted');
    expect(accepted.invite?.can_start).toBe(false);

    const started = await expectPlayerRequest(
      playerA.page,
      '/bot/invites.php',
      { action: 'start', token: inviteToken },
      'Player A starts current direct invitation',
    );
    gameId = String(started.game?.id || '');
    expect(gameId).toMatch(/^[A-Za-z0-9_-]{8,120}$/);
    expect(started.game?.status).toBe('active');
    expect(started.game?.game_type).toBe('tictactoe');
    expect(Number(started.game?.bet || 0)).toBe(entryCost);

    await Promise.all([reloadPlayer(playerA), reloadPlayer(playerB)]);
    await expect(playerA.page.locator('#screen-game')).toHaveClass(/active/, { timeout: 25_000 });
    await expect(playerB.page.locator('#screen-game')).toHaveClass(/active/, { timeout: 25_000 });
    await expect(playerA.page.locator('#screen-game [data-game-cell]')).toHaveCount(9);
    await expect(playerB.page.locator('#screen-game [data-game-cell]')).toHaveCount(9);

    let game = await waitForLaunch(playerA, playerB, gameId);
    const players = {
      stg_test_player_a: playerA,
      stg_test_player_b: playerB,
    };

    const firstActor = players[String(game.turn || '')];
    expect(firstActor, 'First authoritative TTT actor').toBeTruthy();
    game = await firstUiTap(firstActor, gameId, 0);

    for (const cell of [3, 1, 4, 2]) {
      const actor = players[String(game.turn || '')];
      expect(actor, `Authoritative actor before cell ${cell}`).toBeTruthy();
      game = await directCell(actor, gameId, cell);
    }

    expect(game?.status).toBe('finished');
    const winnerId = String(game.winner_id || '');
    expect(players[winnerId], 'Current-core winner must be A or B').toBeTruthy();
    const loserId = winnerId === 'stg_test_player_a' ? 'stg_test_player_b' : 'stg_test_player_a';

    await Promise.all([
      expect(playerA.page.locator('#resultSummary')).toBeVisible({ timeout: 30_000 }),
      expect(playerB.page.locator('#resultSummary')).toBeVisible({ timeout: 30_000 }),
    ]);
    for (const player of [playerA, playerB]) {
      await expect(player.page.locator('#resultSummary')).toContainText('Баланс:', { timeout: 15_000 });
      await expect(player.page.locator('#resultSummary')).not.toContainText('Вход:');
      await expect(player.page.locator('#resultSummary')).not.toContainText('Награда:');
    }

    const [afterA, afterB] = await Promise.all([
      expectPlayerRequest(playerA.page, '/bot/api.php', { action: 'profile' }, 'Player A post-match profile'),
      expectPlayerRequest(playerB.page, '/bot/api.php', { action: 'profile' }, 'Player B post-match profile'),
    ]);
    const afterBalances = {
      stg_test_player_a: Number(afterA.user?.balance),
      stg_test_player_b: Number(afterB.user?.balance),
    };
    const payout = Number(game.payout || 0);
    const commission = Number(game.commission || 0);
    const winnerDelta = afterBalances[winnerId] - beforeBalances[winnerId];
    const loserDelta = afterBalances[loserId] - beforeBalances[loserId];

    expect(winnerDelta).toBe(payout - entryCost);
    expect(loserDelta).toBe(-entryCost);
    expect(winnerDelta + loserDelta + commission).toBe(0);

    let winnerHistory = null;
    let loserHistory = null;
    await expect.poll(async () => {
      winnerHistory = await matchHistoryItem(players[winnerId].page, gameId);
      loserHistory = await matchHistoryItem(players[loserId].page, gameId);
      if (!winnerHistory?.economy || !loserHistory?.economy) return 'missing';
      return `${winnerHistory.economy.ledger_delta}:${loserHistory.economy.ledger_delta}`;
    }, {
      message: 'Current-core Result/History economy projection',
      timeout: 15_000,
      intervals: [100, 200, 400, 800],
    }).toBe(`${winnerDelta}:${loserDelta}`);

    expect(Number(winnerHistory.economy.new_balance)).toBe(afterBalances[winnerId]);
    expect(Number(loserHistory.economy.new_balance)).toBe(afterBalances[loserId]);

    await playerA.page.waitForTimeout(4_500);
    assertNoCurrentRuntimeServerErrors(playerA);
    assertNoCurrentRuntimeServerErrors(playerB);

    await testInfo.attach('current-staging-core-report', {
      body: Buffer.from(`${JSON.stringify({
        ok: true,
        activeEntryPath: ACTIVE_ENTRY_PATH,
        game: {
          idSha256: sha256(gameId),
          type: 'tictactoe',
          entryCost,
          commission,
          payout,
          firstTapCommittedOnce: true,
        },
        economy: {
          winnerDelta,
          loserDelta,
          resultHistoryConsistent: true,
        },
        isolation: {
          sessionsDistinct: true,
          devicesDistinct: true,
          cookiesDistinct: true,
        },
        diagnostics: {
          A: playerA.diagnostics,
          B: playerB.diagnostics,
        },
        productionChanged: false,
        livePaymentsUsed: false,
      }, null, 2)}\n`, 'utf8'),
      contentType: 'application/json',
    });
  } finally {
    for (const player of [playerA, playerB]) {
      if (!player?.context) continue;
      await revokeContext(player.context);
      await player.context.close();
    }
    await resetTechnicalPlayers();
  }
});