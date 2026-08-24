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
$assertSame('Альфа хочет добавить вас в друзья.', $requestEvent['message'] ?? null, 'Friend request bell copy must name the canonical actor');

$service->publish('request', $requester, $recipient, [
    'changed' => true,
    'event_at' => '2026-08-24 12:00:00.000000',
]);
$assertSame(1, count($storage->data['notifications']), 'Retrying the same social event must not duplicate the bell row');

$acceptedEvent = $service->publish('accept', $recipient, $requester, [
    'changed' => true,
    'event_at' => '2026-08-24 12:00:05.000000',
]);
$assertSame('101', $acceptedEvent['user_id'] ?? null, 'Acceptance bell event must return to the original requester');
$assertSame('friend_accepted', $acceptedEvent['type'] ?? null, 'Acceptance must use the canonical social bell type');
$assertSame('Ваша заявка принята игроком «Браво».', $acceptedEvent['message'] ?? null, 'Acceptance bell copy must name the accepting player');
$assertSame(2, count($storage->data['notifications']), 'Request and acceptance must create exactly two distinct bell events');

$assertSame(null, $service->publish('request', $requester, $recipient, ['changed' => false]), 'Idempotent graph responses must not emit a second social event');

fwrite(STDOUT, 'PASS: social friend notification pipeline (' . $assertions . " assertions)\n");
