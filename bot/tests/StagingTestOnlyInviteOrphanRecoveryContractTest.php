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
$assert(str_contains($service, "throw new RuntimeException('Staging test-only orphan recovery refuses match-referenced A/B invite.');"),
    'Recovery must refuse match-referenced invites.');
$assert(str_contains($service, "throw new RuntimeException('Staging test-only orphan recovery refuses non-test notification state.');"),
    'Recovery must refuse linked notifications outside A/B.');
$assert(str_contains($service, '$deleted = $database->transaction(')
    && str_contains($service, '$inviteAudit = (new RuntimeInviteRepository'),
    'Recovery must delete in one DB transaction and audit afterwards.');
$deletePos = strpos($service, '$deleted = $database->transaction(');
$auditPos = strpos($service, '$inviteAudit = (new RuntimeInviteRepository');
$assert($deletePos !== false && $auditPos !== false && $auditPos > $deletePos,
    'Invite audit must be after the delete transaction.');
$assert(str_contains($endpoint, 'GitHubActionsOidcVerifier')
    && str_contains($endpoint, "error' => 'test_only_invite_recovery_unavailable'"),
    'Recovery endpoint must remain GitHub-OIDC-only and fail closed.');
$assert(str_contains($setup, 'await recoverTestOnlyInviteOrphans();')
    && strpos($setup, 'await recoverTestOnlyInviteOrphans();') < strpos($setup, 'body:JSON.stringify({ action:\'reset_test_players\' })'),
    'A/B DB-only orphan recovery must run before the normal test-player reset.');

fwrite(STDOUT, "StagingTestOnlyInviteOrphanRecoveryContractTest: {$assertions} assertions passed\n");
