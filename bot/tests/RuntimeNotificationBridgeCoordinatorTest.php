<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/database/PdoConnectionFactory.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/realtime/RealtimeDatabaseStore.php';
require $root . '/notifications/RuntimeNotificationRepository.php';
require $root . '/notifications/RuntimeNotificationBridgeCoordinator.php';

final class NotificationBridgeTestConnection implements DatabaseConnectionInterface
{
    public array $queries = [];
    public bool $transactionStarted = false;
    public bool $transactionCommitted = false;
    public bool $transactionRolledBack = false;

    public function driver(): string
    {
        return 'mysql';
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $this->queries[] = $sql;
        throw new RuntimeException('Unexpected execute in notification bridge test.');
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $this->queries[] = $sql;
        if (str_contains($sql, 'FROM mgw_account_ownership')) {
            return [[
                'account_ref' => 'legacy:1002',
                'mgw_id' => 'MGW-NOTIFY0000001',
                'legacy_user_id' => '1002',
                'ownership_status' => 'active',
            ]];
        }
        if (str_contains($sql, 'FROM mgw_notifications')) return [];
        return [];
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        $this->queries[] = $sql;
        if (str_contains($sql, 'GET_LOCK')) return 1;
        if (str_contains($sql, 'RELEASE_LOCK')) return 1;
        return null;
    }

    public function transaction(callable $callback): mixed
    {
        $this->transactionStarted = true;
        try {
            $result = $callback($this);
            $this->transactionCommitted = true;
            return $result;
        } catch (Throwable $error) {
            $this->transactionRolledBack = true;
            throw $error;
        }
    }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertContains = static function (string $needle, array $haystack, string $message) use (&$assertions): void {
    $assertions++;
    foreach ($haystack as $item) {
        if (str_contains((string)$item, $needle)) return;
    }
    throw new RuntimeException($message . ': missing ' . $needle);
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains($error->getMessage(), $contains)) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

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

$successConnection = new NotificationBridgeTestConnection();
$successCoordinator = new RuntimeNotificationBridgeCoordinator(
    $config,
    new RuntimeStorageRouter($config),
    $successConnection
);
$success = $successCoordinator->synchronizeAndList(
    ['notifications' => []],
    '1002',
    'MGW-NOTIFY0000001'
);
$assertSame(true, $successConnection->transactionStarted, 'Successful sync must start one DB transaction');
$assertSame(true, $successConnection->transactionCommitted, 'Successful sync must commit the DB transaction');
$assertSame(false, $successConnection->transactionRolledBack, 'Successful sync must not roll back');
$assertSame(true, $success['summary']['parity'] ?? false, 'Successful empty sync must prove parity');
$assertContains('GET_LOCK', $successConnection->queries, 'Successful sync must acquire the MySQL advisory lock');
$assertContains('RELEASE_LOCK', $successConnection->queries, 'Successful sync must release the MySQL advisory lock');

$failureConnection = new NotificationBridgeTestConnection();
$failureCoordinator = new RuntimeNotificationBridgeCoordinator(
    $config,
    new RuntimeStorageRouter($config),
    $failureConnection
);
$assertThrows(
    static fn() => $failureCoordinator->synchronizeAndList(
        ['notifications' => [[
            'user_id' => '1002',
            'type' => 'invite_cancelled',
            'created_at' => '2026-08-05T18:00:00+00:00',
        ]]],
        '1002',
        'MGW-NOTIFY0000001'
    ),
    'stable ID or event key',
    'Malformed notification source must fail closed inside the transaction'
);
$assertSame(true, $failureConnection->transactionStarted, 'Failed sync must start one DB transaction');
$assertSame(false, $failureConnection->transactionCommitted, 'Failed sync must not commit');
$assertSame(true, $failureConnection->transactionRolledBack, 'Failed sync must roll back');
$assertContains('GET_LOCK', $failureConnection->queries, 'Failed sync must acquire the MySQL advisory lock');
$assertContains('RELEASE_LOCK', $failureConnection->queries, 'Failed sync must release the advisory lock in finally');

fwrite(STDOUT, "RuntimeNotificationBridgeCoordinatorTest: {$assertions} assertions passed\n");
