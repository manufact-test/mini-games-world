<?php
declare(strict_types=1);

interface DatabaseConnectionInterface {}
final class RuntimePrimaryRepositoryFactoryTestDatabase implements DatabaseConnectionInterface {}
final class DatabaseConfig
{
    private function __construct(private bool $enabled) {}
    public static function fromApplicationConfig(array $config): self
    {
        return new self((bool)($config['database']['enabled'] ?? false));
    }
    public function enabled(): bool { return $this->enabled; }
}
final class RuntimeStorageRouter
{
    public const DRIVER_JSON = 'json';
    public const DRIVER_DATABASE = 'database';
    private const MODULES = [
        'accounts', 'realtime', 'invites', 'notifications', 'economy',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];
    public function __construct(private array $config) {}
    public function enabled(): bool
    {
        return (bool)($this->config['feature_flags']['database_runtime']['enabled'] ?? false);
    }
    public function enabledModules(): array
    {
        $enabled = [];
        foreach (self::MODULES as $module) {
            if (!empty($this->config['feature_flags']['database_runtime']['modules'][$module])) $enabled[] = $module;
        }
        return $enabled;
    }
}
interface RuntimePrimaryProjectionProjectorInterface
{
    public function project(array $snapshot, int $stateRevision, string $stateSha256): array;
}
interface RuntimePrimaryModuleProjectorInterface
{
    public function module(): string;
    public function project(array $snapshot, int $stateRevision, string $stateSha256): array;
    public function audit(array $snapshot, int $stateRevision, string $stateSha256): array;
}
final class RuntimePrimaryAccountsModuleProjector implements RuntimePrimaryModuleProjectorInterface
{
    public function __construct(DatabaseConnectionInterface $database) {}
    public function module(): string { return 'accounts'; }
    public function project(array $snapshot, int $stateRevision, string $stateSha256): array { return []; }
    public function audit(array $snapshot, int $stateRevision, string $stateSha256): array { return []; }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryCallbackModuleProjector.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryAllModuleProjector.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryRepositoryProjectorFactory.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};

$database = new RuntimePrimaryRepositoryFactoryTestDatabase();
$config = [
    'environment' => 'staging',
    'storage_driver' => 'json',
    'database' => ['enabled' => true],
    'feature_flags' => [],
];
$factory = new RuntimePrimaryRepositoryProjectorFactory($config, $database);
$projector = $factory->create();
$assertTrue($projector instanceof RuntimePrimaryAllModuleProjector, 'Factory must create the strict all-module projector');

$reflection = new ReflectionClass($projector);
$property = $reflection->getProperty('projectors');
$property->setAccessible(true);
$projectors = $property->getValue($projector);
$expected = [
    'accounts', 'realtime', 'economy', 'notifications', 'invites',
    'history', 'shop', 'payments', 'weekly_bonus',
];
$assertTrue(array_keys($projectors) === $expected, 'Factory must preserve the exact dependency order');
foreach ($expected as $module) {
    $assertTrue(
        $projectors[$module] instanceof RuntimePrimaryModuleProjectorInterface,
        'Factory module must implement the projector contract: ' . $module
    );
}

$assertThrows(
    static fn() => new RuntimePrimaryRepositoryProjectorFactory([
        'environment' => 'production',
        'database' => ['enabled' => true],
    ], $database),
    'staging/local-only'
);
$assertThrows(
    static fn() => new RuntimePrimaryRepositoryProjectorFactory([
        'environment' => 'staging',
        'database' => ['enabled' => false],
    ], $database),
    'enabled database'
);

fwrite(STDOUT, "RuntimePrimaryRepositoryProjectorFactoryTest passed: {$assertions} assertions.\n");
