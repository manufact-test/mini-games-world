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
  const response = await fetch(url, {
    headers: { Authorization: `bearer ${bearer}`, Accept: 'application/json' },
  });
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
  expect(payload?.player_slot).toBe(slot);
}

function requestAction(request) {
  try { return String(request.postDataJSON()?.action || ''); } catch { return ''; }
}

function requestKey(item) {
  return `${item.method} ${item.path}${item.action ? `#${item.action}` : ''}`;
}

async function twoFrames(page) {
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}

async function measureSurface(page, nav, screenSelector) {
  const requestIndex = page.__mgwRequestTimings.length;
  const startedAt = Date.now();
  const navNode = page.locator(`[data-shell-nav="${nav}"]`).first();
  await expect(navNode).toBeVisible({ timeout: 10_000 });
  await navNode.click();
  await expect(page.locator(screenSelector)).toHaveClass(/active/, { timeout: 15_000 });
  await twoFrames(page);
  const activeAt = Date.now();
  const metrics = await page.evaluate(selector => {
    const screen = document.querySelector(selector);
    return {
      dom_nodes: document.getElementsByTagName('*').length,
      screen_nodes: screen ? screen.getElementsByTagName('*').length : 0,
      scroll_height: screen instanceof HTMLElement ? screen.scrollHeight : 0,
      client_height: screen instanceof HTMLElement ? screen.clientHeight : 0,
    };
  }, screenSelector);
  return {
    nav,
    first_active_ms: activeAt - startedAt,
    ...metrics,
    requests_started: page.__mgwRequestTimings.slice(requestIndex).map(item => ({
      key: requestKey(item),
      status: item.status,
      duration_ms: item.duration_ms,
    })),
  };
}

test('MVP-25.4 staging performance baseline', async ({ browser }) => {
  const setupStartedAt = Date.now();
  const context = await browser.newContext({
    locale: 'ru-RU',
    timezoneId: 'Europe/Vilnius',
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
  });
  await authorize(context, 'A');
  const page = await context.newPage();
  const testSetupReadyAt = Date.now();

  const requestStartedAt = new WeakMap();
  const requestTimings = [];
  page.__mgwRequestTimings = requestTimings;
  page.on('request', request => {
    if (!request.url().startsWith(ORIGIN)) return;
    requestStartedAt.set(request, Date.now());
  });
  page.on('response', response => {
    const request = response.request();
    if (!request.url().startsWith(ORIGIN)) return;
    const startedAt = requestStartedAt.get(request);
    const endedAt = Date.now();
    let path = '';
    try { path = new URL(response.url()).pathname; } catch {}
    requestTimings.push({
      method: request.method(),
      path,
      action: requestAction(request),
      status: response.status(),
      start_ms: Number.isFinite(startedAt) ? startedAt : null,
      duration_ms: Number.isFinite(startedAt) ? endedAt - startedAt : null,
    });
  });

  const bootstrapPromise = page.waitForResponse(response => (
    response.url() === `${ORIGIN}/bot/api.php`
    && response.request().method() === 'POST'
    && requestAction(response.request()) === 'bootstrap'
  ), { timeout: 35_000 });

  const navigationStartedAt = Date.now();
  const entry = await page.goto(ENTRY_URL, { waitUntil: 'domcontentloaded' });
  const domContentLoadedAt = Date.now();
  expect(entry?.ok()).toBe(true);
  expect(entry.headers()['x-mgw-client-bootstrap']).toBe('v2-single-owner');

  const bootstrapResponse = await bootstrapPromise;
  const bootstrapResponseAt = Date.now();
  expect(bootstrapResponse.status()).toBe(200);
  const bootstrap = await bootstrapResponse.json();
  expect(bootstrap?.ok).toBe(true);

  await page.waitForFunction(() => window.__MGW_APP_BOOTSTRAP_V2__?.ready === true, null, { timeout: 25_000 });
  const appReadyAt = Date.now();
  await expect(page.locator('#screen-home')).toHaveClass(/active/, { timeout: 25_000 });
  await page.waitForFunction(() => document.getElementById('preloader')?.classList.contains('hidden') === true, null, { timeout: 25_000 });
  await twoFrames(page);
  const firstUsableAt = Date.now();

  const resources = await page.evaluate(() => performance.getEntriesByType('resource')
    .filter(entry => entry && typeof entry.name === 'string')
    .map(entry => {
      let path = entry.name;
      try { path = new URL(entry.name).pathname; } catch {}
      return {
        path,
        initiator_type: String(entry.initiatorType || ''),
        start_ms: Math.round(entry.startTime),
        duration_ms: Math.round(entry.duration),
        transfer_size: Number(entry.transferSize || 0),
      };
    })
    .sort((a, b) => b.duration_ms - a.duration_ms)
    .slice(0, 30));

  const startupRequests = requestTimings
    .filter(item => item.start_ms !== null && item.start_ms <= firstUsableAt)
    .map(item => ({
      key: requestKey(item),
      status: item.status,
      start_from_navigation_ms: item.start_ms - navigationStartedAt,
      duration_ms: item.duration_ms,
    }))
    .sort((a, b) => a.start_from_navigation_ms - b.start_from_navigation_ms);

  const duplicateCounts = {};
  for (const item of startupRequests) duplicateCounts[item.key] = (duplicateCounts[item.key] || 0) + 1;

  const startupDom = await page.evaluate(() => ({
    dom_nodes: document.getElementsByTagName('*').length,
    home_nodes: document.getElementById('screen-home')?.getElementsByTagName('*').length || 0,
  }));

  const surfaces = [];
  surfaces.push(await measureSurface(page, 'profile', '#screen-profile'));
  surfaces.push(await measureSurface(page, 'store', '#screen-store'));
  surfaces.push(await measureSurface(page, 'tournaments', '#screen-tournaments'));

  console.log('[MGW25_4_BASELINE] ' + JSON.stringify({
    slot: 'A',
    test_setup_ms: testSetupReadyAt - setupStartedAt,
    navigation_to_dom_ms: domContentLoadedAt - navigationStartedAt,
    navigation_to_bootstrap_ms: bootstrapResponseAt - navigationStartedAt,
    bootstrap_to_app_ready_ms: appReadyAt - bootstrapResponseAt,
    app_ready_to_first_usable_ms: firstUsableAt - appReadyAt,
    navigation_to_first_usable_ms: firstUsableAt - navigationStartedAt,
    startup_dom: startupDom,
    startup_requests: startupRequests,
    duplicate_request_counts: Object.fromEntries(Object.entries(duplicateCounts).filter(([, count]) => count > 1)),
    slowest_resources: resources,
    first_surface_opens: surfaces,
  }));

  expect(firstUsableAt - navigationStartedAt).toBeLessThan(35_000);
  await context.close();
});
