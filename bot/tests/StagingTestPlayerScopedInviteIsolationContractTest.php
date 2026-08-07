<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$reset = file_get_contents($root . '/bot/services/StagingTestPlayerStateResetService.php');
$auth = file_get_contents($root . '/bot/staging-test-auth.php');
if (!is_string($reset) || !is_string($auth)) {
    throw new RuntimeException('Cannot read staging test isolation sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($reset, "private const TEST_PLAYER_IDS = ['stg_test_player_a', 'stg_test_player_b'];"),
    'Reset must remain scoped to the two staging test identities.');
$assert(str_contains($reset, "throw new RuntimeException('Staging test reset refuses an invite with a non-test player.');"),
    'Reset must refuse mixed real/test invitations instead of deleting them.');
$assert(str_contains($reset, "throw new RuntimeException('Staging test invite cleanup refuses a non-test participant.');"),
    'DB cleanup must independently refuse non-test invite participants.');
$assert(str_contains($reset, "throw new RuntimeException('Staging test invite cleanup refuses a non-test notification.');"),
    'DB cleanup must independently refuse notifications belonging to real users.');
$assert(str_contains($reset, "'open_invites_removed' => count(\$removedInvites)"),
    'Reset must report exact open A/B invite removals.');
$assert(str_contains($reset, "'invite_parity' => (\$inviteCleanup['parity'] ?? false) === true"),
    'Reset must report scoped invite DB parity.');
$assert(str_contains($reset, '->auditParity($snapshot)')
    && str_contains($reset, '->auditParity($snapshot, $legacyUserId)'),
    'Scoped cleanup must prove invite and notification parity with read-only audits.');
$assert(!str_contains($reset, '->synchronizeAndList($snapshot, $legacyUserId)'),
    'Scoped cleanup must not globally synchronize notification state.');

$deleteTransaction = strpos($reset, '$deleted = $database->transaction(');
$inviteAudit = strpos($reset, '$inviteAudit = (new RuntimeInviteRepository');
$assert($deleteTransaction !== false && $inviteAudit !== false && $inviteAudit > $deleteTransaction,
    'Scoped DB delete and invite audit boundaries must exist in order.');
$transactionEnd = strpos($reset, "        });\n\n        // The exact A/B delete transaction", (int)$deleteTransaction);
$assert($transactionEnd !== false && $inviteAudit > $transactionEnd,
    'Invite parity audit must run only after the exact A/B DB delete transaction has committed.');

$issueStart = strpos($auth, "if (\$action === 'issue') {");
$revokeStart = strpos($auth, "if (\$action === 'revoke') {");
$assert($issueStart !== false && $revokeStart !== false && $revokeStart > $issueStart,
    'Issue action boundaries must exist.');
$issue = substr($auth, (int)$issueStart, (int)$revokeStart - (int)$issueStart);
$assert(str_contains($issue, "if (\$slot === 'A')") && str_contains($issue, '$stateResetResult = $playerResetService()->reset($_SERVER);'),
    'Every new Player A scenario must reset scoped A/B runtime state before issuing the session.');
$assert(!str_contains($issue, '$residualService()->reconcile($_SERVER)'),
    'Per-scenario test auth must not run broad real-user residual recovery.');

fwrite(STDOUT, "StagingTestPlayerScopedInviteIsolationContractTest: {$assertions} assertions passed\n");
