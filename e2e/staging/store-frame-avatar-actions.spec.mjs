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

async function authorize(context) {
  const response = await context.request.post(AUTH_URL, {
    headers: {
      Authorization: `Bearer ${await requestOidcToken()}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    data: { action:'issue', slot:'A' },
    timeout: 35_000,
  });
  expect(response.status()).toBe(200);
  const payload = await response.json();
  expect(payload?.ok).toBe(true);
}

function requestAction(request) {
  try { return String(request.postDataJSON()?.action || ''); } catch { return ''; }
}

test('STORE ACTION SMOKE: avatar action stays scoped away from frame previews', async ({ browser }) => {
  const context = await browser.newContext({
    locale:'ru-RU', timezoneId:'Europe/Vilnius', viewport:{ width:390, height:844 },
    isMobile:true, hasTouch:true,
  });
  const page = await context.newPage();
  const pageErrors = [];
  const serverErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error).slice(0, 400)));
  page.on('response', response => {
    if (response.url().startsWith(ORIGIN) && response.status() >= 500) {
      serverErrors.push({ path:new URL(response.url()).pathname, status:response.status() });
    }
  });

  try {
    await authorize(context);
    const bootstrapPromise = page.waitForResponse(response => (
      response.url() === `${ORIGIN}/bot/api.php`
      && response.request().method() === 'POST'
      && requestAction(response.request()) === 'bootstrap'
    ), { timeout:35_000 });
    const entry = await page.goto(ENTRY_URL, { waitUntil:'domcontentloaded' });
    expect(entry?.ok()).toBe(true);
    expect((await bootstrapPromise).status()).toBe(200);
    await page.waitForFunction(() => window.__MGW_APP_BOOTSTRAP_V2__?.ready === true, null, { timeout:20_000 });
    await expect(page.locator('#screen-home')).toHaveClass(/active/, { timeout:20_000 });

    const storeNav = page.locator('[data-shell-nav="store"]');
    const homeNav = page.locator('[data-shell-nav="home"]');
    await storeNav.click({ timeout:5_000 });
    await expect(page.locator('#screen-store')).toHaveClass(/active/, { timeout:10_000 });
    await expect(page.locator('#storeTabSurface .store-v2-shell:not(.is-pending)')).toBeVisible({ timeout:20_000 });

    const injected = await page.evaluate(() => {
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

    const avatarAction = page.locator('#stagingOwnedAvatarObserverProbe [data-mgw-store-avatar-select="store-avatar-01"]');
    await expect(avatarAction).toBeVisible({ timeout:5_000 });
    await expect(avatarAction).toHaveText(/^(Выбрать|Снять)$/);
    await expect(page.locator('#stagingOwnedAvatarObserverProbe .store-v2-product-foot > b')).toHaveText(/^(В коллекции|Выбрано)$/);

    // A frame preview deliberately reuses starter-default-01 as artwork. It must
    // never receive the avatar action owner; otherwise frame cards gain a second
    // competing Выбрать/Снять button again.
    await page.waitForTimeout(350);
    await expect(page.locator('#stagingFrameAvatarCollisionProbe [data-mgw-store-avatar-select]')).toHaveCount(0);
    await expect(page.locator('#stagingFrameAvatarCollisionProbe button')).toHaveCount(1);

    // This also keeps the historical observer-loop regression covered: if the
    // decorator mutates itself forever, these navigation clicks cannot complete.
    await homeNav.click({ timeout:3_000 });
    await expect(page.locator('#screen-home')).toHaveClass(/active/, { timeout:3_000 });
    await storeNav.click({ timeout:3_000 });
    await expect(page.locator('#screen-store')).toHaveClass(/active/, { timeout:3_000 });
    await homeNav.click({ timeout:3_000 });
    await expect(page.locator('#screen-home')).toHaveClass(/active/, { timeout:3_000 });

    expect(pageErrors).toEqual([]);
    expect(serverErrors).toEqual([]);
  } finally {
    await context.close().catch(() => null);
  }
});
