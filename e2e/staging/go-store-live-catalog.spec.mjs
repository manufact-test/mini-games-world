import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const ORIGIN = process.env.MGW_STAGING_ORIGIN || 'https://seashell-okapi-889488.hostingersite.com';
const AUTH_URL = `${ORIGIN}/bot/staging-test-auth.php`;
const STORE_URL = `${ORIGIN}/bot/cosmetic-store.php`;
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

async function authorize(context) {
  const response = await context.request.post(AUTH_URL, {
    headers: {
      Authorization: `Bearer ${await requestOidcToken()}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    data: { action: 'issue', slot: 'A' },
    timeout: 35_000,
  });
  expect(response.status(), 'staging player A auth').toBe(200);
  const payload = await response.json();
  expect(payload?.ok).toBe(true);
  expect(payload?.player_slot).toBe('A');
}

async function readStore(context) {
  const response = await context.request.post(STORE_URL, {
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    data: {
      action: 'status',
      initData: '',
      sessionId: 'staging-go-store-catalog-contract',
      deviceId: 'staging-go-store-catalog-contract',
    },
    timeout: 20_000,
  });
  const payload = await response.json().catch(() => null);
  return { status: response.status(), payload };
}

function goCatalog(result) {
  return result?.payload?.store?.games?.catalogs?.go || null;
}

test('GO STORE LIVE CATALOG: automatic staging update publishes the full Go catalog and Store tab', async ({ browser }) => {
  test.setTimeout(390_000);
  const context = await browser.newContext({
    locale: 'ru-RU',
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
  });
  try {
    await authorize(context);

    let latest = null;
    await expect.poll(async () => {
      latest = await readStore(context);
      if (latest.status !== 200 || latest.payload?.ok !== true) return `http:${latest.status}`;
      const catalog = goCatalog(latest);
      if (!catalog) {
        return `catalogs:${Object.keys(latest.payload?.store?.games?.catalogs || {}).join(',')}`;
      }
      return `${catalog.themes?.length || 0}/${catalog.elements?.length || 0}/${catalog.effects?.length || 0}`;
    }, {
      timeout: 330_000,
      intervals: [1_000, 4_000, 10_000, 15_000],
      message: 'Go must reach the live staging Store through the normal automatic deployment/update path',
    }).toBe('4/4/3');

    const catalog = goCatalog(latest);
    expect(catalog?.game_type).toBe('go');
    expect(catalog?.themes).toHaveLength(4);
    expect(catalog?.elements).toHaveLength(4);
    expect(catalog?.effects).toHaveLength(3);
    expect(catalog?.themes?.map(item => item.metadata?.variant)).toEqual(['wood', 'dark', 'stone', 'neon']);
    expect(catalog?.elements?.map(item => item.metadata?.variant)).toEqual(['classic', 'marble', 'glass', 'neon']);
    expect(catalog?.effects?.map(item => item.metadata?.variant)).toEqual(['placement', 'group-capture', 'territory-finish']);

    const page = await context.newPage();
    const entry = await page.goto(ENTRY_URL, { waitUntil: 'domcontentloaded' });
    expect(entry?.ok(), 'fresh Telegram entry').toBe(true);
    await page.waitForFunction(() => window.__MGW_APP_BOOTSTRAP_V2__?.ready === true, null, { timeout: 25_000 });
    await expect(page.locator('#screen-home')).toHaveClass(/active/, { timeout: 25_000 });

    await page.locator('[data-shell-nav="store"]').click({ timeout: 8_000 });
    await expect(page.locator('#screen-store')).toHaveClass(/active/, { timeout: 10_000 });
    await expect(page.locator('#storeTabSurface .store-v2-shell:not(.is-pending)')).toBeVisible({ timeout: 25_000 });
    await page.locator('[data-store-v2-tab="games"]').click({ timeout: 8_000 });

    const selector = page.locator('#storeTabSurface .store-v2-game-selector');
    await expect(selector).toBeVisible({ timeout: 10_000 });
    const goButton = selector.locator('[data-store-v2-game="go"]');
    await expect(goButton).toHaveCount(1);
    await expect(goButton).toHaveText('Го');
    await goButton.scrollIntoViewIfNeeded();
    await expect(goButton).toBeVisible();

    const gameTypes = await selector.locator('[data-store-v2-game]').evaluateAll(nodes => nodes.map(node => node.getAttribute('data-store-v2-game')));
    expect(gameTypes).toEqual(['tictactoe', 'chess', 'checkers', 'reversi', 'go']);
  } finally {
    await context.close().catch(() => null);
  }
});
