const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const TEST_ONLY_INVITE_RECOVERY_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-only-invite-recovery.php`;
const INVITE_MISMATCH_DIAGNOSTIC_ROUTE = `${STAGING_ORIGIN}/bot/staging-invite-mismatch-diagnostic.php`;
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';

async function requestOidcToken(){
  const requestUrl = process.env.ACTIONS_ID_TOKEN_REQUEST_URL || '';
  const requestToken = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN || '';
  if (!requestUrl || !requestToken) {
    throw new Error('GitHub Actions OIDC environment is unavailable for staging reset.');
  }

  const url = new URL(requestUrl);
  url.searchParams.set('audience', OIDC_AUDIENCE);
  const response = await fetch(url, {
    headers:{ Authorization:`bearer ${requestToken}`, Accept:'application/json' },
  });
  if (!response.ok) throw new Error(`GitHub Actions OIDC reset token failed: ${response.status}`);
  const payload = await response.json();
  if (typeof payload?.value !== 'string' || payload.value.split('.').length !== 3) {
    throw new Error('GitHub Actions OIDC reset response did not contain a JWT.');
  }
  return payload.value;
}

async function diagnoseInviteMismatch(){
  const oidcToken = await requestOidcToken();
  const response = await fetch(INVITE_MISMATCH_DIAGNOSTIC_ROUTE, {
    method:'POST',
    headers:{
      Authorization:`Bearer ${oidcToken}`,
      Accept:'application/json',
      'Content-Type':'application/json',
    },
    body:'{}',
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok || payload?.ok !== true || payload?.read_only !== true || !payload?.report) {
    throw new Error(`Staging invite mismatch diagnosis failed: ${response.status} ${payload?.error || 'unknown_error'}`);
  }
  console.log('[MGW_STAGING_INVITE_MISMATCH_DIAGNOSTIC]', JSON.stringify(payload.report));
}

async function recoverTestOnlyInviteOrphans(){
  const oidcToken = await requestOidcToken();
  const response = await fetch(TEST_ONLY_INVITE_RECOVERY_ROUTE, {
    method:'POST',
    headers:{
      Authorization:`Bearer ${oidcToken}`,
      Accept:'application/json',
      'Content-Type':'application/json',
    },
    body:'{}',
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok || payload?.ok !== true || !['recovered', 'already_clean'].includes(payload?.status)) {
    const safeDetail = [payload?.error, payload?.stage, payload?.reason_code]
      .filter((value) => typeof value === 'string' && value !== '')
      .join(' ');
    throw new Error(`Staging test-only invite recovery failed: ${response.status} ${safeDetail || 'unknown_error'}`);
  }
  if (payload?.verification?.candidate_scope !== true
      || payload?.verification?.global_parity_owner !== 'reconcile_invite_residuals') {
    throw new Error('Staging test-only invite recovery did not prove its deletion scope.');
  }
  if (payload.status === 'recovered'
      && ((payload?.candidate_count || 0) < 1 || (payload?.deleted?.invite_rows || 0) < 1)) {
    throw new Error('Staging test-only invite recovery reported no candidate deletion.');
  }
  console.log('[MGW_STAGING_TEST_ONLY_INVITE_RECOVERY]', JSON.stringify({
    status:payload.status,
    candidate_count:payload.candidate_count,
    deleted:payload.deleted,
    verification:payload.verification,
  }));
}

async function resetTestPlayers(){
  const oidcToken = await requestOidcToken();
  const response = await fetch(AUTH_ROUTE, {
    method:'POST',
    headers:{
      Authorization:`Bearer ${oidcToken}`,
      Accept:'application/json',
      'Content-Type':'application/json',
    },
    body:JSON.stringify({ action:'reset_test_players' }),
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok
      || payload?.ok !== true
      || payload?.invite_parity !== true
      || payload?.notification_parity !== true
      || payload?.economy_parity !== true) {
    const stage = typeof payload?.stage === 'string' && payload.stage !== ''
      ? ` stage=${payload.stage}`
      : '';
    throw new Error(`Staging test-player reset failed: ${response.status} ${payload?.error || 'unknown_error'}${stage}`);
  }
  if (payload?.match_balance !== 100 || !Array.isArray(payload?.players) || payload.players.length !== 2) {
    throw new Error('Staging test-player reset returned an unexpected projection.');
  }
  console.log('[MGW_STAGING_TEST_PLAYER_RESET]', JSON.stringify({
    status:payload.status,
    match_balance:payload.match_balance,
    players:payload.players,
    open_invites_removed:payload.open_invites_removed,
    invite_db_rows_removed:payload.invite_db_rows_removed,
    invite_parity:payload.invite_parity,
    notification_parity:payload.notification_parity,
    economy_parity:payload.economy_parity,
  }));
}

export default async function stagingGlobalSetup(){
  // Read-only global diagnostics are useful evidence, but the executable A/B
  // pre-suite owns only the two dedicated technical identities. Real-user
  // residual recovery stays available as an explicit OIDC/admin operation and
  // must never become a prerequisite for issuing or resetting A/B sessions.
  await diagnoseInviteMismatch();
  await recoverTestOnlyInviteOrphans();
  await resetTestPlayers();
}
