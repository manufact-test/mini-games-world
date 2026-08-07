const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const FRESH_INVITE_RECOVERY_ROUTE = `${STAGING_ORIGIN}/bot/staging-fresh-invite-recovery.php`;
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

export default async function stagingGlobalSetup(){
  await recoverFreshInviteReplacement();

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
    throw new Error(`Staging test-player reset failed: ${response.status} ${payload?.error || 'unknown_error'}`);
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
