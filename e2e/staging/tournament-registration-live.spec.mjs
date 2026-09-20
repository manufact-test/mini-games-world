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
    data: { action: 'issue', slot: 'A' },
    timeout: 35_000,
  });
  expect(response.status(), 'Tournament probe player A auth').toBe(200);
  const payload = await response.json();
  expect(payload?.ok).toBe(true);
  expect(payload?.player_slot).toBe('A');
}

function requestAction(request) {
  try { return String(request.postDataJSON()?.action || ''); } catch { return ''; }
}

async function openPlayer(browser) {
  const context = await browser.newContext({
    locale: 'ru-RU',
    timezoneId: 'Europe/Vilnius',
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
  });
  await authorize(context);
  const page = await context.newPage();
  const bootstrapPromise = page.waitForResponse(response => (
    response.url() === `${ORIGIN}/bot/api.php`
    && response.request().method() === 'POST'
    && requestAction(response.request()) === 'bootstrap'
  ), { timeout: 35_000 });
  const entry = await page.goto(ENTRY_URL, { waitUntil: 'domcontentloaded' });
  expect(entry?.ok(), 'Tournament probe Telegram entry').toBe(true);
  const bootstrap = await bootstrapPromise;
  expect(bootstrap.status()).toBe(200);
  const payload = await bootstrap.json();
  expect(payload?.ok).toBe(true);
  expect(payload?.user?.id).toBe('stg_test_player_a');
  await page.waitForFunction(() => window.__MGW_APP_BOOTSTRAP_V2__?.ready === true, null, { timeout: 20_000 });
  await page.waitForFunction(() => Boolean(
    localStorage.getItem('mgw_device_session_id') && localStorage.getItem('mgw_device_id')
  ), null, { timeout: 20_000 });
  return { context, page };
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
    return {
      status: response.status,
      payload: await response.json().catch(() => null),
    };
  }, { path, data });
}

function assertOk(result, label) {
  if (result?.status !== 200 || result?.payload?.ok !== true) {
    const diagnostic = result?.payload?.diagnostic
      ? ` diagnostic=${JSON.stringify(result.payload.diagnostic)}`
      : '';
    throw new Error(
      `${label} failed: http=${result?.status ?? 'unknown'} error=${result?.payload?.error || 'unknown'}${diagnostic}`
    );
  }
  return result.payload;
}

async function tournamentStatus(page) {
  return assertOk(
    await browserPost(page, '/bot/tournament-status.php', {}),
    'tournament_status'
  );
}

async function tournamentAction(page, action) {
  return assertOk(
    await browserPost(page, '/bot/api.php', { action }),
    action
  );
}

async function setProbeBalance(page, mode) {
  return assertOk(
    await browserPost(page, '/bot/api.php', {
      action: 'staging_test_tournament_balance',
      mode,
    }),
    `staging_test_tournament_balance:${mode}`
  );
}

test('MVP-21.1 LIVE — real API reserves and releases official tournament entry', async ({ browser }) => {
  const player = await openPlayer(browser);
  let prepared = false;

  try {
    const initial = await tournamentStatus(player.page);
    const initialRegistration = String(initial?.snapshot?.registration?.state || '');
    if (initialRegistration === 'registered') {
      await tournamentAction(player.page, 'tournament_leave');
    }

    // Mark cleanup as required before the request: the DB-primary write can
    // commit before a later response/finalizer failure becomes visible to the
    // browser. Cleanup must therefore run even when prepare itself reports an
    // error after committing.
    prepared = true;
    const preparedPayload = await setProbeBalance(player.page, 'prepare');
    expect(preparedPayload?.staging_tournament_test_balance?.available_after).toBe(60000);
    expect(preparedPayload?.staging_tournament_test_balance?.reserved_after).toBe(0);

    const before = await tournamentStatus(player.page);
    const tournament = before?.snapshot?.tournament || {};
    const beforeCount = Number(tournament.registered_count || 0);
    expect(tournament.state).toBe('registration_open');
    expect(Number(tournament.capacity || 0)).toBeGreaterThan(beforeCount);
    expect(Number(before?.snapshot?.balance?.available_amount || 0)).toBe(60000);
    expect(Number(before?.snapshot?.balance?.reserved_amount || 0)).toBe(0);

    const registered = await tournamentAction(player.page, 'tournament_register');
    expect(registered?.snapshot?.registration?.state).toBe('registered');
    expect(Number(registered?.snapshot?.tournament?.registered_count || 0)).toBe(beforeCount + 1);
    expect(Number(registered?.snapshot?.balance?.available_amount || 0)).toBe(10000);
    expect(Number(registered?.snapshot?.balance?.reserved_amount || 0)).toBe(50000);

    const duplicate = await tournamentAction(player.page, 'tournament_register');
    expect(duplicate?.snapshot?.registration?.state).toBe('registered');
    expect(Number(duplicate?.snapshot?.tournament?.registered_count || 0)).toBe(beforeCount + 1);
    expect(Number(duplicate?.snapshot?.balance?.available_amount || 0)).toBe(10000);
    expect(Number(duplicate?.snapshot?.balance?.reserved_amount || 0)).toBe(50000);

    const freshRegistered = await tournamentStatus(player.page);
    expect(freshRegistered?.snapshot?.registration?.state).toBe('registered');
    expect(Number(freshRegistered?.snapshot?.tournament?.registered_count || 0)).toBe(beforeCount + 1);
    expect(Number(freshRegistered?.snapshot?.balance?.available_amount || 0)).toBe(10000);
    expect(Number(freshRegistered?.snapshot?.balance?.reserved_amount || 0)).toBe(50000);

    const left = await tournamentAction(player.page, 'tournament_leave');
    expect(left?.snapshot?.registration?.state).toBe('withdrawn');
    expect(Number(left?.snapshot?.tournament?.registered_count || 0)).toBe(beforeCount);
    expect(Number(left?.snapshot?.balance?.available_amount || 0)).toBe(60000);
    expect(Number(left?.snapshot?.balance?.reserved_amount || 0)).toBe(0);

    const freshLeft = await tournamentStatus(player.page);
    expect(freshLeft?.snapshot?.registration?.state).toBe('withdrawn');
    expect(Number(freshLeft?.snapshot?.tournament?.registered_count || 0)).toBe(beforeCount);
    expect(Number(freshLeft?.snapshot?.balance?.available_amount || 0)).toBe(60000);
    expect(Number(freshLeft?.snapshot?.balance?.reserved_amount || 0)).toBe(0);
  } finally {
    try {
      const current = await tournamentStatus(player.page);
      if (String(current?.snapshot?.registration?.state || '') === 'registered'
          && !current?.snapshot?.tournament?.is_full) {
        await tournamentAction(player.page, 'tournament_leave');
      }
    } catch (error) {
      console.error('[MGW_TOURNAMENT_PROBE_LEAVE_CLEANUP_FAILED]', String(error));
    }

    if (prepared) {
      try {
        const cleanup = await setProbeBalance(player.page, 'cleanup');
        expect(cleanup?.staging_tournament_test_balance?.available_after).toBe(100);
        expect(cleanup?.staging_tournament_test_balance?.reserved_after).toBe(0);
      } catch (error) {
        console.error('[MGW_TOURNAMENT_PROBE_BALANCE_CLEANUP_FAILED]', String(error));
        throw error;
      }
    }
    await player.context.close();
  }
});
