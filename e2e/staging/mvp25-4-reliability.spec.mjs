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

function isPresencePing(request) {
  try {
    return new URL(request.url()).pathname === '/bot/presence.php'
      && request.method() === 'POST'
      && requestAction(request) === 'ping';
  } catch {
    return false;
  }
}

test('MVP-25.4 temporary network loss recovers without trapping the app', async ({ browser }) => {
  const context = await browser.newContext({
    locale: 'ru-RU',
    timezoneId: 'Europe/Vilnius',
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
  });
  await authorize(context, 'A');

  const page = await context.newPage();
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error || 'pageerror')));

  const entry = await page.goto(ENTRY_URL, { waitUntil: 'domcontentloaded' });
  expect(entry?.ok()).toBe(true);
  await page.waitForFunction(() => window.__MGW_APP_BOOTSTRAP_V2__?.ready === true, null, { timeout: 25_000 });
  await page.waitForFunction(() => document.getElementById('preloader')?.classList.contains('hidden') === true, null, { timeout: 25_000 });
  await expect(page.locator('#screen-home')).toHaveClass(/active/, { timeout: 15_000 });

  const offlineStartedAt = Date.now();
  await context.setOffline(true);

  const failedPing = page.waitForEvent('requestfailed', {
    predicate: request => isPresencePing(request),
    timeout: 8_000,
  });

  // The production presence owner treats a visible lifecycle signal as an
  // authoritative resume and immediately pings. With transport offline this
  // must fail into its bounded retry path rather than poisoning app state.
  await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
  await failedPing;
  const offlineFailureAt = Date.now();

  const recoveredPing = page.waitForResponse(response => (
    response.status() === 200 && isPresencePing(response.request())
  ), { timeout: 12_000 });

  await context.setOffline(false);
  const response = await recoveredPing;
  expect(response.status()).toBe(200);
  const recoveredAt = Date.now();

  await expect(page.locator('#screen-home')).toHaveClass(/active/, { timeout: 10_000 });
  await expect(page.locator('#preloader')).toHaveClass(/hidden/);

  const profile = page.locator('[data-shell-nav="profile"]').first();
  await expect(profile).toBeVisible();
  await profile.click();
  await expect(page.locator('#screen-profile')).toHaveClass(/active/, { timeout: 10_000 });

  const home = page.locator('[data-shell-nav="home"]').first();
  await home.click();
  await expect(page.locator('#screen-home')).toHaveClass(/active/, { timeout: 10_000 });

  expect(pageErrors, 'No uncaught page errors after offline/recovery').toEqual([]);

  console.log('[MGW25_4_RELIABILITY] ' + JSON.stringify({
    offline_failure_ms: offlineFailureAt - offlineStartedAt,
    recovery_after_failure_ms: recoveredAt - offlineFailureAt,
    app_interactive_after_recovery: true,
  }));

  await context.close();
});
