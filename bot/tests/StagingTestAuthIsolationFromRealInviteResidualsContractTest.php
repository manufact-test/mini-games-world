<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$auth = file_get_contents($root . '/bot/staging-test-auth.php');
$setup = file_get_contents($root . '/e2e/staging-global-setup.mjs');
$reset = file_get_contents($root . '/bot/services/StagingTestPlayerStateResetService.php');
$residual = file_get_contents($root . '/bot/services/StagingTestInviteResidualRecoveryService.php');
$fresh = file_get_contents($root . '/bot/services/StagingFreshInviteReplacementRecoveryService.php');
if (!is_string($auth) || !is_string($setup) || !is_string($reset)
    || !is_string($residual) || !is_string($fresh)) {
    throw new RuntimeException('Cannot read staging A/B isolation sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$issueStart = strpos($auth, "if (\$action === 'issue') {");
$revokeStart = strpos($auth, "if (\$action === 'revoke') {");
$assert($issueStart !== false && $revokeStart !== false && $revokeStart > $issueStart,
    'Issue action boundaries are unavailable.');
$issueBlock = substr($auth, (int)$issueStart, (int)$revokeStart - (int)$issueStart);

$assert(!str_contains($issueBlock, '$residualService()->reconcile($_SERVER)'),
    'Issuing an A/B test session must not reconcile or depend on real-user invite residuals.');
$assert(str_contains($auth, "if (\$action === 'reconcile_invite_residuals')")
    && str_contains($auth, '$result = $residualService()->reconcile($_SERVER);'),
    'Explicit OIDC real-user residual recovery must remain available separately.');
$assert(str_contains($issueBlock, "'invite_residual_recovery' => null"),
    'Test session projection must state that per-issue residual recovery did not run.');

$assert(str_contains($fresh, 'continue 2;')
    && str_contains($fresh, 'in_array($participantId, self::TEST_PLAYER_IDS, true)'),
    'Fresh replacement recovery explicitly excludes A/B and therefore must not be a normal A/B pre-suite dependency.');
$assert(!str_contains($setup, 'FRESH_INVITE_RECOVERY_ROUTE')
    && !str_contains($setup, 'recoverFreshInviteReplacement')
    && !str_contains($setup, 'reconcileInviteResiduals')
    && !str_contains($setup, "action:'reconcile_invite_residuals'"),
    'Normal A/B pre-suite must not run real-user residual recovery.');
$assert(str_contains($setup, 'await diagnoseInviteMismatch();')
    && str_contains($setup, 'await recoverTestOnlyInviteOrphans();')
    && str_contains($setup, 'await resetTestPlayers();'),
    'A/B pre-suite may keep read-only evidence and A/B-only cleanup/reset.');

$assert(str_contains($reset, 'private function assertTestInviteParity('),
    'A/B reset must own a scoped invite parity check.');
$assert(!str_contains($reset, 'private function assertInviteParity(')
    && !str_contains($reset, '->auditParity($snapshot);'),
    'A/B reset must not require global invite parity across unrelated real staging users.');
$assert(str_contains($reset, "throw new RuntimeException('Staging test invite cleanup did not restore A/B invite parity.');"),
    'A/B reset must still fail closed if its own invite scope is not clean.');
$assert(str_contains($reset, "SELECT COUNT(*) FROM mgw_matches WHERE invite_id = :invite_id OR source_match_id = :source_match_id"),
    'A/B scoped parity must preserve match-referenced historical invite rows.');
$assert(str_contains($reset, "Staging test invite parity refuses an unmatched mixed A/B DB invite"),
    'A/B scoped parity must refuse unmatched mixed test/real invite state.');

$assert(str_contains($residual, 'MAX_RESIDUAL_INVITES')
    && str_contains($residual, 'notification_still_in_json'),
    'Separate real-user residual recovery must retain its conservative safety guards.');

fwrite(STDOUT, "StagingTestAuthIsolationFromRealInviteResidualsContractTest: {$assertions} assertions passed\n");
