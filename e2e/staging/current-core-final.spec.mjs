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
    if (path === '/bot/presence.php') value.presenceStatuses.push(response.status());
    if (response.status() >= 500) value.serverErrors.push({ path, status: response.status() });
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
  const transport = await player.page.evaluate(() => ({
    sessionId: localStorage.getItem('mgw_device_session_id'),
    deviceId: localStorage.getItem('mgw_device_id'),
  }));
  const response = await player.context.request.post(`${ORIGIN}${path}`, {
    headers: { Accept: 'application/json' },
    data: { ...data, initData: '', ...transport },
    timeout: 15_000,
  });
  return { status: response.status(), payload: await response.json().catch(() => null) };
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
  expect(response.status(), `${label} observed status`).toBe(200);
  const payload = await response.json().catch(() => null);
  expect(payload?.ok, label).toBe(true);
  return payload;
}

async function readAction(page, path, data, label) {
  const result = await browserPost(page, path, data);
  expect(result.status, `${label}: ${result.payload?.error || 'no error'}`).toBe(200);
  expect(result.payload?.ok, label).toBe(true);
  return result.payload;
}

async function openPlayer(browser, slot) {
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
  expect(entry?.ok(), `Player ${slot} Telegram entry`).toBe(true);
  expect(entry.headers()['x-mgw-client-bootstrap']).toBe('v2-single-owner');
  expect(entry.headers()['x-mgw-game-zone']).toBe('unified-v1');
  const bootstrapResponse = await bootstrapPromise;
  const bootstrap = await bootstrapResponse.json();
  expect(bootstrapResponse.status()).toBe(200);
  expect(bootstrap?.ok).toBe(true);
  expect(bootstrap?.user?.id).toBe(`stg_test_player_${slot.toLowerCase()}`);
  expect(Number(bootstrap?.match_economy?.entry_cost || 0)).toBeGreaterThan(0);
  await page.waitForFunction(() => window.__MGW_APP_BOOTSTRAP_V2__?.ready === true, null, { timeout: 20_000 });
  await expect(page.locator('#screen-home')).toHaveClass(/active/, { timeout: 25_000 });
  await page.waitForFunction(() => Boolean(
    localStorage.getItem('mgw_device_session_id') && localStorage.getItem('mgw_device_id')
  ), null, { timeout: 20_000 });
  const profile = await readAction(page, '/bot/api.php', { action: 'profile' }, `Player ${slot} profile`);
  expect(profile?.user?.id).toBe(`stg_test_player_${slot.toLowerCase()}`);
  return { context, page, cookie, report, bootstrap, profile };
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
  expect(player.report.pageErrors, `${player.report.slot} page errors`).toEqual([]);
  expect(player.report.serverErrors, `${player.report.slot} server 5xx`).toEqual([]);
  for (const status of player.report.presenceStatuses) expect(status).toBeLessThan(500);
}

test('STORE SMOKE: avatar decorator cannot freeze bottom navigation', async ({ browser }) => {
  let A;
  try {
    A = await openPlayer(browser, 'A');
    const storeNav = A.page.locator('[data-shell-nav="store"]');
    const homeNav = A.page.locator('[data-shell-nav="home"]');

    await expect(storeNav).toBeVisible();
    await storeNav.click({ timeout:5_000 });
    await expect(A.page.locator('#screen-store')).toHaveClass(/active/, { timeout:10_000 });
    await expect(A.page.locator('#storeTabSurface .store-v2-shell:not(.is-pending)')).toBeVisible({ timeout:20_000 });

    const injected = await A.page.evaluate(() => {
      const panel = document.querySelector('#storeTabSurface .store-v2-content[data-store-v2-panel="profile"]');
      const avatarGrid = panel?.querySelector(':scope > .store-v2-product-grid');
      if (!(panel instanceof HTMLElement) || !(avatarGrid instanceof HTMLElement)) return false;

      const avatarCard = document.createElement('article');
      avatarCard.id = 'stagingOwnedAvatarObserverProbe';
      avatarCard.className = 'store-v2-product owned';
      avatarCard.innerHTML = `
        <div class="store-v2-avatar-preview" data-avatar-item-id="store-avatar-01" data-avatar-preview="1"><span>01</span></div>
        <strong class="store-v2-product-name">Avatar probe</strong>
        <div class="store-v2-product-foot"><b>Куплено</b></div>
      `;
      avatarGrid.append(avatarCard);

      const frameLikeSection = document.createElement('section');
      frameLikeSection.id = 'stagingFrameAvatarCollisionProbe';
      frameLikeSection.innerHTML = `
        <article class="store-v2-product owned">
          <span class="store-v2-avatar-preview" data-avatar-item-id="starter-default-01" data-profile-frame-avatar-item-id="profile-frame-01"></span>
          <strong class="store-v2-product-name">Frame collision probe</strong>
          <div class="store-v2-product-foot"><button type="button" data-profile-frame-equip="profile-frame-01">Выбрать</button></div>
        </article>
      `;
      panel.append(frameLikeSection);
      return true;
    });
    expect(injected).toBe(true);

    const action = A.page.locator('#stagingOwnedAvatarObserverProbe [data-mgw-store-avatar-select="store-avatar-01"]');
    await expect(action).toBeVisible({ timeout:5_000 });
    await expect(action).toHaveText(/^(Выбрать|Снять)$/);
    await expect(A.page.locator('#stagingOwnedAvatarObserverProbe .store-v2-product-foot > b')).toHaveText(/^(В\s+коллекции|Выбрано)$/u);

    // Frame previews deliberately reuse starter-default-01 as artwork. They must
    // remain outside the avatar action owner or the duplicate frame button bug
    // returns immediately.
    await A.page.waitForTimeout(350);
    await expect(A.page.locator('#stagingFrameAvatarCollisionProbe [data-mgw-store-avatar-select]')).toHaveCount(0);
    await expect(A.page.locator('#stagingFrameAvatarCollisionProbe button')).toHaveCount(1);

    // The historical regression endlessly rewrote button textContent from the
    // observer callback. If that feedback loop returns, the browser main thread
    // cannot process the following navigation click within this short timeout.
    await homeNav.click({ timeout:3_000 });
    await expect(A.page.locator('#screen-home')).toHaveClass(/active/, { timeout:3_000 });

    await storeNav.click({ timeout:3_000 });
    await expect(A.page.locator('#screen-store')).toHaveClass(/active/, { timeout:3_000 });
    await homeNav.click({ timeout:3_000 });
    await expect(A.page.locator('#screen-home')).toHaveClass(/active/, { timeout:3_000 });
    assertDiagnostics(A);
  } finally {
    if (A?.context) await A.context.close().catch(() => null);
    await resetPlayers();
  }
});

test('CURRENT FINAL CORE: canonical Telegram v110 two-player TTT lifecycle', async ({ browser }) => {
  let A;
  let B;
  try {
    A = await openPlayer(browser, 'A');
    B = await openPlayer(browser, 'B');
    expect(ENTRY_PATH).toMatch(/^\/app\/v110\.php\?v=\d+$/);
    expect(A.cookie.value).not.toBe(B.cookie.value);

    const entryCost = Number(A.bootstrap.match_economy.entry_cost);
    expect(Number(B.bootstrap.match_economy.entry_cost)).toBe(entryCost);
    const before = {
      stg_test_player_a: Number(A.profile.user.balance),
      stg_test_player_b: Number(B.profile.user.balance),
    };

    const created = await observedAction(A.page, '/bot/invites.php', {
      action: 'create_direct', inviteeId: 'stg_test_player_b', gameType: 'tictactoe', boardSize: 3,
    }, 'create_direct', 'create direct invite');
    const token = String(created.invite?.token || '');
    expect(token).toMatch(/^[a-f0-9]{24}$/);
    expect(created.invite?.status).toBe('pending');
    expect(Number(created.invite?.bet || 0)).toBe(entryCost);

    await waitInviteEvent(B, token);
    const accepted = await observedAction(B.page, '/bot/invites.php', { action: 'accept', token }, 'accept', 'accept invite');
    expect(accepted.invite?.status).toBe('accepted');
    expect(accepted.invite?.can_start).toBe(false);

    const started = await observedAction(A.page, '/bot/invites.php', { action: 'start', token }, 'start', 'start invite');
    const gameId = String(started.game?.id || started.invite?.game_id || '');
    expect(gameId).toMatch(/^[A-Za-z0-9_-]{8,120}$/);
    expect(started.invite?.status).toBe('active');
    expect(started.game?.status).toBe('active');
    expect(Number(started.game?.bet || 0)).toBe(entryCost);

    await Promise.all([reload(A), reload(B)]);
    await expect(A.page.locator('#screen-game')).toHaveClass(/active/, { timeout:25_000 });
    await expect(B.page.locator('#screen-game')).toHaveClass(/active/, { timeout:25_000 });
    await expect(A.page.locator('#screen-game [data-game-cell]')).toHaveCount(9);
    await expect(B.page.locator('#screen-game [data-game-cell]')).toHaveCount(9);

    const byId = { stg_test_player_a: A, stg_test_player_b: B };
    let game = await waitLaunch(A, gameId);
    const firstActor = byId[String(game.turn || '')];
    expect(firstActor).toBeTruthy();
    game = await firstUiTap(firstActor, gameId, 0);
    for (const cell of [3, 1, 4, 2]) {
      const actor = byId[String(game.turn || '')];
      expect(actor).toBeTruthy();
      game = await directCell(actor, gameId, cell);
    }

    expect(game?.status).toBe('finished');
    const winnerId = String(game.winner_id || '');
    expect(byId[winnerId]).toBeTruthy();
    const loserId = winnerId === 'stg_test_player_a' ? 'stg_test_player_b' : 'stg_test_player_a';

    await Promise.all([
      expect(A.page.locator('#resultSummary')).toBeVisible({ timeout:30_000 }),
      expect(B.page.locator('#resultSummary')).toBeVisible({ timeout:30_000 }),
    ]);
    for (const player of [A, B]) {
      await expect(player.page.locator('#resultSummary')).toContainText('Баланс:');
      await expect(player.page.locator('#resultSummary')).not.toContainText('Вход:');
      await expect(player.page.locator('#resultSummary')).not.toContainText('Награда:');
    }

    const afterA = await readAction(A.page, '/bot/api.php', { action: 'profile' }, 'A final profile');
    const afterB = await readAction(B.page, '/bot/api.php', { action: 'profile' }, 'B final profile');
    const after = {
      stg_test_player_a: Number(afterA.user?.balance),
      stg_test_player_b: Number(afterB.user?.balance),
    };
    const winnerDelta = after[winnerId] - before[winnerId];
    const loserDelta = after[loserId] - before[loserId];
    expect(winnerDelta).toBe(Number(game.payout || 0) - entryCost);
    expect(loserDelta).toBe(-entryCost);
    expect(winnerDelta + loserDelta + Number(game.commission || 0)).toBe(0);

    let winnerHistory;
    let loserHistory;
    await expect.poll(async () => {
      winnerHistory = await historyItem(byId[winnerId], gameId);
      loserHistory = await historyItem(byId[loserId], gameId);
      return winnerHistory?.economy && loserHistory?.economy
        ? `${winnerHistory.economy.ledger_delta}:${loserHistory.economy.ledger_delta}`
        : 'missing';
    }, { timeout:15_000, intervals:[100, 200, 400, 800] }).toBe(`${winnerDelta}:${loserDelta}`);
    expect(Number(winnerHistory.economy.new_balance)).toBe(after[winnerId]);
    expect(Number(loserHistory.economy.new_balance)).toBe(after[loserId]);

    await A.page.waitForTimeout(2_000);
    assertDiagnostics(A);
    assertDiagnostics(B);
  } finally {
    for (const player of [A, B]) {
      if (player?.context) await player.context.close().catch(() => null);
    }
    await resetPlayers();
  }
});
