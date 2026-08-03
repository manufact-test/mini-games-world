<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/bot/services/StagingTestInviteResidualRecoveryService.php');
$endpoint = file_get_contents($root . '/bot/staging-test-auth.php');
$repository = file_get_contents($root . '/bot/invites/RuntimeInviteRepository.php');
$notifications = file_get_contents($root . '/bot/notifications/RuntimeNotificationRepository.php');
if (!is_string($service) || !is_string($endpoint) || !is_string($repository) || !is_string($notifications)) {
    throw new RuntimeException('Missing staging test invite residual recovery source.');
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
    'Recovery must be bound to the exact HTTPS staging environment and fixed A/B identities.');

$assert(str_contains($service, "routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE")
    && str_contains($service, "routeFor('notifications') !== RuntimeStorageRouter::DRIVER_DATABASE")
    && str_contains($service, "routeFor('invites') !== RuntimeStorageRouter::DRIVER_DATABASE"),
    'Recovery must require all normalized ownership, notification and invite routes.');

$assert(str_contains($service, "'draft'")
    && str_contains($service, "'pending'")
    && str_contains($service, "'awaiting_start'")
    && str_contains($service, "'declined'")
    && str_contains($service, "'cancelled'")
    && str_contains($service, "'expired'")
    && str_contains($service, "'timed_out'")
    && !str_contains($service, "'active',")
    && !str_contains($service, "'starting',"),
    'Only non-active residual invitation statuses may be recovered.');

$assert(str_contains($service, '$participants !== $expectedParticipants')
    && str_contains($service, "'invite_not_test_players'")
    && str_contains($service, 'MAX_RESIDUAL_INVITES = 20'),
    'Every DB-only candidate must contain exactly A and B and the batch must remain bounded.');

$assert(str_contains($service, "WHERE invite_id = :invite_id OR source_match_id = :source_match_id")
    && str_contains($service, "'invite_id' => \$inviteId")
    && str_contains($service, "'source_match_id' => \$inviteId")
    && str_contains($service, '$matchCount !== 0'),
    'A candidate with any direct or source-match reference must remain blocked using native-safe placeholders.');

$assert(str_contains($service, '$idPresent xor $tokenPresent')
    && str_contains($service, 'isset($sourceNotificationIds[$notificationId])')
    && str_contains($service, "'notification_still_in_json'"),
    'Partial invite identity and any notification still present in JSON must block deletion.');

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
    'Dependent test rows must be deleted before current JSON invites and A/B notifications are resynchronized.');

$assert(str_contains($service, '$database->transaction(function (DatabaseConnectionInterface $db)')
    && str_contains($service, 'RuntimeInviteRepository($this->config, $this->router, $db)')
    && str_contains($service, 'RuntimeNotificationRepository($this->config, $this->router, $db)')
    && str_contains($service, "(\$inviteSync['parity'] ?? false) !== true")
    && str_contains($service, "(\$summary['parity'] ?? false) !== true"),
    'Deletion and exact invite/notification resynchronization must share one database transaction.');

$assert(str_contains($service, "'production_changed' => false")
    && str_contains($service, "'live_payments_used' => false")
    && str_contains($service, "'candidate_count'")
    && str_contains($service, "'invite_rows'")
    && str_contains($service, "'notification_rows'")
    && str_contains($service, "'invite_event_rows'")
    && str_contains($service, "'notification_counts'"),
    'The public service result must contain aggregate recovery and safety evidence.');

$assert(str_contains($service, "external_payments_enabled")
    && substr_count($service, "=== 'live'") >= 1
    && str_contains($service, 'invite-residual-recovery.lock')
    && str_contains($service, '@chmod($path, 0600)')
    && str_contains($service, 'flock($handle, LOCK_EX)'),
    'Live payments must remain disabled and concurrent recovery must use a private lock.');

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
    'The explicit mutation action must accept only a consumed GitHub OIDC token and no arbitrary slot.');

$issuePosition = strpos($endpoint, "if (\$action === 'issue')");
$slotAPosition = strpos($endpoint, "if (\$slot === 'A')", $issuePosition ?: 0);
$issueCallPosition = strpos($endpoint, '$issued = $service->issue(', $issuePosition ?: 0);
$assert($issuePosition !== false
    && $slotAPosition !== false
    && $issueCallPosition !== false
    && $issuePosition < $slotAPosition
    && $slotAPosition < $issueCallPosition,
    'A consumed OIDC request for player A must reconcile residuals before issuing the browser session.');

$assert(str_contains($endpoint, "'invite_residual_recovery' => is_array(\$recoveryResult)")
    && str_contains($endpoint, "'candidate_count'")
    && str_contains($endpoint, "'deleted'")
    && str_contains($endpoint, "'parity'")
    && !str_contains($endpoint, "'private_candidates'"),
    'The issue response may expose only the safe aggregate recovery summary.');

$assert(str_contains($repository, "'Invite JSON and DB counts differ.'")
    && str_contains($repository, "'Invite JSON and DB fingerprints differ.'")
    && str_contains($notifications, 'Notification JSON and DB runtime parity check failed.'),
    'The existing strict JSON/DB parity blockers must remain unchanged.');

$assert(!str_contains($service, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($service, 'mini-games-world.com')
    && !str_contains($endpoint, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($endpoint, 'mini-games-world.com'),
    'The staging recovery path must contain no production hostname.');

fwrite(STDOUT, "ProductionMvp14R13StagingTestInviteResidualRecoveryTest: {$assertions} assertions passed\n");
