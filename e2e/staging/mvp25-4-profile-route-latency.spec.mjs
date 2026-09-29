import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const ORIGIN = process.env.MGW_STAGING_ORIGIN || 'https://seashell-okapi-889488.hostingersite.com';
const AUTH_URL = `${ORIGIN}/bot/staging-test-auth.php`;
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const launchSource = readFileSync(resolve(repoRoot, 'bot/helpers/WebAppLaunchUrl.php'), 'utf8');
const entryMatch = launchSource.match(/^\s*private const ENTRY_PATH = '([^']+)'/m);
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
}

async function armProfileProbe(page) {
  await page.evaluate(() => {
    globalThis.__MGW_PROFILE_ROUTE_PROBE__?.cleanup?.();

    const state = {
      events: [],
      frames: [],
      longTasks: [],
      cleanup: null,
    };
    const now = () => performance.now();
    const push = (name, extra = {}) => state.events.push({ name, t: now(), ...extra });

    const onPointer = event => {
      if (event.target instanceof Element && event.target.closest('[data-shell-nav="profile"]')) {
        push('profile-pointerdown');
      }
    };
    const onClick = event => {
      if (event.target instanceof Element && event.target.closest('[data-shell-nav="profile"]')) {
        push('profile-click-capture');
      }
    };
    const onScreen = event => {
      const from = String(event?.detail?.from || '');
      const to = String(event?.detail?.to || '');
      if (from === 'profile' || to === 'profile') push('screen-changed', { from, to });
    };

    window.addEventListener('pointerdown', onPointer, true);
    window.addEventListener('click', onClick, true);
    document.addEventListener('mgw:screen-changed', onScreen);

    const screen = document.getElementById('screen-profile');
    const mutation = new MutationObserver(() => {
      push('profile-class-mutation', { active: screen?.classList.contains('active') === true });
    });
    if (screen) mutation.observe(screen, { attributes: true, attributeFilter: ['class'] });

    let perfObserver = null;
    if (typeof PerformanceObserver === 'function' && PerformanceObserver.supportedEntryTypes?.includes('longtask')) {
      perfObserver = new PerformanceObserver(list => {
        for (const entry of list.getEntries()) {
          state.longTasks.push({
            start: entry.startTime,
            duration: entry.duration,
            name: String(entry.name || ''),
          });
        }
      });
      perfObserver.observe({ type: 'longtask', buffered: true });
    }

    let frameId = 0;
    const frame = t => {
      state.frames.push(t);
      if (state.frames.length < 180) frameId = requestAnimationFrame(frame);
    };
    frameId = requestAnimationFrame(frame);

    state.cleanup = () => {
      window.removeEventListener('pointerdown', onPointer, true);
      window.removeEventListener('click', onClick, true);
      document.removeEventListener('mgw:screen-changed', onScreen);
      mutation.disconnect();
      perfObserver?.disconnect();
      if (frameId) cancelAnimationFrame(frameId);
    };
    globalThis.__MGW_PROFILE_ROUTE_PROBE__ = state;
  });
}

async function collectProfileProbe(page, label) {
  return page.evaluate(label => {
    const state = globalThis.__MGW_PROFILE_ROUTE_PROBE__;
    if (!state) return { label, missing: true };

    const events = state.events || [];
    const pointer = events.find(item => item.name === 'profile-pointerdown')?.t ?? null;
    const click = events.find(item => item.name === 'profile-click-capture')?.t ?? null;
    const activeMutation = events.find(item => item.name === 'profile-class-mutation' && item.active)?.t ?? null;
    const screenChanged = events.find(item => item.name === 'screen-changed' && item.to === 'profile')?.t ?? null;
    const base = pointer ?? click ?? events[0]?.t ?? performance.now();
    const frames = (state.frames || []).filter(t => t >= base).slice(0, 8).map(t => Math.round((t - base) * 10) / 10);
    const longTasks = (state.longTasks || [])
      .filter(item => item.start + item.duration >= base - 10 && item.start <= base + 1200)
      .map(item => ({
        start_ms: Math.round((item.start - base) * 10) / 10,
        duration_ms: Math.round(item.duration * 10) / 10,
        name: item.name,
      }));

    state.cleanup?.();

    const rel = t => t === null ? null : Math.round((t - base) * 10) / 10;
    return {
      label,
      pointer_to_click_ms: pointer !== null && click !== null ? Math.round((click - pointer) * 10) / 10 : null,
      active_mutation_ms: rel(activeMutation),
      screen_changed_ms: rel(screenChanged),
      frames_ms: frames,
      long_tasks: longTasks,
      profile_nodes: document.getElementById('screen-profile')?.getElementsByTagName('*').length || 0,
    };
  }, label);
}

async function openProfileWithProbe(page, label) {
  await armProfileProbe(page);
  const started = Date.now();
  const profileNav = page.locator('[data-shell-nav="profile"]').first();
  await profileNav.click();
  await expect(page.locator('#screen-profile')).toHaveClass(/active/);
  await page.waitForTimeout(650);
  const result = await collectProfileProbe(page, label);
  result.playwright_click_to_active_ms = Date.now() - started;
  return result;
}

test('MVP-25.4 mobile Profile route phase probe', async ({ browser }) => {
  const context = await browser.newContext({
    locale: 'ru-RU',
    timezoneId: 'Europe/Vilnius',
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
  });
  await authorize(context, 'A');

  const page = await context.newPage();
  const response = await page.goto(ENTRY_URL, { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBe(true);
  await page.waitForFunction(() => window.__MGW_APP_BOOTSTRAP_V2__?.ready === true, null, { timeout: 25_000 });
  await page.waitForFunction(() => document.getElementById('preloader')?.classList.contains('hidden') === true, null, { timeout: 25_000 });
  await expect(page.locator('#screen-home')).toHaveClass(/active/);
  await page.waitForTimeout(350);

  const first = await openProfileWithProbe(page, 'first');

  await page.locator('[data-shell-nav="home"]').first().click();
  await expect(page.locator('#screen-home')).toHaveClass(/active/);
  await page.waitForTimeout(350);

  const repeat = await openProfileWithProbe(page, 'repeat');

  console.log('[MGW25_4_PROFILE_ROUTE_PROBE] ' + JSON.stringify({ first, repeat }));

  expect(first.active_mutation_ms).not.toBeNull();
  expect(repeat.active_mutation_ms).not.toBeNull();
  await context.close();
});
