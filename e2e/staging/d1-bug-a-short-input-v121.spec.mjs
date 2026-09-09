import { test, expect } from '@playwright/test';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = `${STAGING_ORIGIN}/app/`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const NOTIFICATIONS_ROUTE = `${STAGING_ORIGIN}/bot/notifications.php`;
const TEST_COOKIE = 'mgw_staging_test_session';

async function requestOidcToken() {
  const requestUrl = process.env.ACTIONS_ID_TOKEN_REQUEST_URL || '';
  const requestToken = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN || '';
  if (!requestUrl || !requestToken) throw new Error('GitHub Actions OIDC environment is unavailable.');
  const url = new URL(requestUrl);
  url.searchParams.set('audience', OIDC_AUDIENCE);
  const response = await fetch(url, { headers:{ Authorization:`bearer ${requestToken}`, Accept:'application/json' } });
  if (!response.ok) throw new Error(`OIDC request failed: ${response.status}`);
  const payload = await response.json();
  if (typeof payload?.value !== 'string') throw new Error('OIDC token is unavailable.');
  return payload.value;
}

async function authorizeContext(context) {
  const oidcToken = await requestOidcToken();
  const response = await context.request.post(AUTH_ROUTE, {
    headers:{ Authorization:`Bearer ${oidcToken}`, Accept:'application/json', 'Content-Type':'application/json' },
    data:{ action:'issue', slot:'A' },
    timeout:35_000,
  });
  expect(response.status()).toBe(200);
  expect(await response.json()).toMatchObject({ ok:true, action:'issued', player_slot:'A' });
  expect((await context.cookies(STAGING_ORIGIN)).some(item => item.name === TEST_COOKIE)).toBe(true);
}

function requestAction(response) {
  try { return String(response.request().postDataJSON()?.action || ''); }
  catch { return ''; }
}

async function openPlayer(browser, isMobile) {
  const context = await browser.newContext({
    locale:'ru-RU', timezoneId:'Europe/Vilnius',
    viewport:isMobile ? { width:390, height:844 } : { width:1280, height:900 },
    deviceScaleFactor:1, isMobile, hasTouch:isMobile,
  });
  await context.addInitScript(() => {
    window.__MGW_SHORT_INPUT_TRACE__ = [];
    const record = (scope, phase) => event => {
      const target = event.target instanceof Element ? event.target.closest('#notificationsOpen, #notificationToast') : null;
      if (!target) return;
      window.__MGW_SHORT_INPUT_TRACE__.push({ scope, phase, type:event.type, target:target.id, at:performance.now() });
    };
    for (const type of ['pointerdown', 'pointerup', 'click']) {
      window.addEventListener(type, record('window', 'capture'), true);
      document.addEventListener(type, record('document', 'capture'), true);
      document.addEventListener(type, record('document', 'bubble'), false);
    }
  });
  await authorizeContext(context);
  const page = await context.newPage();
  const bootstrap = page.waitForResponse(response => response.url() === API_ROUTE
    && response.request().method() === 'POST'
    && requestAction(response) === 'bootstrap', { timeout:35_000 });
  const response = await page.goto(APP_ROUTE, { waitUntil:'domcontentloaded' });
  expect(response?.ok()).toBe(true);
  expect((await bootstrap).status()).toBe(200);
  await expect(page.locator('#preloader')).toBeHidden({ timeout:20_000 });
  return { context, page };
}

async function closeSheet(page) {
  const close = page.locator('#sheet [data-close-sheet]').first();
  await expect(close).toBeVisible({ timeout:5_000 });
  await close.click();
  await expect(page.locator('#sheetOverlay')).not.toHaveClass(/active/, { timeout:5_000 });
}

async function shortDesktopPress(page, durationMs) {
  const box = await page.locator('#notificationsOpen').boundingBox();
  expect(box).not.toBeNull();
  const x = box.x + box.width / 2;
  const y = box.y + box.height / 2;
  await page.mouse.move(x, y);
  await page.mouse.down();
  await page.waitForTimeout(durationMs);
  await page.mouse.up();
}

async function shortMobileTap(page) {
  const box = await page.locator('#notificationsOpen').boundingBox();
  expect(box).not.toBeNull();
  await page.touchscreen.tap(box.x + box.width / 2, box.y + box.height / 2);
}

async function runShortInputCycles(page, isMobile) {
  let markReadCalls = 0;
  await page.route(NOTIFICATIONS_ROUTE, async route => {
    let payload = {};
    try { payload = route.request().postDataJSON() || {}; } catch {}
    if (!payload.markRead) return route.continue();
    markReadCalls += 1;
    const delayMs = markReadCalls === 1 ? 1_600 : (markReadCalls % 5 === 0 ? 700 : 120);
    await new Promise(resolve => setTimeout(resolve, delayMs));
    return route.continue();
  });

  const pressDurations = [5, 8, 13, 21, 29];
  for (let cycle = 0; cycle < 25; cycle += 1) {
    if (isMobile) await shortMobileTap(page);
    else await shortDesktopPress(page, pressDurations[cycle % pressDurations.length]);
    await expect(page.locator('#sheetOverlay')).toHaveClass(/active/, { timeout:650 });
    await expect(page.locator('#sheet .sheet-head h2')).toHaveText('Уведомления', { timeout:650 });
    await closeSheet(page);
  }

  await page.waitForTimeout(1_850);
  await expect(page.locator('#sheetOverlay')).not.toHaveClass(/active/);
  expect(markReadCalls).toBeGreaterThanOrEqual(1);
  const trace = await page.evaluate(() => window.__MGW_SHORT_INPUT_TRACE__ || []);
  const downs = trace.filter(item => item.scope === 'window' && item.phase === 'capture' && item.type === 'pointerdown');
  const ups = trace.filter(item => item.scope === 'window' && item.phase === 'capture' && item.type === 'pointerup');
  expect(downs.length).toBeGreaterThanOrEqual(25);
  expect(ups.length).toBeGreaterThanOrEqual(25);
  for (let index = 0; index < 25; index += 1) expect(downs[index]?.at).toBeLessThanOrEqual(ups[index]?.at);
  await page.unroute(NOTIFICATIONS_ROUTE);
}

test('D1 bug A v121: desktop real 5-29ms pointer presses open first try for 25 cycles', async ({ browser }) => {
  const player = await openPlayer(browser, false);
  try { await runShortInputCycles(player.page, false); }
  finally {
    try { await player.context.request.post(AUTH_ROUTE, { data:{ action:'revoke' }, timeout:15_000 }); } catch {}
    await player.context.close();
  }
});

test('D1 bug A v121: mobile real touch taps open first try for 25 cycles', async ({ browser }) => {
  const player = await openPlayer(browser, true);
  try { await runShortInputCycles(player.page, true); }
  finally {
    try { await player.context.request.post(AUTH_ROUTE, { data:{ action:'revoke' }, timeout:15_000 }); } catch {}
    await player.context.close();
  }
});
