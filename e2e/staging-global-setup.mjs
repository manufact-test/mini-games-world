const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const TEST_ONLY_INVITE_RECOVERY_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-only-invite-recovery.php`;
const FRESH_INVITE_RECOVERY_ROUTE = `${STAGING_ORIGIN}/bot/staging-fresh-invite-recovery.php`;
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
  if (payload.status === 'recovered') {
    if ((payload?.candidate_count || 0) < 1 || (payload?.deleted?.invite_rows || 0) < 1
        || payload?.parity?.invites !== true || payload?.parity?.test_notifications !== true) {
      throw new Error('Staging test-only invite recovery did not prove parity.');
    }
  }
  console.log('[MGW_STAGING_TEST_ONLY_INVITE_RECOVERY]', JSON.stringify({
    status:payload.status,
    candidate_count:payload.candidate_count,
    deleted:payload.deleted,
    parity:payload.parity,
  }));
}

async function recoverFreshInviteReplacement(){
  const oidcToken = await requestOidcToken();
  const response = await fetch(FRESH_INVITE_RECOVERY_ROUTE, {
    method:'POST',
    headers:{
      Authorization:`Bearer ${oidcToken}`,
      Accept:'application/json',
      'Content-Type':'application/json',
    },
    body:'{}',
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok || payload?.ok !== true) {
    throw new Error(`Staging fresh invite replacement recovery failed: ${response.status} ${payload?.error || 'unknown_error'}`);
  }
  if (!['recovered', 'already_clean'].includes(payload.status)) {
    throw new Error('Staging fresh invite replacement recovery returned an unexpected status.');
  }
  if (payload.status === 'recovered') {
    if (payload?.candidate_count !== 1 || payload?.deleted?.invite_rows !== 1 || payload?.parity?.invites !== true) {
      throw new Error('Staging fresh invite replacement recovery did not prove one-row invite parity recovery.');
    }
  }
  console.log('[MGW_STAGING_FRESH_INVITE_REPLACEMENT_RECOVERY]', JSON.stringify({
    status:payload.status,
    candidate_count:payload.candidate_count,
    deleted:payload.deleted,
    parity:payload.parity,
  }));
}

async function diagnoseInviteResiduals(){
  const oidcToken = await requestOidcToken();
  const response = await fetch(AUTH_ROUTE, {
    method:'POST',
    headers:{
      Authorization:`Bearer ${oidcToken}`,
      Accept:'application/json',
      'Content-Type':'application/json',
    },
    body:JSON.stringify({ action:'diagnose_invite_residuals' }),
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok || payload?.ok !== true || payload?.read_only !== true) {
    throw new Error(`Staging invite residual diagnosis failed: ${response.status} ${payload?.error || 'unknown_error'}`);
  }
  console.log('[MGW_STAGING_INVITE_RESIDUAL_DIAGNOSIS]', JSON.stringify({
    status:payload.status,
    recovery_ready:payload.recovery_ready,
    candidate_count:payload.candidate_count,
    test_player_candidate_count:payload.test_player_candidate_count,
    terminal_staging_candidate_count:payload.terminal_staging_candidate_count,
    blocker_codes:Array.isArray(payload.blocker_codes) ? payload.blocker_codes : [],
    production_changed:payload.production_changed,
    live_payments_used:payload.live_payments_used,
  }));
}

async function reconcileInviteResiduals(){
  const oidcToken = await requestOidcToken();
  const response = await fetch(AUTH_ROUTE, {
    method:'POST',
    headers:{
      Authorization:`Bearer ${oidcToken}`,
      Accept:'application/json',
      'Content-Type':'application/json',
    },
    body:JSON.stringify({ action:'reconcile_invite_residuals' }),
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok
      || payload?.ok !== true
      || !['recovered', 'already_clean'].includes(payload?.status)
      || payload?.parity?.invites !== true
      || payload?.parity?.scoped_notifications !== true
      || payload?.parity?.test_player_notifications !== true) {
    throw new Error(`Staging invite residual reconciliation failed: ${response.status} ${payload?.error || 'unknown_error'}`);
  }
  console.log('[MGW_STAGING_INVITE_RESIDUAL_RECONCILIATION]', JSON.stringify({
    status:payload.status,
    candidate_count:payload.candidate_count,
    deleted:payload.deleted,
    parity:payload.parity,
    notification_account_count:payload.notification_account_count,
  }));
}

export default async function stagingGlobalSetup(){
  await diagnoseInviteMismatch();
  await recoverTestOnlyInviteOrphans();
  await recoverFreshInviteReplacement();
  await diagnoseInviteResiduals();
  await reconcileInviteResiduals();

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
  if (!response.ok || payload?.ok !== true || payload?.economy_parity !== true) {
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
    economy_parity:payload.economy_parity,
  }));
}
