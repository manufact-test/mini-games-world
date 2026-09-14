import { test, expect } from '@playwright/test';

const ORIGIN = process.env.MGW_STAGING_ORIGIN || 'https://seashell-okapi-889488.hostingersite.com';
const AUTH_URL = `${ORIGIN}/bot/staging-test-auth.php`;
const STORE_URL = `${ORIGIN}/bot/cosmetic-store.php`;
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';

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

test('GO STORE LIVE CATALOG: automatic staging update publishes the full Go catalog', async ({ browser }) => {
  test.setTimeout(390_000);
  const context = await browser.newContext();
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
  } finally {
    await context.close().catch(() => null);
  }
});
