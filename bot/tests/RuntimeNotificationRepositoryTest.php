<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/PdoConnectionFactory.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/database/MigrationRepository.php';
require $root . '/database/MigrationRunner.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/realtime/RealtimeDatabaseStore.php';
require $root . '/notifications/RuntimeNotificationRepository.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('RuntimeNotificationRepositoryTest requires pdo_sqlite.');
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($contains))) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $root . '/database/migrations');
$assertSame(7, $runner->migrate(false)['executed_count'], 'Notification runtime test must apply all migrations');

$legacyUserId = '972585905';
$mgwId = 'MGW-NOTIFYTEST00001';
$accountRef = 'legacy:' . $legacyUserId;
$now = '2026-07-18 17:30:00.000000';
$database->execute(
    'INSERT INTO mgw_users (
        mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
     ) VALUES (
        :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
     )',
    [
        'mgw_id' => $mgwId,
        'status' => 'active',
        'display_name' => 'Notification Test',
        'created_at' => $now,
        'updated_at' => $now,
        'last_seen_at' => $now,
    ]
);
$database->execute(
    'INSERT INTO mgw_account_ownership (
        account_ref, mgw_id, legacy_user_id, ownership_status,
        source_type, source_ref, source_sha256, created_at_utc, verified_at_utc
     ) VALUES (
        :account_ref, :mgw_id, :legacy_user_id, :ownership_status,
        :source_type, :source_ref, :source_sha256, :created_at, :verified_at
     )',
    [
        'account_ref' => $accountRef,
        'mgw_id' => $mgwId,
        'legacy_user_id' => $legacyUserId,
        'ownership_status' => 'active',
        'source_type' => 'legacy_json',
        'source_ref' => 'users.json:' . $legacyUserId,
        'source_sha256' => hash('sha256', 'notification-owner'),
        'created_at' => $now,
        'verified_at' => $now,
    ]
);

$config = [
    'environment' => 'staging',
    'storage_driver' => 'json',
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'mgw_test',
        'user' => 'mgw_test',
        'password' => 'test-password',
        'charset' => 'utf8mb4',
    ],
    'feature_flags' => [
        'database_runtime' => [
            'enabled' => true,
            'modules' => [
                'accounts' => true,
                'notifications' => true,
            ],
        ],
    ],
];
$repository = new RuntimeNotificationRepository(
    $config,
    new RuntimeStorageRouter($config),
    $database
);

$data = [
    'notifications' => [[
        'id' => 'notification-runtime-1',
        'event_key' => 'runtime:test:1',
        'user_id' => $legacyUserId,
        'type' => 'system_notice',
        'title' => 'Проверка уведомлений',
        'message' => 'DB runtime сохраняет текущий контракт.',
        'tone' => 'info',
        'invite_token' => '',
        'created_at' => '2026-07-18T17:30:00+00:00',
        'read_at' => null,
    ]],
];

$first = $repository->synchronizeAndList($data, $legacyUserId, $mgwId);
$assertSame(1, $first['summary']['source_count'], 'One JSON notification must be synchronized');
$assertSame(1, $first['summary']['database_count'], 'One DB notification must exist');
$assertSame(1, $first['summary']['created_count'], 'First sync must create one DB notification');
$assertSame(true, $first['summary']['parity'], 'First sync must prove parity');
$assertSame('notification-runtime-1', $first['items'][0]['id'] ?? null, 'DB reader must preserve notification ID');
$assertSame(false, !empty($first['items'][0]['read_at']), 'Fresh notification must remain unread');

$repeat = $repository->synchronizeAndList($data, $legacyUserId, $mgwId);
$assertSame(0, $repeat['summary']['created_count'], 'Repeat sync must create nothing');
$assertSame(1, $repeat['summary']['unchanged_count'], 'Repeat sync must verify one unchanged notification');
$assertSame(
    $repeat['summary']['source_fingerprint'],
    $repeat['summary']['database_fingerprint'],
    'JSON and DB fingerprints must match'
);

$data['notifications'][0]['read_at'] = '2026-07-18T17:31:00+00:00';
$read = $repository->synchronizeAndList($data, $legacyUserId, $mgwId);
$assertTrue(!empty($read['items'][0]['read_at']), 'JSON read state must propagate to DB');
$assertSame(
    '2026-07-18 17:31:00.000000',
    (string)$database->fetchValue(
        'SELECT read_at_utc FROM mgw_notifications WHERE notification_id = :notification_id',
        ['notification_id' => 'notification-runtime-1']
    ),
    'DB read timestamp must match the JSON rollback source'
);

$conflict = $data;
$conflict['notifications'][0]['title'] = 'Conflicting title';
$assertThrows(
    static fn() => $repository->synchronizeAndList($conflict, $legacyUserId, $mgwId),
    'conflicts with JSON rollback source',
    'Notification content drift must fail closed'
);

$wrongIdentity = 'MGW-DIFFERENT000001';
$assertThrows(
    static fn() => $repository->synchronizeAndList($data, $legacyUserId, $wrongIdentity),
    'does not match notification ownership',
    'Authenticated account mismatch must fail closed'
);

fwrite(STDOUT, "RuntimeNotificationRepositoryTest: {$assertions} assertions passed\n");
