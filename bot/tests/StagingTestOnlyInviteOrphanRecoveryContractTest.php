<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/bot/services/StagingTestOnlyInviteOrphanRecoveryService.php');
$endpoint = file_get_contents($root . '/bot/staging-test-only-invite-recovery.php');
$setup = file_get_contents($root . '/e2e/staging-global-setup.mjs');
if (!is_string($service) || !is_string($endpoint) || !is_string($setup)) {
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
    && str_contains($service, "ownership_status")
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
$deletePos = strpos($service, '$deleted = $database->transaction(');
$auditPos = strpos($service, '$inviteAudit = (new RuntimeInviteRepository');
$assert($deletePos !== false && $auditPos !== false && $auditPos > $deletePos,
    'Invite parity audit must run after the delete transaction.');
$assert(str_contains($service, "->auditParity(\$snapshot, \$legacyUserId)")
    && str_contains($service, "'candidate_count' => count(\$candidates)"),
    'Recovery must audit test notifications and report the complete drained candidate count.');
$assert(str_contains($endpoint, 'GitHubActionsOidcVerifier')
    && str_contains($endpoint, "error' => 'test_only_invite_recovery_unavailable'"),
    'Recovery endpoint must remain GitHub-OIDC-only and fail closed.');
$recoverPos = strpos($setup, 'await recoverTestOnlyInviteOrphans();');
$resetPos = strpos($setup, "body:JSON.stringify({ action:'reset_test_players' })");
$assert($recoverPos !== false && $resetPos !== false && $recoverPos < $resetPos,
    'DB-only orphan recovery must complete before the normal A/B reset can drain JSON-backed test state.');

fwrite(STDOUT, "StagingTestOnlyInviteOrphanRecoveryContractTest: {$assertions} assertions passed\n");
