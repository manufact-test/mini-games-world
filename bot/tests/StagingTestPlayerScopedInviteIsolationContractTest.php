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

$assert(
    str_contains($reset, "private const TEST_PLAYER_IDS = ['stg_test_player_a', 'stg_test_player_b'];"),
    'Reset must remain scoped to the two staging test identities.'
);
$assert(
    str_contains($reset, "throw new RuntimeException('Staging test reset refuses an invite with a non-test player.');"),
    'Reset must refuse mixed real/test invitations instead of deleting them.'
);
$assert(
    str_contains($reset, "return !isset(\$testIds[(string)(\$notification['user_id'] ?? '')]);"),
    'The explicit pre-suite reset must remove all historical notifications for A/B only.'
);
$assert(
    str_contains($reset, 'private function cleanupRuntimeTestNotificationRows(array $snapshot): array')
        && str_contains($reset, 'WHERE legacy_user_id = :legacy_user_id')
        && str_contains($reset, 'AND recipient_ref = :recipient_ref')
        && str_contains($reset, 'AND mgw_id = :mgw_id'),
    'DB notification cleanup must be exact-scoped to technical ownership.'
);
$assert(
    str_contains($reset, "throw new RuntimeException('Staging test notification cleanup ownership mismatch.');"),
    'DB cleanup must refuse rows whose ownership does not exactly match the A/B account.'
);
$assert(
    str_contains($reset, '$notificationCleanup = $this->cleanupRuntimeTestNotificationRows($snapshot);')
        && str_contains($reset, '$inviteCleanup = $this->cleanupRuntimeInviteRows($snapshot, $removedInvites);')
        && strpos($reset, '$notificationCleanup = $this->cleanupRuntimeTestNotificationRows($snapshot);')
            < strpos($reset, '$inviteCleanup = $this->cleanupRuntimeInviteRows($snapshot, $removedInvites);'),
    'A/B notification DB cleanup must commit before invite parity cleanup runs.'
);
$deleteTransaction = strpos($reset, '$deleted = $database->transaction(function (DatabaseConnectionInterface $db) use ($ownership): int');
$notificationAudit = strpos($reset, '->auditParity($snapshot, $legacyUserId);');
$assert(
    $deleteTransaction !== false && $notificationAudit !== false && $notificationAudit > $deleteTransaction,
    'Notification parity audit must run only after the exact A/B delete transaction.'
);
$assert(
    str_contains($reset, "'notification_parity' => (\$notificationCleanup['parity'] ?? false) === true"),
    'Reset must report proven technical notification parity.'
);
$assert(
    !str_contains($reset, '->synchronizeAndList($snapshot, $legacyUserId)'),
    'Technical cleanup must use read-only parity audits rather than global notification synchronization.'
);

$resetActionStart = strpos($auth, "if (\$action === 'reset_test_players') {");
$issueStart = strpos($auth, "if (\$action === 'issue') {");
$revokeStart = strpos($auth, "if (\$action === 'revoke') {");
$assert(
    $resetActionStart !== false && $issueStart !== false && $revokeStart !== false
        && $resetActionStart < $issueStart && $issueStart < $revokeStart,
    'Staging auth action boundaries must exist in the expected order.'
);
$resetAction = substr($auth, (int)$resetActionStart, (int)$issueStart - (int)$resetActionStart);
$issue = substr($auth, (int)$issueStart, (int)$revokeStart - (int)$issueStart);
$assert(
    str_contains($resetAction, '$result = $playerResetService()->reset($_SERVER);'),
    'GitHub-OIDC reset_test_players must remain the sole test-state reset owner.'
);
$assert(
    !str_contains($issue, '$playerResetService()->reset($_SERVER)')
        && !str_contains($issue, '$residualService()->reconcile($_SERVER)'),
    'Per-session issue must never reset A/B state or run broad residual recovery.'
);

fwrite(STDOUT, "StagingTestPlayerScopedInviteIsolationContractTest: {$assertions} assertions passed\n");
