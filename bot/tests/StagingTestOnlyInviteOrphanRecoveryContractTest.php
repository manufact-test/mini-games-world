<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/bot/services/StagingTestOnlyInviteOrphanRecoveryService.php');
$residual = file_get_contents($root . '/bot/services/StagingTestInviteResidualRecoveryService.php');
$endpoint = file_get_contents($root . '/bot/staging-test-only-invite-recovery.php');
$setup = file_get_contents($root . '/e2e/staging-global-setup.mjs');
if (!is_string($service) || !is_string($residual) || !is_string($endpoint) || !is_string($setup)) {
    throw new RuntimeException('Cannot read staging test-only recovery sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($service, "private const TEST_PLAYER_IDS = ['stg_test_player_a', 'stg_test_player_b'];"),
    'Recovery must remain hard-scoped to the two staging test identities.');
$assert(str_contains($service, "private const SAFE_SOURCES = ['direct', 'link'];"),
    'Recovery must not clean rematch or unrelated invite sources.');
$assert(str_contains($service, "if (isset(\$sourceInviteIds[\$inviteId]) || isset(\$sourceInviteTokens[\$token])) continue;"),
    'JSON-backed invites must never become DB-only recovery candidates.');
$assert(str_contains($service, "if (\$matchId !== '' || \$matchRefs !== 0) {")
    && str_contains($service, 'Runtime invite parity intentionally'),
    'Match-referenced A/B history must be preserved, not recovered as an orphan.');
$assert(str_contains($service, "throw new RuntimeException('Staging test-only orphan recovery found unsafe A/B invite state.');"),
    'Unsafe status/source combinations must fail closed.');
$assert(str_contains($service, '$this->assertOwnership($database, $invite, $participants);')
    && str_contains($service, 'ownership_status')
    && str_contains($service, 'hash_equals'),
    'Every candidate must prove current A/B ownership before mutation.');
$assert(str_contains($service, "throw new RuntimeException('Staging test-only orphan recovery refuses non-test notification state.');"),
    'Recovery must refuse linked notifications outside A/B.');
$assert(str_contains($service, "throw new RuntimeException('Staging test-only orphan recovery refuses JSON-backed notification state.');"),
    'Recovery must refuse linked notifications still represented in JSON.');
$assert(!str_contains($service, 'MAX_CANDIDATES')
    && !str_contains($service, 'refuses excessive candidates')
    && !str_contains($service, 'candidate count is excessive'),
    'Candidate cardinality must not deadlock cleanup after every candidate independently passes strict safety proof.');
$assert(str_contains($service, '$deleted = $database->transaction(')
    && str_contains($service, 'foreach ($candidates as $candidate)'),
    'All strictly proved candidates must drain inside one DB transaction.');
$assert(str_contains($service, 'candidate deletion verification failed')
    && str_contains($service, 'event deletion verification failed')
    && str_contains($service, 'notification deletion verification failed'),
    'Orphan recovery must verify its exact deletion scope after mutation.');
$assert(str_contains($service, "'candidate_scope' => true")
    && str_contains($service, "'global_parity_owner' => 'reconcile_invite_residuals'"),
    'Orphan recovery must publish its scoped verification and leave global parity to the explicit admin owner.');
$assert(!str_contains($service, '->auditParity('),
    'A/B orphan recovery must not duplicate global residual parity.');
$assert(str_contains($residual, '->synchronize($snapshot)')
    && str_contains($residual, '->synchronizeAndList($snapshot, $legacyUserId)'),
    'Explicit residual reconciliation must remain available as the global parity owner.');
$assert(str_contains($endpoint, 'GitHubActionsOidcVerifier')
    && str_contains($endpoint, "error' => 'test_only_invite_recovery_unavailable'"),
    'Recovery endpoint must remain GitHub-OIDC-only and fail closed.');
$assert(str_contains($setup, "payload?.verification?.candidate_scope !== true"),
    'Global setup must require scoped orphan verification.');
$assert(!str_contains($setup, 'recoverFreshInviteReplacement()')
    && !str_contains($setup, 'diagnoseInviteResiduals()')
    && !str_contains($setup, 'reconcileInviteResiduals()')
    && !str_contains($setup, "action:'reconcile_invite_residuals'"),
    'Normal A/B Playwright setup must not mutate or block on unrelated real-user residual history.');
$recoverPos = strpos($setup, 'await recoverTestOnlyInviteOrphans();');
$resetPos = strpos($setup, 'await resetTestPlayers();');
$assert($recoverPos !== false && $resetPos !== false && $recoverPos < $resetPos,
    'Setup order must be A/B orphan cleanup followed directly by A/B state reset.');

fwrite(STDOUT, "StagingTestOnlyInviteOrphanRecoveryContractTest: {$assertions} assertions passed\n");
