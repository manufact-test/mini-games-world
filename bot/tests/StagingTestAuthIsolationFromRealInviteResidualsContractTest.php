<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$auth = file_get_contents($root . '/bot/staging-test-auth.php');
$authService = file_get_contents($root . '/bot/services/StagingTestAuthService.php');
$bootstrap = file_get_contents($root . '/bot/services/StagingTestPlayerBootstrapService.php');
$setup = file_get_contents($root . '/e2e/staging-global-setup.mjs');
$reset = file_get_contents($root . '/bot/services/StagingTestPlayerStateResetService.php');
$residual = file_get_contents($root . '/bot/services/StagingTestInviteResidualRecoveryService.php');
$fresh = file_get_contents($root . '/bot/services/StagingFreshInviteReplacementRecoveryService.php');
if (!is_string($auth) || !is_string($authService) || !is_string($bootstrap)
    || !is_string($setup) || !is_string($reset)
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

$assert(str_contains($authService, 'public static function playerDefinitions(): array')
    && str_contains($authService, "'stg_test_player_a'")
    && str_contains($authService, "'stg_test_player_b'"),
    'The authorization owner must expose one canonical A/B identity catalog for technical bootstrap.');
$assert(str_contains($bootstrap, 'final class StagingTestPlayerBootstrapService')
    && str_contains($bootstrap, 'StagingTestAuthService::playerDefinitions()')
    && str_contains($bootstrap, 'new UserService($this->config)'),
    'Missing technical A/B users must be recreated through the canonical auth identities and normal UserService initialization.');
$assert(str_contains($bootstrap, "if (array_key_exists(\$legacyUserId, \$data['users']))")
    && str_contains($bootstrap, '$createdSlots[] = $slot;')
    && str_contains($bootstrap, '$existingSlots[] = $slot;'),
    'Bootstrap must create only missing A/B runtime users and leave existing A/B identities intact.');
$assert(str_contains($bootstrap, "Staging test player bootstrap refuses live payments")
    && str_contains($bootstrap, "self::STAGING_HOST"),
    'A/B bootstrap must remain exact-staging-only and fail closed when live payments are enabled.');
$assert(str_contains($auth, 'new StagingTestPlayerBootstrapService($config)')
    && strpos($auth, '$bootstrap = $playerBootstrapService()->ensure($_SERVER);') !== false
    && strpos($auth, '$result = $playerResetService()->reset($_SERVER);') !== false
    && strpos($auth, '$bootstrap = $playerBootstrapService()->ensure($_SERVER);')
        < strpos($auth, '$result = $playerResetService()->reset($_SERVER);'),
    'Reset endpoint must rehydrate missing A/B identities before running the A/B state reset.');
$assert(str_contains($auth, "'test_player_bootstrap' => \$bootstrap"),
    'Reset response must expose bounded bootstrap evidence.');

$assert(str_contains($reset, 'private function assertTestInviteParity('),
    'A/B reset must own a scoped invite parity check.');
$assert(!str_contains($reset, 'private function assertInviteParity(')
    && !str_contains($reset, 'new RuntimeInviteRepository('),
    'A/B reset must not require the global invite repository parity audit across unrelated real staging users.');
$assert(!str_contains($reset, 'UnifiedBalanceRuntimeState::migrateAll($data)')
    && str_contains($reset, "UnifiedBalanceRuntimeState::ensureUser(\$data['users'][\$legacyUserId]);"),
    'A/B reset must migrate/validate unified balance only for the two technical identities, never every real staging user.');
$assert(str_contains($reset, 'new RuntimeNotificationRepository(')
    && str_contains($reset, 'new RuntimeEconomyRepository('),
    'A/B-scoped notification parity and economy parity must remain enforced.');
$assert(str_contains($reset, "throw new RuntimeException('Staging test invite cleanup did not restore A/B invite parity.');"),
    'A/B reset must still fail closed if its own invite scope is not clean.');
$assert(str_contains($reset, "SELECT COUNT(*) FROM mgw_matches WHERE invite_id = :invite_id OR source_match_id = :source_match_id"),
    'A/B scoped parity must preserve match-referenced historical invite rows.');
$assert(str_contains($reset, "Staging test invite parity refuses an unmatched mixed A/B DB invite"),
    'A/B scoped parity must refuse unmatched mixed test/real invite state.');

$assert(str_contains($reset, "\$isStarted = \$status === 'active';")
    && str_contains($reset, 'private function startedTestInviteCanRetire('),
    'A/B reset must handle already-started test invites through a dedicated guarded retirement path.');
$assert(str_contains($reset, "if (\$linkedGame === null) {")
    && str_contains($reset, "if (\$gameStatus !== 'finished') {")
    && str_contains($reset, "in_array(\$gameStatus, ['active', 'waiting'], true)"),
    'Started A/B invites may retire only when the linked game is gone or proven finished, never while a game is live.');
$assert(str_contains($reset, "Staging test reset refuses an active invite linked to a non-test game"),
    'Started-invite retirement must fail closed on mixed/non-test linked game ownership.');
$assert(str_contains($reset, '$retiredStartedInvites++')
    && str_contains($reset, "'retired_started_invites' => \$retiredStartedInvites"),
    'Started A/B invite retirement must be explicit and observable.');
$assert(str_contains($reset, '$this->cleanupRuntimeInviteRows($snapshot, $removedInvites)')
    && !str_contains($reset, 'cleanupRuntimeInviteRows($snapshot, $retiredStartedInvites)'),
    'Started historical A/B invites must never enter unmatched DB invite deletion.');

$assert(str_contains($auth, '$resetReasonCode = static function (StagingTestPlayerResetStageException $error): string')
    && str_contains($auth, "'reason_code' => \$reasonCode")
    && str_contains($auth, "default => \$error->stage() . '_unclassified'"),
    'Reset failures must expose only a bounded machine-readable reason code, never raw internal exception text.');
$assert(!str_contains($auth, "'reason' => \$error->getPrevious()")
    && !str_contains($auth, "'message' => \$error->getPrevious()"),
    'Reset endpoint must not expose raw internal exception details.');
$assert(str_contains($setup, '[payload?.error, payload?.stage, payload?.reason_code]'),
    'Staging E2E must surface the safe reset reason code when setup fails.');

$assert(str_contains($residual, 'MAX_RESIDUAL_INVITES')
    && str_contains($residual, 'notification_still_in_json'),
    'Separate real-user residual recovery must retain its conservative safety guards.');

fwrite(STDOUT, "StagingTestAuthIsolationFromRealInviteResidualsContractTest: {$assertions} assertions passed\n");
