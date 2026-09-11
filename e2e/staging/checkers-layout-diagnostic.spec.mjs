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
const ENTRY_URL = `${ORIGIN}${entryMatch[1]}`;

async function requestOidcToken() {
  const source = process.env.ACTIONS_ID_TOKEN_REQUEST_URL || '';
  const bearer = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN || '';
  if (!source || !bearer) throw new Error('GitHub Actions OIDC environment is unavailable.');
  const url = new URL(source);
  url.searchParams.set('audience', OIDC_AUDIENCE);
  const response = await fetch(url, { headers: { Authorization: `bearer ${bearer}`, Accept: 'application/json' } });
  if (!response.ok) throw new Error(`OIDC request failed: ${response.status}`);
  const payload = await response.json();
  if (typeof payload?.value !== 'string') throw new Error('OIDC JWT is unavailable.');
  return payload.value;
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
}

function requestAction(request) {
  try { return String(request.postDataJSON()?.action || ''); } catch { return ''; }
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

async function openPlayer(browser, slot) {
  const context = await browser.newContext({
    locale: 'ru-RU',
    timezoneId: 'Europe/Vilnius',
    viewport: { width: 390, height: 690 },
    isMobile: true,
    hasTouch: true,
  });
  await authorize(context, slot);
  const page = await context.newPage();
  const bootstrapPromise = page.waitForResponse(response => (
    response.url() === `${ORIGIN}/bot/api.php`
    && response.request().method() === 'POST'
    && requestAction(response.request()) === 'bootstrap'
  ), { timeout: 35_000 });
  const entry = await page.goto(ENTRY_URL, { waitUntil: 'domcontentloaded' });
  expect(entry?.ok(), `Player ${slot} entry`).toBe(true);
  expect(entry.headers()['x-mgw-client-bootstrap']).toBe('v2-single-owner');
  const bootstrap = await bootstrapPromise;
  expect(bootstrap.status()).toBe(200);
  await page.waitForFunction(() => window.__MGW_APP_BOOTSTRAP_V2__?.ready === true, null, { timeout: 20_000 });
  await expect(page.locator('#screen-home')).toHaveClass(/active/, { timeout: 25_000 });
  await page.waitForFunction(() => Boolean(
    localStorage.getItem('mgw_device_session_id') && localStorage.getItem('mgw_device_id')
  ), null, { timeout: 20_000 });
  return { context, page };
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

async function waitLaunch(page, gameId) {
  await expect.poll(async () => {
    const result = await browserPost(page, '/bot/api.php', { action: 'game_state', gameId });
    const game = result.payload?.game || {};
    return result.status === 200 && result.payload?.ok === true
      && game.status === 'active'
      && game.launch_phase === 'active';
  }, { timeout: 20_000, intervals: [100, 200, 400, 800] }).toBe(true);
}

async function captureLayout(page) {
  return page.evaluate(() => {
    const round = value => Math.round(Number(value) * 10) / 10;
    const rect = element => {
      if (!(element instanceof Element)) return null;
      const value = element.getBoundingClientRect();
      return {
        x: round(value.x), y: round(value.y), width: round(value.width), height: round(value.height),
        top: round(value.top), right: round(value.right), bottom: round(value.bottom), left: round(value.left),
      };
    };
    const style = element => {
      if (!(element instanceof Element)) return null;
      const css = getComputedStyle(element);
      return {
        display: css.display,
        visibility: css.visibility,
        opacity: css.opacity,
        position: css.position,
        width: css.width,
        maxWidth: css.maxWidth,
        height: css.height,
        maxHeight: css.maxHeight,
        overflow: css.overflow,
        overflowY: css.overflowY,
        marginTop: css.marginTop,
        marginBottom: css.marginBottom,
        paddingTop: css.paddingTop,
        paddingBottom: css.paddingBottom,
      };
    };

    const screen = document.querySelector('#screen-game.active');
    const content = screen?.querySelector('.content');
    const wrap = screen?.querySelector('.board-wrap');
    const board = document.getElementById('gameBoard');
    const checkersBoard = screen?.querySelector('.checkers-board');
    const legend = screen?.querySelector('.checkers-legend');
    const leave = document.getElementById('leaveGame');
    const vv = window.visualViewport;

    return {
      viewport: {
        innerWidth: window.innerWidth,
        innerHeight: window.innerHeight,
        visualWidth: round(vv?.width ?? window.innerWidth),
        visualHeight: round(vv?.height ?? window.innerHeight),
      },
      screen: { rect: rect(screen), style: style(screen), gameType: screen?.dataset?.gameType || '' },
      content: {
        rect: rect(content), style: style(content),
        clientHeight: content?.clientHeight ?? null,
        scrollHeight: content?.scrollHeight ?? null,
        scrollTop: content?.scrollTop ?? null,
        maxScroll: content ? content.scrollHeight - content.clientHeight : null,
      },
      wrap: { rect: rect(wrap), style: style(wrap) },
      gameBoard: { rect: rect(board), style: style(board), className: board?.className || '' },
      checkersBoard: { rect: rect(checkersBoard), style: style(checkersBoard) },
      legend: { rect: rect(legend), style: style(legend) },
      leave: {
        exists: Boolean(leave),
        connected: Boolean(leave?.isConnected),
        rect: rect(leave),
        style: style(leave),
        offsetParent: leave?.offsetParent ? `${leave.offsetParent.tagName}.${leave.offsetParent.className}` : null,
      },
    };
  });
}

test('CHECKERS LAYOUT DIAGNOSTIC — live v110 mobile geometry', async ({ browser }) => {
  let A;
  let B;
  try {
    A = await openPlayer(browser, 'A');
    B = await openPlayer(browser, 'B');

    const created = await observedAction(A.page, '/bot/invites.php', {
      action: 'create_direct',
      inviteeId: 'stg_test_player_b',
      gameType: 'checkers',
      boardSize: 8,
    }, 'create_direct', 'create Checkers direct invite');
    const token = String(created.invite?.token || '');
    expect(token).toMatch(/^[a-f0-9]{24}$/);

    const accepted = await observedAction(B.page, '/bot/invites.php', { action: 'accept', token }, 'accept', 'accept Checkers invite');
    expect(accepted.invite?.status).toBe('accepted');

    const started = await observedAction(A.page, '/bot/invites.php', { action: 'start', token }, 'start', 'start Checkers invite');
    const gameId = String(started.game?.id || started.invite?.game_id || '');
    expect(gameId).toMatch(/^[A-Za-z0-9_-]{8,120}$/);

    await Promise.all([reload(A), reload(B)]);
    await expect(A.page.locator('#screen-game')).toHaveClass(/active/, { timeout: 25_000 });
    await waitLaunch(A.page, gameId);
    await expect(A.page.locator('#screen-game.active .checkers-board')).toBeVisible({ timeout: 20_000 });

    const diagnostic = await captureLayout(A.page);
    console.log(`CHECKERS_LAYOUT_DIAGNOSTIC=${JSON.stringify(diagnostic)}`);

    expect(diagnostic.screen.gameType).toBe('checkers');
    expect(diagnostic.leave.exists).toBe(true);
  } finally {
    await Promise.allSettled([A?.context?.close(), B?.context?.close()]);
  }
});
