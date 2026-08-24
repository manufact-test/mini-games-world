<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/database/DatabaseConnectionInterface.php';
require_once $root . '/database/PdoDatabaseConnection.php';
require_once $root . '/storage/contracts/StorageTransactionInterface.php';
require_once $root . '/helpers/validators.php';
require_once $root . '/notifications/NotificationCenterV2Policy.php';
require_once $root . '/services/NotificationService.php';
require_once $root . '/social/SocialFriendNotificationService.php';

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDOUT, "SKIP: pdo_sqlite is unavailable\n");
    exit(0);
}

final class SocialNotificationMemoryStorage implements StorageTransactionInterface
{
    public array $data = ['notifications' => []];

    public function transaction(callable $callback): mixed
    {
        return $callback($this->data);
    }

    public function readOnly(callable $callback): mixed
    {
        return $callback($this->data);
    }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database = new PdoDatabaseConnection($pdo);
$database->execute('CREATE TABLE mgw_users (mgw_id TEXT PRIMARY KEY, status TEXT NOT NULL, nickname TEXT NOT NULL)');
$database->execute('CREATE TABLE mgw_account_ownership (mgw_id TEXT PRIMARY KEY, legacy_user_id TEXT, ownership_status TEXT NOT NULL)');
$database->execute('CREATE TABLE mgw_identities (mgw_id TEXT NOT NULL, provider TEXT NOT NULL, provider_subject TEXT NOT NULL)');
$database->execute("CREATE TABLE mgw_social_relations (
    user_low_mgw_id TEXT NOT NULL,
    user_high_mgw_id TEXT NOT NULL,
    friend_status TEXT NOT NULL,
    requested_by_mgw_id TEXT NULL,
    blocked_by_low INTEGER NOT NULL DEFAULT 0,
    blocked_by_high INTEGER NOT NULL DEFAULT 0,
    friend_requested_at_utc TEXT NULL
)");

$requester = 'MGW-000000000000000A';
$recipient = 'MGW-000000000000000B';
foreach ([[$requester, 'Альфа', '101'], [$recipient, 'Браво', '202']] as [$mgwId, $nickname, $legacyId]) {
    $database->execute(
        'INSERT INTO mgw_users (mgw_id, status, nickname) VALUES (:mgw_id, :status, :nickname)',
        ['mgw_id' => $mgwId, 'status' => 'active', 'nickname' => $nickname]
    );
    $database->execute(
        'INSERT INTO mgw_account_ownership (mgw_id, legacy_user_id, ownership_status) VALUES (:mgw_id, :legacy_user_id, :status)',
        ['mgw_id' => $mgwId, 'legacy_user_id' => $legacyId, 'status' => 'active']
    );
}

$storage = new SocialNotificationMemoryStorage();
$service = new SocialFriendNotificationService($database, $storage);
$requestEvent = $service->publish('request', $requester, $recipient, [
    'changed' => true,
    'event_at' => '2026-08-24 12:00:00.000000',
]);
$assertSame('202', $requestEvent['user_id'] ?? null, 'Friend request bell event must resolve the target legacy notification identity');
$assertSame('friend_request', $requestEvent['type'] ?? null, 'Friend request must use the canonical social bell type');
$assertSame('Альфа хочет добавить вас в друзья. Откройте заявку, чтобы посмотреть профиль и принять или отклонить её.', $requestEvent['message'] ?? null, 'Friend request bell copy must explain the review flow');
$assertSame('friends:requests', $requestEvent['deep_link'] ?? null, 'Friend request bell event must deep-link to the canonical request review tab');
$database->execute(
    "INSERT INTO mgw_social_relations (
        user_low_mgw_id, user_high_mgw_id, friend_status, requested_by_mgw_id,
        blocked_by_low, blocked_by_high, friend_requested_at_utc
     ) VALUES (:low, :high, 'pending', :requested_by, 0, 0, :requested_at)",
    [
        'low' => $requester,
        'high' => $recipient,
        'requested_by' => $requester,
        'requested_at' => '2026-08-24 12:00:00.000000',
    ]
);
$service->synchronizeResolvedForRecipient($recipient);
$assertSame(null, $storage->data['notifications'][0]['hidden_at'] ?? null, 'The exact active request notification must remain visible');

$notificationService = new NotificationService();
$storage->transaction(function (array &$data) use ($notificationService, $requester, $recipient): void {
    $notificationService->addFriendRequest(
        $data,
        '202',
        $requester,
        $recipient,
        'Альфа',
        '2026-08-24 11:00:00.000000'
    );
});
$service->synchronizeResolvedForRecipient($recipient);
$assertSame(false, empty($storage->data['notifications'][1]['hidden_at']), 'An older duplicate request card must be hidden while only the current request stays actionable');

$service->publish('request', $requester, $recipient, [
    'changed' => true,
    'event_at' => '2026-08-24 12:00:00.000000',
]);
$assertSame(2, count($storage->data['notifications']), 'Retrying the same social event must not duplicate either the current or historical audit row');

$database->execute(
    "UPDATE mgw_social_relations
     SET friend_status = 'friends', requested_by_mgw_id = NULL, friend_requested_at_utc = NULL
     WHERE user_low_mgw_id = :low AND user_high_mgw_id = :high",
    ['low' => $requester, 'high' => $recipient]
);

$acceptedEvent = $service->publish('accept', $recipient, $requester, [
    'changed' => true,
    'event_at' => '2026-08-24 12:00:05.000000',
]);
$assertSame('101', $acceptedEvent['user_id'] ?? null, 'Acceptance bell event must return to the original requester');
$assertSame('friend_accepted', $acceptedEvent['type'] ?? null, 'Acceptance must use the canonical social bell type');
$assertSame('Ваша заявка принята игроком «Браво».', $acceptedEvent['message'] ?? null, 'Acceptance bell copy must name the accepting player');
$assertSame(false, empty($storage->data['notifications'][0]['hidden_at']), 'Accepted friend request card must disappear from the recipient bell feed');
$assertSame(false, empty($storage->data['notifications'][0]['read_at']), 'Resolved friend request card must no longer count as unread');
$assertSame(3, count($storage->data['notifications']), 'Current request, historical duplicate and acceptance remain auditable without exposing stale cards');

$assertSame(null, $service->publish('request', $requester, $recipient, ['changed' => false]), 'Idempotent graph responses must not emit a second social event');

fwrite(STDOUT, 'PASS: social friend notification pipeline (' . $assertions . " assertions)\n");
