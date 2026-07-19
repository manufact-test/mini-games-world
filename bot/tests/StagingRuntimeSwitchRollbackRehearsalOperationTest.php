<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/operations/StagingOperationDefinition.php';
require $root . '/operations/StagingRuntimeSwitchRollbackRehearsalOperation.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $value, string $message) use (&$assertions): void {
    $assertions++;
    if (!$value) throw new RuntimeException($message);
};

$directory = sys_get_temp_dir() . '/mgw-switch-rehearsal-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not create switch rehearsal test directory.');
}

$snapshot = [
    'users' => [],
    'games' => [],
    'queue' => [],
    'invites' => [],
    'notifications' => [],
    'transactions' => [],
    'shop_orders' => [],
    'payments' => [],
];
$storage = new class($snapshot) implements StorageAdapterInterface {
    public function __construct(private array $snapshot) {}
    public function driver(): string { return 'json'; }
    public function transaction(callable $callback): mixed { return $callback($this->snapshot); }
    public function readOnly(callable $callback): mixed { return $callback($this->snapshot); }
};
$database = new class implements DatabaseConnectionInterface {
    public function driver(): string { return 'sqlite'; }
    public function execute(string $sql, array $parameters = []): int { return 0; }
    public function fetchAll(string $sql, array $parameters = []): array { return []; }
    public function fetchValue(string $sql, array $parameters = []): mixed { return 0; }
    public function transaction(callable $callback): mixed { return $callback($this); }
};

$modules = [
    'accounts' => true,
    'realtime' => true,
    'invites' => true,
    'notifications' => true,
    'economy' => true,
    'history' => true,
    'shop' => true,
    'payments' => true,
    'weekly_bonus' => true,
];
$runtime = [
    'database_runtime' => [
        'enabled' => true,
        'modules' => $modules,
    ],
    'features' => ['matchmaking' => true],
];
$config = [
    'environment' => 'staging',
    'storage_driver' => 'json',
    'data_dir' => $directory,
    'database' => [
        'enabled' => true,
        'driver' => 'sqlite',
        'path' => ':memory:',
    ],
    'feature_flags' => $runtime,
];
$runtimeFile = $directory . '/runtime.php';
file_put_contents(
    $runtimeFile,
    "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($runtime, true) . ";\n",
    LOCK_EX
);
chmod($runtimeFile, 0600);
$originalBytes = (string)file_get_contents($runtimeFile);

$operation = new StagingRuntimeSwitchRollbackRehearsalOperation(
    $config,
    $storage,
    $database,
    $directory
);
$definition = $operation->definition();
$assertSame('mvp-14.8.4k-switch-rollback-rehearsal-v1', $definition->id(), 'Operation ID must be immutable');
$assertSame('v101-mvp14-db-switch-rollback-rehearsal', $definition->build(), 'Operation must bind to exact build');

$reflect = new ReflectionClass($operation);
$invoke = static function (string $method, array $arguments = []) use ($reflect, $operation): mixed {
    $reflection = $reflect->getMethod($method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($operation, $arguments);
};

$invoke('createBackup');
$backupFile = $directory . '/switch-rollback-rehearsal.runtime.backup';
$assertTrue(is_file($backupFile), 'Runtime backup must be created outside the deployed project');
$assertSame($originalBytes, (string)file_get_contents($backupFile), 'Runtime backup must preserve exact bytes');

$rollbackRuntime = $runtime;
$rollbackRuntime['database_runtime']['enabled'] = false;
$invoke('writeRuntime', [$rollbackRuntime]);
$disabled = $invoke('readRuntime');
$assertSame(false, $disabled['database_runtime']['enabled'], 'Rehearsal must be able to disable DB routing');

$rollbackConfig = $invoke('configWithRuntime', [$disabled]);
$router = new RuntimeStorageRouter($rollbackConfig);
$assertSame(false, $router->enabled(), 'Rollback config must disable the DB runtime router');
foreach (array_keys($modules) as $module) {
    $assertSame('json', $router->routeFor($module), 'Every module must route to JSON during rollback');
}

$assertSame(true, $invoke('restoreBackup', [false]), 'Runtime backup must restore successfully');
$restoredBytes = (string)file_get_contents($runtimeFile);
$assertSame($originalBytes, $restoredBytes, 'Runtime restore must be byte-exact');
$restored = $invoke('readRuntime');
$restoredConfig = $invoke('configWithRuntime', [$restored]);
$restoredRouter = new RuntimeStorageRouter($restoredConfig);
$assertSame(true, $restoredRouter->enabled(), 'DB runtime router must be enabled after restore');
$assertSame(array_keys($modules), $restoredRouter->enabledModules(), 'All nine DB modules must be restored');

@unlink($backupFile);
@unlink($runtimeFile);
@rmdir($directory);

fwrite(STDOUT, "StagingRuntimeSwitchRollbackRehearsalOperationTest passed: {$assertions} assertions.\n");
