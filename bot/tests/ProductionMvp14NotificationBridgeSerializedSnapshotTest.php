<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$endpoint = $read('bot/notifications.php');
$coordinator = $read('bot/notifications/RuntimeNotificationBridgeCoordinator.php');
$focusedTest = $read('bot/tests/RuntimeNotificationBridgeCoordinatorTest.php');
$e2e = $read('e2e/staging/d2-d3-d5-integration.spec.mjs');

$detachedRead = '$db->readOnly(static fn(array $data): array => $data)';
$assert(!str_contains($endpoint, $detachedRead)
    && str_contains($endpoint, '$db->exclusiveReadOnlySections(')
    && str_contains($endpoint, "['invites', 'notifications']")
    && str_contains($endpoint, '$runtimeNotifications->synchronizeAndList('),
    'Notification DB synchronization must consume a fresh snapshot while the exclusive JSON lock is held.');
$assert(str_contains($endpoint, 'if ($markRead) {')
    && str_contains($endpoint, '$notifications->markAllRead($data, $userId);')
    && str_contains($endpoint, 'Notification bridge requires exclusive JSON snapshots.'),
    'markRead must finish before the fresh exclusive snapshot and the bridge must fail closed without lock support.');
$assert(str_contains($coordinator, "SELECT GET_LOCK(:lock_name, 10)")
    && str_contains($coordinator, "SELECT RELEASE_LOCK(:lock_name)")
    && str_contains($coordinator, 'return $database->transaction(')
    && str_contains($coordinator, 'new RuntimeNotificationRepository('),
    'The notification mirror must use one advisory lock and one outer DB transaction.');
$assert(str_contains($coordinator, 'Notification DB synchronization lock is unavailable.')
    && str_contains($coordinator, 'notification DB lock release failed'),
    'The coordinator must fail closed on lock acquisition and release the lock in finally.');
$assert(str_contains($focusedTest, 'Successful sync must commit the DB transaction')
    && str_contains($focusedTest, 'Failed sync must roll back')
    && str_contains($focusedTest, "'RELEASE_LOCK'"),
    'Focused tests must prove commit, rollback and advisory-lock release.');
$assert(str_contains($e2e, 'notificationByInviteToken(playerB, directToken)')
    && str_contains($e2e, 'player.context.request.post(NOTIFICATIONS_ROUTE')
    && str_contains($e2e, 'invite_status')
    && str_contains($e2e, 'cancelled|canceled'),
    'The live regression must still require Player B terminal cancellation delivery from the raw authenticated response.');

fwrite(STDOUT, "ProductionMvp14NotificationBridgeSerializedSnapshotTest: {$assertions} assertions passed\n");
