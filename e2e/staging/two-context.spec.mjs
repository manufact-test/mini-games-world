import { createHash } from 'node:crypto';
import { test, expect } from '@playwright/test';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = `${STAGING_ORIGIN}/app/`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const INVITES_ROUTE = `${STAGING_ORIGIN}/bot/invites.php`;
const TEST_COOKIE = 'mgw_staging_test_session';

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

async function authorizeContext(context, slot) {
  const oidcToken = await requestOidcToken();
  const response = await context.request.post(AUTH_ROUTE, {
    headers: {
      Authorization: `Bearer ${oidcToken}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    data: {
      action: 'issue',
      slot,
    },
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
    ttl_seconds: 900,
    cookie: {
      http_only: true,
      secure: true,
      same_site: 'Strict',
    },
  });

  const cookies = await context.cookies(STAGING_ORIGIN);
  const cookie = cookies.find((item) => item.name === TEST_COOKIE);
  expect(cookie, `Player ${slot} auth cookie`).toBeTruthy();
  expect(cookie.httpOnly).toBe(true);
  expect(cookie.secure).toBe(true);
  expect(cookie.sameSite).toBe('Strict');
  expect(cookie.value).toMatch(/^mgwstg_[A-Za-z0-9_-]{40,80}$/);
  return cookie;
}

function collectDiagnostics(page, slot) {
  const report = {
    slot,
    consoleErrors: [],
    pageErrors: [],
    failedRequests: [],
    serverErrors: [],
  };

  page.on('console', (message) => {
    if (message.type() === 'error') {
      report.consoleErrors.push(message.text().slice(0, 500));
    }
  });
  page.on('pageerror', (error) => {
    report.pageErrors.push(String(error?.message || error).slice(0, 500));
  });
  page.on('requestfailed', (request) => {
    if (request.url().startsWith(STAGING_ORIGIN)) {
      report.failedRequests.push({
        method: request.method(),
        url: new URL(request.url()).pathname,
        error: String(request.failure()?.errorText || 'request_failed').slice(0, 200),
      });
    }
  });
  page.on('response', (response) => {
    if (response.url().startsWith(STAGING_ORIGIN) && response.status() >= 500) {
      report.serverErrors.push({
        status: response.status(),
        url: new URL(response.url()).pathname,
      });
    }
  });

  return report;
}

function requestAction(response) {
  try {
    return String(response.request().postDataJSON()?.action || '');
  } catch {
    return '';
  }
}

function isBootstrapResponse(response) {
  return response.url() === API_ROUTE
    && response.request().method() === 'POST'
    && requestAction(response) === 'bootstrap';
}

function isActionResponse(route, action) {
  return (response) => response.url() === route
    && response.request().method() === 'POST'
    && requestAction(response) === action;
}

async function waitForApplicationBootstrap(page, slot) {
  const response = await page.waitForResponse(isBootstrapResponse, { timeout: 35_000 });
  const payload = await response.json().catch(() => null);
  const publicError = typeof payload?.error === 'string' ? payload.error.slice(0, 300) : 'no_public_error';
  expect(
    response.status(),
    `Player ${slot} bootstrap status; public error: ${publicError}`,
  ).toBe(200);
  expect(payload?.ok, `Player ${slot} bootstrap payload`).toBe(true);
  expect(payload?.user, `Player ${slot} bootstrap user`).toBeTruthy();
  return payload;
}

async function readPlayerSnapshot(page) {
  await page.waitForFunction(() => (
    typeof localStorage.getItem('mgw_device_session_id') === 'string'
    && localStorage.getItem('mgw_device_session_id').length > 0
    && typeof localStorage.getItem('mgw_device_id') === 'string'
    && localStorage.getItem('mgw_device_id').length > 0
  ), null, { timeout: 20_000 });

  return page.evaluate(async () => {
    const sessionId = localStorage.getItem('mgw_device_session_id');
    const deviceId = localStorage.getItem('mgw_device_id');
    const response = await fetch('/bot/api.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        action: 'profile',
        initData: '',
        sessionId,
        deviceId,
      }),
    });
    const payload = await response.json().catch(() => null);
    return {
      status: response.status,
      ok: payload?.ok === true,
      error: typeof payload?.error === 'string' ? payload.error.slice(0, 300) : null,
      user: payload?.user || null,
      session: payload?.session || null,
      sessionId,
      deviceId,
      localStorageKeys: Object.keys(localStorage).sort(),
      sessionStorageKeys: Object.keys(sessionStorage).sort(),
    };
  });
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

async function openPlayer(browser, slot, testInfo) {
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
  const bootstrapPromise = waitForApplicationBootstrap(page, slot);

  const response = await page.goto(APP_ROUTE, { waitUntil: 'domcontentloaded' });
  expect(response, `Player ${slot} app response`).not.toBeNull();
  expect(response.ok(), `Player ${slot} app status`).toBe(true);
  await expect(page).toHaveTitle(/Mini Games World/i);
  await expect(page.locator('body')).toBeVisible();
  const bootstrap = await bootstrapPromise;

  const snapshot = await readPlayerSnapshot(page);
  expect(
    snapshot.status,
    `Player ${slot} profile status; public error: ${snapshot.error || 'no_public_error'}`,
  ).toBe(200);
  expect(snapshot.ok).toBe(true);
  expect(snapshot.user).toBeTruthy();
  expect(snapshot.session?.locked ?? false).toBe(false);

  await page.screenshot({
    path: testInfo.outputPath(`player-${slot.toLowerCase()}-home.png`),
    fullPage: true,
  });

  return { context, page, cookie, diagnostics, snapshot, bootstrap };
}

async function revokeContext(context) {
  try {
    await context.request.post(AUTH_ROUTE, {
      data: { action: 'revoke' },
      timeout: 15_000,
    });
  } catch {
    // The context is still closed below; the 15-minute server TTL is the fallback.
  }
}

async function cleanupPlayer(player) {
  if (!player?.page) return;
  const { page } = player;

  try {
    const gameState = await postFromPlayer(page, '/bot/api.php', { action: 'game_state' });
    const game = gameState.payload?.game || null;
    if (gameState.status === 200 && game?.id && game.status === 'active') {
      await postFromPlayer(page, '/bot/api.php', { action: 'leave_game', gameId: game.id });
    } else if (gameState.status === 200 && gameState.payload?.user?.status === 'searching') {
      await postFromPlayer(page, '/bot/api.php', { action: 'leave_search' });
    }
  } catch {
    // Best-effort cleanup continues with invitations below.
  }

  try {
    const sync = await postFromPlayer(page, '/bot/invites.php', { action: 'sync', token: '' });
    const invite = sync.payload?.invite || sync.payload?.tracked_invite || null;
    if (sync.status === 200
      && invite?.token
      && ['pending', 'accepted', 'awaiting_start'].includes(String(invite.status || ''))) {
      await postFromPlayer(page, '/bot/invites.php', { action: 'cancel', token: invite.token });
    }
  } catch {
    // A later session TTL and server expiry remain the final fallback.
  }

  await page.keyboard.press('Escape').catch(() => null);
}

async function openNotificationsAndWaitForAction(page, token, action) {
  const button = page.locator('#notificationsOpen');
  await expect(button).toBeVisible({ timeout: 20_000 });
  await button.click();
  await expect(page.locator('#sheet .sheet-head h2')).toHaveText('Уведомления', { timeout: 20_000 });

  const selector = `[data-invite-action="${action}"][data-invite-token="${token}"]`;
  const actionButton = page.locator(selector);
  await expect(actionButton).toBeVisible({ timeout: 25_000 });
  await expect(actionButton).toBeEnabled();
  return actionButton;
}

async function clickInviteAction(page, button, action) {
  const responsePromise = page.waitForResponse(isActionResponse(INVITES_ROUTE, action), {
    timeout: 30_000,
  });
  await button.click();
  const response = await responsePromise;
  const payload = await response.json().catch(() => null);
  const publicError = typeof payload?.error === 'string' ? payload.error.slice(0, 300) : 'no_public_error';
  expect(response.status(), `${action} invitation status; public error: ${publicError}`).toBe(200);
  expect(payload?.ok, `${action} invitation payload`).toBe(true);
  return payload;
}

async function playTicTacToeCell(player, cell) {
  const locator = player.page.locator(`#screen-game.active [data-game-cell="${cell}"]`);
  await expect(locator).toBeVisible({ timeout: 25_000 });
  await expect(locator).toBeEnabled({ timeout: 25_000 });

  const responsePromise = player.page.waitForResponse(isActionResponse(API_ROUTE, 'game_action'), {
    timeout: 30_000,
  });
  await locator.click();
  const response = await responsePromise;
  const payload = await response.json().catch(() => null);
  const publicError = typeof payload?.error === 'string' ? payload.error.slice(0, 300) : 'no_public_error';
  expect(response.status(), `Tic Tac Toe cell ${cell}; public error: ${publicError}`).toBe(200);
  expect(payload?.ok, `Tic Tac Toe cell ${cell} payload`).toBe(true);
  expect(payload?.game, `Tic Tac Toe cell ${cell} game`).toBeTruthy();
  return payload;
}

test('TEST PLAYER A and B run in isolated browser contexts', async ({ browser }, testInfo) => {
  let playerA;
  let playerB;
  let replayContext;

  try {
    playerA = await openPlayer(browser, 'A', testInfo);
    playerB = await openPlayer(browser, 'B', testInfo);

    expect(playerA.snapshot.user.id).toBe('stg_test_player_a');
    expect(playerB.snapshot.user.id).toBe('stg_test_player_b');
    expect(playerA.snapshot.user.id).not.toBe(playerB.snapshot.user.id);
    expect(playerA.snapshot.user.username).toBe('mgw_test_player_a');
    expect(playerB.snapshot.user.username).toBe('mgw_test_player_b');

    expect(playerA.snapshot.sessionId).not.toBe(playerB.snapshot.sessionId);
    expect(playerA.snapshot.deviceId).not.toBe(playerB.snapshot.deviceId);
    expect(playerA.cookie.value).not.toBe(playerB.cookie.value);

    expect(playerA.diagnostics.pageErrors).toEqual([]);
    expect(playerB.diagnostics.pageErrors).toEqual([]);
    expect(playerA.diagnostics.failedRequests).toEqual([]);
    expect(playerB.diagnostics.failedRequests).toEqual([]);
    expect(playerA.diagnostics.serverErrors).toEqual([]);
    expect(playerB.diagnostics.serverErrors).toEqual([]);

    replayContext = await browser.newContext();
    await replayContext.addCookies([playerA.cookie]);
    const replay = await replayContext.request.post(API_ROUTE, {
      data: {
        action: 'profile',
        initData: '',
        sessionId: 'sess_replay_context',
        deviceId: 'device_replay_context',
      },
      timeout: 20_000,
    });
    expect(replay.status()).toBeGreaterThanOrEqual(400);
    const replayPayload = await replay.json();
    expect(replayPayload?.ok).toBe(false);

    const safeReport = {
      ok: true,
      stagingOrigin: STAGING_ORIGIN,
      players: {
        A: {
          id: playerA.snapshot.user.id,
          sessionIdSha256: sha256(playerA.snapshot.sessionId),
          deviceIdSha256: sha256(playerA.snapshot.deviceId),
          localStorageKeys: playerA.snapshot.localStorageKeys,
          sessionStorageKeys: playerA.snapshot.sessionStorageKeys,
        },
        B: {
          id: playerB.snapshot.user.id,
          sessionIdSha256: sha256(playerB.snapshot.sessionId),
          deviceIdSha256: sha256(playerB.snapshot.deviceId),
          localStorageKeys: playerB.snapshot.localStorageKeys,
          sessionStorageKeys: playerB.snapshot.sessionStorageKeys,
        },
      },
      isolation: {
        accountsDistinct: true,
        cookiesDistinct: true,
        sessionsDistinct: true,
        devicesDistinct: true,
        copiedCookieReplayRejected: true,
      },
      diagnostics: {
        A: playerA.diagnostics,
        B: playerB.diagnostics,
      },
      productionChanged: false,
      livePaymentsUsed: false,
    };

    await testInfo.attach('staging-two-context-report', {
      body: Buffer.from(`${JSON.stringify(safeReport, null, 2)}\n`, 'utf8'),
      contentType: 'application/json',
    });
  } finally {
    if (replayContext) await replayContext.close();
    if (playerA?.context) {
      await revokeContext(playerA.context);
      await playerA.context.close();
    }
    if (playerB?.context) {
      await revokeContext(playerB.context);
      await playerB.context.close();
    }
  }
});

test('A invites B through notifications and they finish a Tic Tac Toe match', async ({ browser }, testInfo) => {
  let playerA;
  let playerB;
  let inviteToken = '';
  let gameId = '';

  try {
    playerA = await openPlayer(browser, 'A', testInfo);
    playerB = await openPlayer(browser, 'B', testInfo);
    await cleanupPlayer(playerA);
    await cleanupPlayer(playerB);

    const beforeA = await expectPlayerRequest(
      playerA.page,
      '/bot/api.php',
      { action: 'profile' },
      'Player A pre-match profile',
    );
    const beforeB = await expectPlayerRequest(
      playerB.page,
      '/bot/api.php',
      { action: 'profile' },
      'Player B pre-match profile',
    );

    const created = await expectPlayerRequest(
      playerA.page,
      '/bot/invites.php',
      {
        action: 'create_direct',
        inviteeId: 'stg_test_player_b',
        gameType: 'tictactoe',
        room: 'match',
        bet: 10,
        boardSize: 3,
      },
      'Player A direct invitation',
    );
    inviteToken = String(created.invite?.token || '');
    expect(inviteToken).toMatch(/^[A-Za-z0-9_-]{12,80}$/);
    expect(created.invite?.status).toBe('pending');
    expect(created.invite?.inviter_id).toBe('stg_test_player_a');
    expect(created.invite?.invitee_id).toBe('stg_test_player_b');

    const acceptButton = await openNotificationsAndWaitForAction(
      playerB.page,
      inviteToken,
      'accept',
    );
    const accepted = await clickInviteAction(playerB.page, acceptButton, 'accept');
    expect(['accepted', 'awaiting_start']).toContain(String(accepted.invite?.status || ''));
    await expect(playerB.page.locator('#sheet .sheet-head h2')).toHaveText('Приглашение принято', {
      timeout: 20_000,
    });

    await playerB.page.screenshot({
      path: testInfo.outputPath('player-b-invitation-accepted.png'),
      fullPage: true,
    });

    const startButton = await openNotificationsAndWaitForAction(
      playerA.page,
      inviteToken,
      'start',
    );
    const started = await clickInviteAction(playerA.page, startButton, 'start');
    gameId = String(started.game?.id || '');
    expect(gameId).toMatch(/^[A-Za-z0-9_-]{8,120}$/);
    expect(started.game?.status).toBe('active');
    expect(started.game?.game_type).toBe('tictactoe');
    expect(Number(started.game?.bet || 0)).toBe(10);

    await expect(playerA.page.locator('#screen-game')).toHaveClass(/active/, { timeout: 20_000 });
    await expect(playerB.page.locator('#screen-game')).toHaveClass(/active/, { timeout: 30_000 });
    await expect(playerA.page.locator('#screen-game [data-game-cell]')).toHaveCount(9);
    await expect(playerB.page.locator('#screen-game [data-game-cell]')).toHaveCount(9);

    const playersById = {
      stg_test_player_a: playerA,
      stg_test_player_b: playerB,
    };
    const winningSequence = [0, 3, 1, 4, 2];
    let finalPayload = null;

    for (const cell of winningSequence) {
      const statePayload = await expectPlayerRequest(
        playerA.page,
        '/bot/api.php',
        { action: 'game_state', gameId },
        `Game state before cell ${cell}`,
      );
      const turnId = String(statePayload.game?.turn || '');
      const actor = playersById[turnId];
      expect(actor, `Known player owns turn before cell ${cell}`).toBeTruthy();
      finalPayload = await playTicTacToeCell(actor, cell);
    }

    expect(finalPayload?.game?.status).toBe('finished');
    expect(finalPayload?.game?.winner_id).toBeTruthy();
    expect(Number(finalPayload?.game?.payout || 0)).toBe(20);

    const winnerId = String(finalPayload.game.winner_id);
    const winner = playersById[winnerId];
    const loser = winnerId === 'stg_test_player_a' ? playerB : playerA;
    expect(winner).toBeTruthy();

    await expect(winner.page.locator('#sheet .sheet-head h2')).toHaveText('Победа!', {
      timeout: 30_000,
    });
    await expect(loser.page.locator('#sheet .sheet-head h2')).toHaveText('Поражение', {
      timeout: 30_000,
    });

    const afterA = await expectPlayerRequest(
      playerA.page,
      '/bot/api.php',
      { action: 'profile' },
      'Player A post-match profile',
    );
    const afterB = await expectPlayerRequest(
      playerB.page,
      '/bot/api.php',
      { action: 'profile' },
      'Player B post-match profile',
    );

    const beforeBalances = {
      stg_test_player_a: Number(beforeA.user?.balance_match || 0),
      stg_test_player_b: Number(beforeB.user?.balance_match || 0),
    };
    const afterBalances = {
      stg_test_player_a: Number(afterA.user?.balance_match || 0),
      stg_test_player_b: Number(afterB.user?.balance_match || 0),
    };
    const loserId = winnerId === 'stg_test_player_a' ? 'stg_test_player_b' : 'stg_test_player_a';

    expect(afterBalances[winnerId] - beforeBalances[winnerId]).toBe(10);
    expect(afterBalances[loserId] - beforeBalances[loserId]).toBe(-10);
    expect(afterBalances.stg_test_player_a + afterBalances.stg_test_player_b)
      .toBe(beforeBalances.stg_test_player_a + beforeBalances.stg_test_player_b);

    await playerA.page.screenshot({
      path: testInfo.outputPath('player-a-match-result.png'),
      fullPage: true,
    });
    await playerB.page.screenshot({
      path: testInfo.outputPath('player-b-match-result.png'),
      fullPage: true,
    });

    expect(playerA.diagnostics.pageErrors).toEqual([]);
    expect(playerB.diagnostics.pageErrors).toEqual([]);
    expect(playerA.diagnostics.failedRequests).toEqual([]);
    expect(playerB.diagnostics.failedRequests).toEqual([]);
    expect(playerA.diagnostics.serverErrors).toEqual([]);
    expect(playerB.diagnostics.serverErrors).toEqual([]);

    const safeReport = {
      ok: true,
      scenario: 'direct_invite_notification_tictactoe_match',
      invitation: {
        tokenSha256: sha256(inviteToken),
        receivedThroughNotificationsUi: true,
        acceptedThroughUi: true,
        startedThroughUi: true,
      },
      game: {
        idSha256: sha256(gameId),
        type: 'tictactoe',
        room: 'match',
        bet: 10,
        boardSize: 3,
        winnerId,
        completedThroughUi: true,
      },
      economy: {
        winnerDelta: 10,
        loserDelta: -10,
        totalPreserved: true,
      },
      diagnostics: {
        A: playerA.diagnostics,
        B: playerB.diagnostics,
      },
      productionChanged: false,
      livePaymentsUsed: false,
    };
    await testInfo.attach('staging-invite-tictactoe-report', {
      body: Buffer.from(`${JSON.stringify(safeReport, null, 2)}\n`, 'utf8'),
      contentType: 'application/json',
    });
  } finally {
    if (playerA) await cleanupPlayer(playerA);
    if (playerB) await cleanupPlayer(playerB);
    if (playerA?.context) {
      await revokeContext(playerA.context);
      await playerA.context.close();
    }
    if (playerB?.context) {
      await revokeContext(playerB.context);
      await playerB.context.close();
    }
  }
});
