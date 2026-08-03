import { test, expect } from '@playwright/test';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;

async function requestOidcToken() {
  const requestUrl = process.env.ACTIONS_ID_TOKEN_REQUEST_URL || '';
  const requestToken = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN || '';
  if (!requestUrl || !requestToken) {
    throw new Error('GitHub Actions OIDC environment is unavailable.');
  }

  const url = new URL(requestUrl);
  url.searchParams.set('audience', OIDC_AUDIENCE);
  const response = await fetch(url, {
    headers: {
      Authorization: `bearer ${requestToken}`,
      Accept: 'application/json',
    },
  });
  if (!response.ok) {
    throw new Error(`GitHub Actions OIDC request failed with status ${response.status}.`);
  }
  const payload = await response.json();
  if (typeof payload?.value !== 'string' || payload.value.split('.').length !== 3) {
    throw new Error('GitHub Actions OIDC response did not contain a JWT.');
  }
  return payload.value;
}

function assertSafeReport(value) {
  const serialized = JSON.stringify(value);
  for (const forbidden of [
    'invite_id', 'notification_id', 'event_key', 'invite_token',
    'legacy_user_id', 'account_ref', 'mgw_id', 'payload_json',
    'database_identity', 'setup_secret', 'staging_test_auth_secret',
  ]) {
    expect(serialized).not.toContain(forbidden);
  }
}

test('OIDC runner reads only aggregate staging invite residual diagnosis', async ({ request }) => {
  const oidcToken = await requestOidcToken();
  const response = await request.post(AUTH_ROUTE, {
    headers: {
      Authorization: `Bearer ${oidcToken}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    data: { action: 'diagnose_invite_residuals' },
    timeout: 35_000,
  });

  expect(response.status(), 'Safe residual diagnosis HTTP status').toBe(200);
  const payload = await response.json();
  expect(payload).toMatchObject({
    ok: true,
    service: 'mini-games-world-staging-test-invite-residual-diagnosis',
    read_only: true,
    authorization_mode: 'github_actions_oidc',
    production_changed: false,
    live_payments_used: false,
  });
  expect(['already_clean', 'recoverable', 'blocked']).toContain(payload.status);
  expect(typeof payload.recovery_ready).toBe('boolean');
  expect(Number.isInteger(payload.candidate_count)).toBe(true);
  expect(payload.candidate_count).toBeGreaterThanOrEqual(0);
  expect(Array.isArray(payload.blocker_codes)).toBe(true);
  for (const code of payload.blocker_codes) {
    expect(code).toMatch(/^[a-z0-9_]{3,64}$/);
  }
  assertSafeReport(payload);

  console.log(`[MGW_SAFE_INVITE_RESIDUAL_DIAGNOSIS] ${JSON.stringify({
    status: payload.status,
    recovery_ready: payload.recovery_ready,
    candidate_count: payload.candidate_count,
    blocker_codes: payload.blocker_codes,
  })}`);
});
