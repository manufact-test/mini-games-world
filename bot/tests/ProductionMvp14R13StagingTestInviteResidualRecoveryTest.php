<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/bot/services/StagingTestInviteResidualRecoveryService.php');
$endpoint = file_get_contents($root . '/bot/staging-test-auth.php');
$repository = file_get_contents($root . '/bot/invites/RuntimeInviteRepository.php');
$notifications = file_get_contents($root . '/bot/notifications/RuntimeNotificationRepository.php');
if (!is_string($service) || !is_string($endpoint) || !is_string($repository) || !is_string($notifications)) {
    throw new RuntimeException('Missing staging invite residual recovery source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($service, "private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com'")
    && str_contains($service, "private const TEST_PLAYER_IDS = ['stg_test_player_a', 'stg_test_player_b']")
    && str_contains($service, "\$environment !== 'staging'")
    && str_contains($service, "\$baseScheme !== 'https'"),
    'Recovery must remain bound to the exact HTTPS staging environment.');

$assert(str_contains($service, 'private const TEST_PLAYER_SAFE_STATUSES')
    && str_contains($service, "'draft', 'pending', 'awaiting_start'")
    && str_contains($service, 'private const TERMINAL_SAFE_STATUSES')
    && str_contains($service, "'declined', 'cancelled', 'expired', 'timed_out'")
    && str_contains($service, "'invite_non_test_nonterminal'")
    && !str_contains($service, "'active',")
    && !str_contains($service, "'starting',"),
    'Only A/B fixtures may recover non-terminal states; every other staging identity must be terminal.');

$assert(str_contains($service, 'private function validateInviteOwnership(')
    && str_contains($service, 'FROM mgw_account_ownership')
    && str_contains($service, "'invite_participant_ownership_invalid'")
    && str_contains($service, "'invite_participant_ownership_mismatch'")
    && str_contains($service, "'ownership_status'")
    && str_contains($service, 'hash_equals($actual[\'account_ref\']')
    && str_contains($service, 'hash_equals($actual[\'mgw_id\']'),
    'Every staging participant must resolve to the exact active normalized ownership row.');

$assert(str_contains($service, "WHERE invite_id = :invite_id OR source_match_id = :source_match_id")
    && str_contains($service, "'invite_id' => \$inviteId")
    && str_contains($service, "'source_match_id' => \$inviteId")
    && str_contains($service, "'invite_attached_to_match'")
    && str_contains($service, "'invite_referenced_by_match'"),
    'Any direct or source-match reference must block deletion using native-safe placeholders.');

$assert(str_contains($service, "'notification_not_invite_participant'")
    && str_contains($service, "'notification_ownership_mismatch'")
    && str_contains($service, "'notification_still_in_json'")
    && str_contains($service, 'isset($ownershipByLegacyId[$legacyUserId])')
    && str_contains($service, 'isset($sourceNotificationIds[$notificationId])'),
    'Only DB-only notifications with exact participant ownership may be removed.');

$notificationDelete = strpos($service, 'DELETE FROM mgw_notifications');
$eventDelete = strpos($service, 'DELETE FROM mgw_invite_events');
$inviteDelete = strpos($service, 'DELETE FROM mgw_invites');
$inviteSync = strpos($service, '->synchronize($snapshot);');
$notificationSync = strpos($service, '->synchronizeAndList($snapshot, $legacyUserId);');
$assert($notificationDelete !== false
    && $eventDelete !== false
    && $inviteDelete !== false
    && $inviteSync !== false
    && $notificationSync !== false
    && $notificationDelete < $eventDelete
    && $eventDelete < $inviteDelete
    && $inviteDelete < $inviteSync
    && $inviteSync < $notificationSync,
    'Dependent rows must be deleted before current JSON invite and scoped notification resynchronization.');

$assert(str_contains($service, '$database->transaction(function (DatabaseConnectionInterface $db)')
    && str_contains($service, 'RuntimeInviteRepository($this->config, $this->router, $db)')
    && str_contains($service, 'RuntimeNotificationRepository($this->config, $this->router, $db)')
    && str_contains($service, '$notificationUserIds[(string)$legacyUserId] = true')
    && str_contains($service, "(\$inviteSync['parity'] ?? false) !== true")
    && str_contains($service, "(\$summary['parity'] ?? false) !== true"),
    'Deletion and global invite/scoped notification parity must share one database transaction.');

$assert(str_contains($service, 'MAX_RESIDUAL_INVITES = 20')
    && str_contains($service, "'test_player_candidate_count'")
    && str_contains($service, "'terminal_staging_candidate_count'")
    && str_contains($service, "'notification_account_count'")
    && str_contains($service, "'production_changed' => false")
    && str_contains($service, "'live_payments_used' => false"),
    'Recovery must remain bounded and publish aggregate safety evidence only.');

$reconcilePosition = strpos($endpoint, "if (\$action === 'reconcile_invite_residuals')");
$verifyPosition = strpos($endpoint, 'verifyAndConsume($providedCredential)', $reconcilePosition ?: 0);
$runPosition = strpos($endpoint, '$result = $residualService()->reconcile($_SERVER);', $reconcilePosition ?: 0);
$assert($reconcilePosition !== false
    && $verifyPosition !== false
    && $runPosition !== false
    && $reconcilePosition < $verifyPosition
    && $verifyPosition < $runPosition
    && str_contains($endpoint, "substr_count(\$providedCredential, '.') !== 2")
    && str_contains($endpoint, "array_key_exists('slot', \$payload)"),
    'The explicit mutation action must accept only a consumed GitHub OIDC token and no arbitrary selector.');

$issuePosition = strpos($endpoint, "if (\$action === 'issue')");
$slotAPosition = strpos($endpoint, "if (\$slot === 'A')", $issuePosition ?: 0);
$issueCallPosition = strpos($endpoint, '$issued = $service->issue(', $issuePosition ?: 0);
$assert($issuePosition !== false
    && $slotAPosition !== false
    && $issueCallPosition !== false
    && $issuePosition < $slotAPosition
    && $slotAPosition < $issueCallPosition,
    'OIDC issuance for player A must reconcile safe residuals before creating a browser session.');

$assert(str_contains($endpoint, "'invite_residual_recovery' => is_array(\$recoveryResult)")
    && str_contains($endpoint, "'candidate_count'")
    && str_contains($endpoint, "'deleted'")
    && str_contains($endpoint, "'parity'")
    && !str_contains($endpoint, "'private_candidates'"),
    'The issue response may expose only the aggregate recovery summary.');

$assert(str_contains($repository, "'Invite JSON and DB counts differ.'")
    && str_contains($repository, "'Invite JSON and DB fingerprints differ.'")
    && str_contains($notifications, 'Notification JSON and DB runtime parity check failed.'),
    'The existing strict JSON/DB parity blockers must remain unchanged.');

$assert(str_contains($service, "external_payments_enabled")
    && substr_count($service, "=== 'live'") >= 1
    && str_contains($service, 'invite-residual-recovery.lock')
    && str_contains($service, '@chmod($path, 0600)')
    && str_contains($service, 'flock($handle, LOCK_EX)')
    && !str_contains($service, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($endpoint, 'lemonchiffon-gerbil-545102.hostingersite.com'),
    'The operation must use a private lock, refuse live payments and contain no production host.');

fwrite(STDOUT, "ProductionMvp14R13StagingTestInviteResidualRecoveryTest: {$assertions} assertions passed\n");
