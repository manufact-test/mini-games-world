from pathlib import Path

setup_path = Path('e2e/staging-global-setup.mjs')
text = setup_path.read_text(encoding='utf-8')

if 'async function reconcileInviteResiduals(){' not in text:
    anchor = """async function recoverFreshInviteReplacement(){\n"""
    start = text.find(anchor)
    if start < 0:
        raise SystemExit('Fresh recovery function anchor not found.')
    export_anchor = "\nexport default async function stagingGlobalSetup(){"
    end = text.find(export_anchor, start)
    if end < 0:
        raise SystemExit('Global setup export anchor not found.')
    addition = r'''

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
'''
    text = text[:end] + addition + text[end:]

call_anchor = """  await recoverFreshInviteReplacement();\n\n  const oidcToken = await requestOidcToken();\n"""
call_replacement = """  await recoverFreshInviteReplacement();\n  await reconcileInviteResiduals();\n\n  const oidcToken = await requestOidcToken();\n"""
if call_anchor in text:
    text = text.replace(call_anchor, call_replacement, 1)
elif '  await reconcileInviteResiduals();' not in text:
    raise SystemExit('Global setup recovery call anchor not found.')

setup_path.write_text(text, encoding='utf-8')

contract = Path('bot/tests/StagingGlobalSetupResidualReconcileContractTest.php')
contract.write_text(r'''<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$setup = file_get_contents($root . '/e2e/staging-global-setup.mjs');
$endpoint = file_get_contents($root . '/bot/staging-test-auth.php');
$service = file_get_contents($root . '/bot/services/StagingTestInviteResidualRecoveryService.php');
if (!is_string($setup) || !is_string($endpoint) || !is_string($service)) {
    throw new RuntimeException('Cannot read staging residual reconciliation sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($setup, 'async function reconcileInviteResiduals(){')
    && str_contains($setup, "body:JSON.stringify({ action:'reconcile_invite_residuals' })"),
    'Global setup must call the existing residual reconciliation action.');
$assert(str_contains($setup, 'payload?.parity?.invites !== true')
    && str_contains($setup, 'payload?.parity?.scoped_notifications !== true')
    && str_contains($setup, 'payload?.parity?.test_player_notifications !== true'),
    'Global setup must require authoritative residual parity before reset.');

$fresh = strpos($setup, 'await recoverFreshInviteReplacement();');
$residual = strpos($setup, 'await reconcileInviteResiduals();');
$reset = strpos($setup, "body:JSON.stringify({ action:'reset_test_players' })");
$assert($fresh !== false && $residual !== false && $reset !== false
    && $fresh < $residual && $residual < $reset,
    'Residual reconciliation must run after narrow recovery and before test-player reset.');

$action = strpos($endpoint, "if (\$action === 'reconcile_invite_residuals')");
$verify = strpos($endpoint, 'verifyAndConsume($providedCredential)', $action ?: 0);
$run = strpos($endpoint, '$result = $residualService()->reconcile($_SERVER);', $action ?: 0);
$assert($action !== false && $verify !== false && $run !== false
    && $action < $verify && $verify < $run,
    'Residual reconciliation endpoint must remain GitHub-OIDC gated.');

$assert(str_contains($service, 'RuntimeInviteRepository($this->config, $this->router, $db)')
    && str_contains($service, '->synchronize($snapshot);')
    && str_contains($service, '($inviteSync[\'parity\'] ?? false) !== true'),
    'Existing residual owner must use RuntimeInviteRepository synchronization and prove parity.');
$assert(str_contains($service, '$database->transaction(function (DatabaseConnectionInterface $db)')
    && str_contains($service, 'flock($handle, LOCK_EX)')
    && str_contains($service, "'production_changed' => false")
    && str_contains($service, "'live_payments_used' => false"),
    'Residual reconciliation must retain its transaction, lock and staging safety boundary.');
$assert(!str_contains($setup, 'private_candidates')
    && !str_contains($setup, 'blocker_codes'),
    'Global setup must not log private residual candidate details.');

fwrite(STDOUT, "StagingGlobalSetupResidualReconcileContractTest: {$assertions} assertions passed\n");
''', encoding='utf-8')

print('Residual reconciliation wiring patch applied.')
