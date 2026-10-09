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

        const labels = locale.code === 'en'
          ? ['Home','Arena','Store','Profile']
          : ['Главная','Арена','Магазин','Профиль'];
        const nav = page.locator('#appBottomNav');
        const routes = ['home','tournaments','store','profile'];
        for (let i = 0; i < routes.length; i++) {
          const button = nav.locator(`[data-shell-nav="${routes[i]}"]`);
          await expect(button.locator('.app-bottom-nav-label')).toHaveText(labels[i]);
          await expect(button).toHaveAttribute('aria-label', labels[i]);
        }

        await nav.locator('[data-shell-nav="tournaments"]').click();
        await expect(page.locator('#screen-tournaments')).toHaveClass(/active/);
        await expect(page.locator('#tournamentsV2Root .tournaments-v2-page-head .page-title'))
          .toHaveText(locale.code === 'en' ? 'Competitions' : 'Соревнования');
        await expect(page.locator('[data-competition-mode="rating"]'))
          .toHaveText(locale.code === 'en' ? 'Rating' : 'Рейтинг');
        await expect(page.locator('[data-competition-mode="tournaments"]'))
          .toHaveText(locale.code === 'en' ? 'Tournaments' : 'Турниры');
        await nav.locator('[data-shell-nav="home"]').click();
        await expect(page.locator('#screen-home')).toHaveClass(/active/);
      }
    }
  } finally {
    await context.close();
  }
});


test('Cold-open history hydrate: first Profile entry and full reopen, RU/EN, no extra tap', async ({ browser }) => {
  test.setTimeout(240_000);
  const context = await browser.newContext({
    viewport: { width:390, height:844 },
    isMobile:true,
    hasTouch:true,
    locale:'en-US',
    timezoneId:'Europe/Vilnius',
  });
  try {
    const auth = await context.request.post(AUTH_URL, {
      headers: {
        Authorization:'Bearer ' + await oidc(),
        Accept:'application/json',
        'Content-Type':'application/json',
      },
      data:{ action:'issue', slot:'A' },
      timeout:35_000,
    });
    expect(auth.status()).toBe(200);
    expect((await auth.json()).ok).toBe(true);

    const page = await context.newPage();
    let activeTrialLocale = 'en';
    // Profile bootstrap may finish after the temporary locale preview. Serve
    // the same trial locale in the browser-only canonical account read, so a
    // persisted staging test account preference cannot race this RU/EN smoke.
    // The real account and its preferred_locale are never updated.
    await page.route('**/bot/profile.php', async route => {
      const response = await route.fetch();
      if (!response.ok()) return route.fulfill({ response });
      const result = await response.json();
      if (result?.ok !== true || !result?.profile) return route.fulfill({ response });
      return route.fulfill({
        response,
        json:{ ...result, profile:{ ...result.profile, preferred_locale:activeTrialLocale } },
      });
    });
    let currentGate = Promise.resolve();
    let signalRequest = () => {};
    const fixture = {
      id:'client-only-cold-open-history-fixture',
      game_type:'tictactoe',
      board_columns:3,
      board_rows:3,
      opponent:'History fixture opponent',
      tone:'pos',
      result_key:'server.history.match.victory',
      result:'Victory',
      finished_at:'2026-10-09T08:00:00Z',
      economy:{ entry:0, reward:0, ledger_delta:0, new_balance:null },
    };
    // Real authenticated profile response, with one synthetic match injected
    // into the browser response only. Never create/modify an actual game, DB,
    // wallet, history ledger entry, or account locale setting.
    await page.route('**/bot/profile-v2.php', async route => {
      const payload = (() => { try { return route.request().postDataJSON(); } catch { return {}; } })();
      if (payload?.profile_update || payload?.tournament_prestige_only) {
        await route.continue();
        return;
      }
      const waitForRelease = currentGate;
      signalRequest();
      const response = await route.fetch();
      if (!response.ok()) {
        await route.fulfill({ response });
        return;
      }
      const result = await response.json();
      if (result?.ok !== true || !result?.profile) {
        await route.fulfill({ response });
        return;
      }
      await waitForRelease;
      await route.fulfill({
        response,
        json:{
          ...result,
          profile:{ ...result.profile, preferred_locale:activeTrialLocale },
          history:{ ...(result.history || {}), matches:[fixture] },
        },
      });
    });

    const trials = [
      { locale:'en', result:'Victory' },
      { locale:'en', result:'Victory' },
      { locale:'ru', result:'Победа' },
      { locale:'ru', result:'Победа' },
    ];
    for (const trial of trials) {
      activeTrialLocale = trial.locale;
      let release;
      currentGate = new Promise(resolve => { release = resolve; });
      const requested = new Promise(resolve => { signalRequest = resolve; });

      const bootstrap = page.waitForResponse(response => (
        response.url() === ORIGIN + '/bot/api.php'
        && response.request().method() === 'POST'
        && (() => { try { return response.request().postDataJSON()?.action === 'bootstrap'; } catch { return false; } })()
      ), { timeout:35_000 });
      expect((await page.goto(ENTRY_URL, { waitUntil:'domcontentloaded' }))?.ok()).toBe(true);
      expect((await bootstrap).status()).toBe(200);
      await page.waitForFunction(() => window.__MGW_APP_BOOTSTRAP_V2__?.ready === true, null, { timeout:25_000 });
      await page.waitForFunction(() => document.getElementById('preloader')?.classList.contains('hidden') === true, null, { timeout:25_000 });

      await page.evaluate(async code => {
        const language = await import('@mgw/i18n');
        language.previewAccountLocale(code);
      }, trial.locale);
      await expect(page.locator('html')).toHaveAttribute('lang', trial.locale);
      await page.locator('#appBottomNav [data-shell-nav="profile"]').click();
      await expect(page.locator('#screen-profile')).toHaveClass(/active/);
      const history = page.locator('#profileV2Root .profile-v2-history');
      const identity = await page.locator('#profileV2Root .profile-v2-identity').elementHandle();
      expect(identity).toBeTruthy();
      const beforeBalance = await page.locator('#profileV2Root .profile-v2-balance strong').innerText();
      await requested;
      // While the authoritative Profile response is held, "empty" must never
      // be presented as a factual history result on an unauthenticated first read.
      await expect(history.locator('.profile-v2-history-row')).toHaveCount(0);
      await expect(history).not.toContainText(trial.result);
      release();

      const rows = history.locator('.profile-v2-history-row');
      await expect(rows).toHaveCount(1, { timeout:25_000 });
      await expect(rows.first()).toContainText('History fixture opponent');
      await expect(rows.first().locator('.profile-v2-history-result b')).toHaveText(trial.result);
      expect(await identity.evaluate(element => element.isConnected)).toBe(true);
      expect(await page.locator('#profileV2Root .profile-v2-balance strong').innerText()).toBe(beforeBalance);
      await expect(page.locator('html')).toHaveAttribute('lang', trial.locale);
    }
  } finally {
    await context.close();
  }
});
