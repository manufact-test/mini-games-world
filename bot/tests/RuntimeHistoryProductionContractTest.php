<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/services/HistoryService.php';
require $root . '/history/RuntimeHistoryRepository.php';

if (!class_exists('UserService')) {
    final class UserService {}
}

final class RuntimeHistoryProductionContractConnection implements DatabaseConnectionInterface
{
    public function driver(): string
    {
        return 'mysql';
    }

    public function execute(string $sql, array $parameters = []): int
    {
        throw new RuntimeException('Production history contract test must remain read-only.');
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        return [];
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        return null;
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this);
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

$modules = array_fill_keys([
    'accounts',
    'realtime',
    'invites',
    'notifications',
    'economy',
    'history',
    'shop',
    'payments',
    'weekly_bonus',
], true);

$config = [
    'environment' => 'production',
    'storage_driver' => 'json',
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'test',
        'user' => 'test',
        'password' => 'test-password',
        'charset' => 'utf8mb4',
    ],
    'feature_flags' => [
        'database_runtime' => [
            'enabled' => true,
            'production_activated' => true,
            'activation_build' => RuntimeStorageRouter::PRODUCTION_ACTIVATION_BUILD,
            'activation_plan_fingerprint' => str_repeat('a', 64),
            'activation_source_fingerprint' => str_repeat('b', 64),
            'activated_at_utc' => '2026-07-24T18:24:08+00:00',
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'modules' => $modules,
        ],
    ],
];

$router = new RuntimeStorageRouter($config);
$status = $router->publicStatus();
$assertSame(true, $status['production_allowed'], 'Exact production activation must pass the router contract');
$assertSame($modules !== [], $router->enabledModules() !== [], 'All protected production modules must be enabled');

$repository = new RuntimeHistoryRepository(
    $config,
    $router,
    new RuntimeHistoryProductionContractConnection(),
    new HistoryService($config, new UserService())
);
$report = $repository->auditParity([
    'users' => [],
    'transactions' => [],
    'games' => [],
]);

$assertSame(true, $report['ok'], 'Protected production history parity audit must be allowed');
$assertSame([], $report['blockers'], 'Protected production history parity audit must have no blockers');
$assertSame(0, $report['source_user_count'], 'Empty protected production history audit must stay empty');
$assertSame(false, $report['production_changed'], 'Production history parity audit must remain read-only');

$invalid = $config;
$invalid['feature_flags']['database_runtime']['activation_build'] = 'wrong-build';
$blocked = false;
try {
    new RuntimeStorageRouter($invalid);
} catch (RuntimeException $error) {
    $blocked = str_contains($error->getMessage(), 'exact protected activation contract');
}
$assertSame(true, $blocked, 'Incomplete production activation must remain fail-closed');

fwrite(STDOUT, "RuntimeHistoryProductionContractTest passed: {$assertions} assertions.\n");
