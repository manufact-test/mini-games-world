import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

// Read-only staging UI smoke. No account-language save, purchases, or game mutation.
const ORIGIN = process.env.MGW_STAGING_ORIGIN || 'https://seashell-okapi-889488.hostingersite.com';
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const launchSource = readFileSync(resolve(root, 'bot/helpers/WebAppLaunchUrl.php'), 'utf8');
const match = launchSource.match(/^\s*private const ENTRY_PATH = '([^']+)';/m);
if (!match) throw new Error('Active Telegram launch path is missing.');
const ENTRY_URL = ORIGIN + match[1];
const AUTH_URL = ORIGIN + '/bot/staging-test-auth.php';

async function oidc() {
  const requestUrl = process.env.ACTIONS_ID_TOKEN_REQUEST_URL;
  const bearer = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN;
  if (!requestUrl || !bearer) throw new Error('Staging OIDC identity is unavailable.');
  const url = new URL(requestUrl);
  url.searchParams.set('audience', 'mini-games-world-staging-e2e');
  const response = await fetch(url, {
    headers: { Authorization: 'bearer ' + bearer, Accept: 'application/json' },
  });
  if (!response.ok) throw new Error('Staging OIDC token failed: ' + response.status);
  const data = await response.json();
  if (!data.value) throw new Error('Staging OIDC token is empty.');
  return data.value;
}

test('RU/EN renders real Home + Settings on 320px and 390px mobile staging', async ({ browser }) => {
  const context = await browser.newContext({
    locale: 'ru-RU',
    timezoneId: 'Europe/Vilnius',
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
  });
  try {
    const response = await context.request.post(AUTH_URL, {
      headers: {
        Authorization: 'Bearer ' + await oidc(),
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      data: { action: 'issue', slot: 'A' },
      timeout: 35_000,
    });
    expect(response.status()).toBe(200);
    expect((await response.json()).ok).toBe(true);
    const cookie = (await context.cookies(ORIGIN)).find(entry => entry.name === 'mgw_staging_test_session');
    expect(cookie?.httpOnly).toBe(true);

    const page = await context.newPage();
    const bootstrap = page.waitForResponse(entry => (
      entry.url() === ORIGIN + '/bot/api.php'
      && entry.request().method() === 'POST'
      && (() => { try { return entry.request().postDataJSON()?.action === 'bootstrap'; } catch { return false; } })()
    ), { timeout: 35_000 });
    expect((await page.goto(ENTRY_URL, { waitUntil: 'domcontentloaded' }))?.ok()).toBe(true);
    expect((await bootstrap).status()).toBe(200);
    await page.waitForFunction(() => window.__MGW_APP_BOOTSTRAP_V2__?.ready === true, null, { timeout: 20_000 });
    await expect(page.locator('#screen-home')).toHaveClass(/active/, { timeout: 25_000 });
    await page.waitForFunction(() => document.getElementById('preloader')?.classList.contains('hidden') === true, null, { timeout: 25_000 });

    const locales = [
      { code: 'en', hero: 'Mini Games World', play: 'Play', settings: 'Settings', language: 'Language' },
      { code: 'ru', hero: 'Мировые мини-игры', play: 'Играть', settings: 'Настройки', language: 'Язык' },
    ];
    for (const width of [390, 320]) {
      await page.setViewportSize({ width, height: 844 });
      for (const locale of locales) {
        const selected = await page.evaluate(async code => {
          // Import-map-resolved active i18n owner, not a second module instance.
          const localeOwner = await import('@mgw/i18n');
          return localeOwner.previewAccountLocale(code);
        }, locale.code);
        expect(selected).toBe(locale.code);
        await expect(page.locator('html')).toHaveAttribute('lang', locale.code);
        await expect(page.locator('#screen-home .hero-title')).toHaveText(locale.hero);
        const gameActions = page.locator('#screen-home .game-card .btn.primary');
        expect(await gameActions.count()).toBeGreaterThan(0);
        await expect(gameActions.first()).toHaveText(locale.play);
        await expect(page.locator('#moreMenuOpen')).toHaveAttribute('aria-label', locale.settings === 'Settings' ? 'Menu' : 'Меню');
        await page.locator('#moreMenuOpen').click();
        await expect(page.locator('#settingsBtn')).toContainText(locale.settings);
        await page.locator('#settingsBtn').click();
        await expect(page.locator('.sheet-head h2').last()).toHaveText(locale.settings);
        await expect(page.locator('#languageSettingsBtn')).toBeVisible();
        await page.locator('#languageSettingsBtn').click();
        await expect(page.locator('.sheet-head h2').last()).toHaveText(locale.language);
        await expect(page.locator('#languageRuBtn')).toBeVisible();
        await expect(page.locator('#languageEnBtn')).toBeVisible();
        await page.locator('[data-close-sheet]').last().click();
      }
    }
  } finally {
    await context.close();
  }
});
