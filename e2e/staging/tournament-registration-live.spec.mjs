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

async function requestOidcToken(){
  const source = process.env.ACTIONS_ID_TOKEN_REQUEST_URL || '';
  const bearer = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN || '';
  if (!source || !bearer) throw new Error('GitHub Actions OIDC environment is unavailable.');
  const url = new URL(source);
  url.searchParams.set('audience', OIDC_AUDIENCE);
  const response = await fetch(url, {
    headers:{ Authorization:`bearer ${bearer}`, Accept:'application/json' },
  });
  if (!response.ok) throw new Error(`OIDC request failed: ${response.status}`);
  const payload = await response.json();
  if (typeof payload?.value !== 'string') throw new Error('OIDC JWT is unavailable.');
  return payload.value;
}

async function resetPlayers(){
  const response = await fetch(AUTH_URL, {
    method:'POST',
    headers:{
      Authorization:`Bearer ${await requestOidcToken()}`,
      Accept:'application/json',
      'Content-Type':'application/json',
    },
    body:JSON.stringify({ action:'reset_test_players' }),
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok || payload?.ok !== true || payload?.economy_parity !== true) {
    throw new Error(`Tournament E2E reset failed: ${response.status} ${payload?.stage || payload?.reason_code || payload?.error || ''}`);
  }
}

async function authorize(context){
  const response = await context.request.post(AUTH_URL, {
    headers:{
      Authorization:`Bearer ${await requestOidcToken()}`,
      Accept:'application/json',
      'Content-Type':'application/json',
    },
    data:{ action:'issue', slot:'A' },
    timeout:35_000,
  });
  expect(response.status(), 'Tournament Player A auth').toBe(200);
  const payload = await response.json();
  expect(payload?.ok).toBe(true);
}

async function openPlayer(browser){
  const context = await browser.newContext({
    locale:'ru-RU',
    timezoneId:'Europe/Vilnius',
    viewport:{ width:390, height:844 },
    isMobile:true,
    hasTouch:true,
  });
  await authorize(context);
  const page = await context.newPage();
  const bootstrapPromise = page.waitForResponse(response => {
    if (response.url() !== `${ORIGIN}/bot/api.php`) return false;
    if (response.request().method() !== 'POST') return false;
    try { return String(response.request().postDataJSON()?.action || '') === 'bootstrap'; }
    catch { return false; }
  }, { timeout:35_000 }).catch(() => null);
  const entry = await page.goto(ENTRY_URL, { waitUntil:'domcontentloaded' });
  expect(entry?.ok(), 'Tournament Player A entry').toBe(true);
  await page.waitForFunction(() => window.__MGW_APP_BOOTSTRAP_V2__?.ready === true, null, { timeout:35_000 });
  const bootstrap = await bootstrapPromise;
  if (bootstrap) expect(bootstrap.status()).toBe(200);
  await page.waitForFunction(() => Boolean(
    localStorage.getItem('mgw_device_session_id') && localStorage.getItem('mgw_device_id')
  ), null, { timeout:20_000 });
  return { context, page };
}

async function post(page, path, data){
  return page.evaluate(async ({ path, data }) => {
    const response = await fetch(path, {
      method:'POST',
      headers:{ 'Content-Type':'application/json', Accept:'application/json' },
      body:JSON.stringify({
        ...data,
        initData:'',
        sessionId:localStorage.getItem('mgw_device_session_id'),
        deviceId:localStorage.getItem('mgw_device_id'),
      }),
      cache:'no-store',
    });
    return {
      status:response.status,
      payload:await response.json().catch(() => null),
    };
  }, { path, data });
}

function describeFailure(result){
  return JSON.stringify({
    status:result?.status ?? null,
    error:result?.payload?.error ?? null,
    debug_error:result?.payload?.debug_error ?? null,
    debug_exception:result?.payload?.debug_exception ?? null,
  });
}

test('MVP-21.1 LIVE TOURNAMENT: real API reserves and releases 50,000', async ({ browser }) => {
  await resetPlayers();
  const player = await openPlayer(browser);
  const setupToken = `setup-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  const restoreToken = `restore-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  let registered = false;

  try {
    const setup = await post(player.page, '/bot/api.php', {
      action:'staging_test_tournament_balance',
      targetBalance:100000,
      requestToken:setupToken,
    });
    expect(setup.status, `test balance setup: ${describeFailure(setup)}`).toBe(200);
    expect(setup.payload?.ok, `test balance setup: ${describeFailure(setup)}`).toBe(true);
    expect(Number(setup.payload?.balance?.available_amount ?? -1)).toBe(100000);
    expect(Number(setup.payload?.balance?.reserved_amount ?? -1)).toBe(0);

    const before = await post(player.page, '/bot/tournament-status.php', {});
    expect(before.status, `tournament status before: ${describeFailure(before)}`).toBe(200);
    expect(before.payload?.ok).toBe(true);

    const tournament = before.payload?.snapshot?.tournament || null;
    if (!tournament || tournament.state !== 'registration_open' || tournament.is_full === true) {
      test.skip(true, 'No open official tournament with a free seat is available on staging.');
    }
    if (Number(tournament.remaining_count || 0) <= 1) {
      test.skip(true, 'Live reserve/release smoke requires at least two free seats so it does not auto-close the staging tournament.');
    }

    const rules = tournament.rules || {};
    expect(rules.version, 'tournament rules version').toBeTruthy();
    expect(rules.language, 'tournament rules language').toBeTruthy();
    expect(rules.sha256, 'tournament rules sha256').toMatch(/^[a-f0-9]{64}$/);

    const beforeCount = Number(tournament.registered_count || 0);
    expect(Number(before.payload?.snapshot?.balance?.available_amount ?? -1)).toBe(100000);
    expect(Number(before.payload?.snapshot?.balance?.reserved_amount ?? -1)).toBe(0);

    const register = await post(player.page, '/bot/api.php', {
      action:'tournament_register',
      tournamentRulesAccepted:true,
      tournamentRulesVersion:String(rules.version),
      tournamentRulesLanguage:String(rules.language),
      tournamentRulesSha256:String(rules.sha256),
    });
    console.log('[MGW_TOURNAMENT_REGISTER_LIVE]', describeFailure(register));
    expect(register.status, `tournament_register: ${describeFailure(register)}`).toBe(200);
    expect(register.payload?.ok, `tournament_register: ${describeFailure(register)}`).toBe(true);
    registered = true;

    const registeredSnapshot = register.payload?.snapshot || {};
    expect(registeredSnapshot?.registration?.state).toBe('registered');
    expect(Number(registeredSnapshot?.tournament?.registered_count || 0)).toBe(beforeCount + 1);
    expect(Number(registeredSnapshot?.balance?.available_amount ?? -1)).toBe(50000);
    expect(Number(registeredSnapshot?.balance?.reserved_amount ?? -1)).toBe(50000);
    expect(Number(register.payload?.user?.balance ?? -1)).toBe(50000);

    const confirmed = await post(player.page, '/bot/tournament-status.php', {});
    expect(confirmed.status, `tournament status after register: ${describeFailure(confirmed)}`).toBe(200);
    expect(confirmed.payload?.snapshot?.registration?.state).toBe('registered');
    expect(Number(confirmed.payload?.snapshot?.balance?.available_amount ?? -1)).toBe(50000);
    expect(Number(confirmed.payload?.snapshot?.balance?.reserved_amount ?? -1)).toBe(50000);

    const leave = await post(player.page, '/bot/api.php', { action:'tournament_leave' });
    console.log('[MGW_TOURNAMENT_LEAVE_LIVE]', describeFailure(leave));
    expect(leave.status, `tournament_leave: ${describeFailure(leave)}`).toBe(200);
    expect(leave.payload?.ok, `tournament_leave: ${describeFailure(leave)}`).toBe(true);
    registered = false;

    expect(leave.payload?.snapshot?.registration?.state).toBe('withdrawn');
    expect(Number(leave.payload?.snapshot?.tournament?.registered_count || 0)).toBe(beforeCount);
    expect(Number(leave.payload?.snapshot?.balance?.available_amount ?? -1)).toBe(100000);
    expect(Number(leave.payload?.snapshot?.balance?.reserved_amount ?? -1)).toBe(0);
    expect(Number(leave.payload?.user?.balance ?? -1)).toBe(100000);
  } finally {
    if (registered) {
      const cleanupLeave = await post(player.page, '/bot/api.php', { action:'tournament_leave' }).catch(() => null);
      console.log('[MGW_TOURNAMENT_CLEANUP_LEAVE]', cleanupLeave ? describeFailure(cleanupLeave) : 'transport_failure');
    }

    const restore = await post(player.page, '/bot/api.php', {
      action:'staging_test_tournament_balance',
      targetBalance:100,
      requestToken:restoreToken,
    }).catch(() => null);
    console.log('[MGW_TOURNAMENT_BALANCE_RESTORE]', restore ? describeFailure(restore) : 'transport_failure');
    await player.context.close();
  }
});


test('MVP-21.2 UI BALANCE FREEZE: visible coins change only after verification spinner ends', async ({ browser }) => {
  await resetPlayers();
  const player = await openPlayer(browser);
  const setupToken = `ui-freeze-setup-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  const restoreToken = `ui-freeze-restore-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  let registered = false;
  let releaseVerification = null;
  let verificationGate = null;

  const setVisibleBalance = async value => {
    await player.page.evaluate(next => {
      for (const id of ['balanceUnified', 'topbarBalanceUnified']) {
        const element = document.getElementById(id);
        if (element) element.textContent = String(next);
      }
    }, value);
  };

  const visibleBalance = async id => String(await player.page.locator(`#${id}`).textContent() || '').trim();

  try {
    const setup = await post(player.page, '/bot/api.php', {
      action:'staging_test_tournament_balance',
      targetBalance:100000,
      requestToken:setupToken,
    });
    expect(setup.status, `UI freeze balance setup: ${describeFailure(setup)}`).toBe(200);
    expect(setup.payload?.ok).toBe(true);

    const before = await post(player.page, '/bot/tournament-status.php', {});
    expect(before.status, `UI freeze tournament status: ${describeFailure(before)}`).toBe(200);
    const tournament = before.payload?.snapshot?.tournament || null;
    if (!tournament || tournament.state !== 'registration_open' || tournament.is_full === true) {
      test.skip(true, 'No open official tournament with a free seat is available on staging.');
    }
    if (Number(tournament.remaining_count || 0) <= 1) {
      test.skip(true, 'UI balance freeze requires at least two free seats so it does not auto-close the staging tournament.');
    }

    if (String(before.payload?.snapshot?.registration?.state || '') === 'registered') {
      const cleanup = await post(player.page, '/bot/api.php', { action:'tournament_leave' });
      expect(cleanup.status, `UI freeze pre-cleanup leave: ${describeFailure(cleanup)}`).toBe(200);
    }

    await setVisibleBalance(100000);
    await player.page.locator('[data-shell-nav="tournaments"]').click();
    await expect(player.page.locator('#screen-tournaments')).toHaveClass(/active/, { timeout:20_000 });
    await player.page.locator('[data-competition-mode="tournaments"]').click();

    const action = player.page.locator('[data-tournament-action="register"]');
    await expect(action).toBeVisible({ timeout:20_000 });
    const consent = player.page.locator('[data-tournament-rules-consent]');
    await expect(consent).toBeVisible();
    await consent.check();

    await player.page.route('**/bot/tournament-status.php', async route => {
      if (verificationGate) await verificationGate;
      await route.continue();
    });

    verificationGate = new Promise(resolve => { releaseVerification = resolve; });
    player.page.once('dialog', dialog => dialog.accept());
    await action.click();
    await expect(action).toHaveAttribute('aria-busy', 'true');
    await expect(action).toContainText('Проверяем', { timeout:20_000 });

    // Simulate any unrelated runtime owner learning the already-committed server
    // balance while the tournament verification is still pending. The visible
    // header must remain frozen until the spinner is gone.
    await setVisibleBalance(50000);
    await player.page.waitForTimeout(100);
    expect(await visibleBalance('balanceUnified')).toBe('100000');
    expect(await visibleBalance('topbarBalanceUnified')).toBe('100000');

    releaseVerification?.();
    verificationGate = null;
    registered = true;
    const leaveAction = player.page.locator('[data-tournament-action="leave"]');
    await expect(leaveAction).toBeVisible({ timeout:20_000 });
    await expect(player.page.locator('#balanceUnified')).toHaveText('50000', { timeout:10_000 });
    await expect(player.page.locator('#topbarBalanceUnified')).toHaveText('50000', { timeout:10_000 });

    verificationGate = new Promise(resolve => { releaseVerification = resolve; });
    player.page.once('dialog', dialog => dialog.accept());
    await leaveAction.click();
    await expect(leaveAction).toHaveAttribute('aria-busy', 'true');
    await expect(leaveAction).toContainText('Проверяем', { timeout:20_000 });

    await setVisibleBalance(100000);
    await player.page.waitForTimeout(100);
    expect(await visibleBalance('balanceUnified')).toBe('50000');
    expect(await visibleBalance('topbarBalanceUnified')).toBe('50000');

    releaseVerification?.();
    verificationGate = null;
    registered = false;
    await expect(player.page.locator('[data-tournament-action="register"]')).toBeVisible({ timeout:20_000 });
    await expect(player.page.locator('#balanceUnified')).toHaveText('100000', { timeout:10_000 });
    await expect(player.page.locator('#topbarBalanceUnified')).toHaveText('100000', { timeout:10_000 });

    console.log('[MGW_TOURNAMENT_VISIBLE_BALANCE_FREEZE]', JSON.stringify({ register:'held_until_verified', leave:'held_until_verified' }));
  } finally {
    releaseVerification?.();
    if (registered) {
      const cleanupLeave = await post(player.page, '/bot/api.php', { action:'tournament_leave' }).catch(() => null);
      console.log('[MGW_TOURNAMENT_UI_FREEZE_CLEANUP_LEAVE]', cleanupLeave ? describeFailure(cleanupLeave) : 'transport_failure');
    }
    const restore = await post(player.page, '/bot/api.php', {
      action:'staging_test_tournament_balance',
      targetBalance:100,
      requestToken:restoreToken,
    }).catch(() => null);
    console.log('[MGW_TOURNAMENT_UI_FREEZE_BALANCE_RESTORE]', restore ? describeFailure(restore) : 'transport_failure');
    await player.context.close();
  }
});
