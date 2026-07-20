<?php
declare(strict_types=1);

if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $offset, ?int $length = null): string
    {
        return $length === null ? substr($value, $offset) : substr($value, $offset, $length);
    }
}

interface StorageAdapterInterface
{
    public function driver(): string;
    public function readOnly(callable $callback): mixed;
    public function transaction(callable $callback): mixed;
}
interface DatabaseConnectionInterface {}
final class BackupManager {}
final class ProductionCutoverConfig
{
    public function __construct(private bool $enabled = false, private bool $approved = false) {}
    public function enabled(): bool { return $this->enabled; }
    public function assertApproved(string $build, string $fingerprint, int $now): void
    {
        if (!$this->approved) throw new RuntimeException('approval missing');
    }
    public function requirePrimaryBackup(): bool { return true; }
    public function requireExternalCopy(): bool { return true; }
    public function safeSummary(): array { return ['enabled' => $this->enabled]; }
}
final class FeatureFlagService
{
    public const BUILD = 'v103-mvp14-production-cutover';
    public function __construct(array $config) {}
}
final class RuntimeStorageRouter
{
    public const DRIVER_JSON = 'json';
    public const DRIVER_DATABASE = 'database';
    public function __construct(private array $config) {}
    public function enabled(): bool
    {
        return (bool)($this->config['feature_flags']['database_runtime']['enabled'] ?? false);
    }
    public function enabledModules(): array { return []; }
}
final class MigrationRunner
{
    public function __construct(mixed ...$arguments) {}
    public function status(): array { return ['pending_count' => 0]; }
}
final class ProductionPreflightRunner
{
    public function __construct(mixed ...$arguments) {}
    public function run(): array
    {
        return ['technical_ready_for_window' => false, 'blockers' => ['test blocker']];
    }
}
final class ProductionCutoverRunnerStateTestStorage implements StorageAdapterInterface
{
    public function driver(): string { return 'json'; }
    public function readOnly(callable $callback): mixed { return $callback([]); }
    public function transaction(callable $callback): mixed
    {
        $data = [];
        return $callback($data);
    }
}
final class ProductionCutoverRunnerStateTestDatabase implements DatabaseConnectionInterface {}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/cutover/ProductionCutoverRunner.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$makeFixture = static function () use ($projectRoot): array {
    $root = sys_get_temp_dir() . '/mgw-production-cutover-state-' . bin2hex(random_bytes(6));
    $private = $root . '/private';
    $data = $root . '/data';
    mkdir($private, 0700, true);
    mkdir($data, 0700, true);
    $configFile = $private . '/config.php';
    file_put_contents($configFile, "<?php\nreturn [];\n");
    return [$root, $private, $data, $configFile];
};
$removeFixture = static function (string $path) use (&$removeFixture): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $path . '/' . $name;
        if (is_dir($child)) $removeFixture($child);
        else @unlink($child);
    }
    @rmdir($path);
};
$runner = static function (
    string $data,
    string $configFile,
    ProductionCutoverConfig $policy,
    bool $executionDependencies = true
) use ($projectRoot): ProductionCutoverRunner {
    return new ProductionCutoverRunner(
        $projectRoot,
        ['environment' => 'production', 'data_dir' => $data, 'feature_flags' => []],
        $configFile,
        $executionDependencies ? new ProductionCutoverRunnerStateTestStorage() : null,
        $executionDependencies ? new ProductionCutoverRunnerStateTestDatabase() : null,
        $executionDependencies ? new BackupManager() : null,
        $policy,
        strtotime('2026-07-20T10:00:00+00:00')
    );
};

[$fixture, $private, $data, $configFile] = $makeFixture();
try {
    $subject = $runner($data, $configFile, new ProductionCutoverConfig(false, false));
    $blocked = $subject->run();
    $assertTrue(($blocked['action'] ?? '') === 'cutover_blocked', 'Pre-mutation gate failure must be blocked, not rolled back');
    $assertTrue(($blocked['production_changed'] ?? true) === false, 'Pre-mutation gate failure must report no production change');
    $assertTrue(!is_file($private . '/production-cutover.json'), 'Pre-mutation gate failure must not create terminal state');

    $emergencyControl = $runner($data, $configFile, new ProductionCutoverConfig(false, false), false);
    $statusWithoutDatabase = $emergencyControl->status();
    $assertTrue(($statusWithoutDatabase['action'] ?? '') === 'status', 'Status must work without production DB dependencies');
    $assertTrue(($statusWithoutDatabase['ok'] ?? false) === true, 'Status without production DB dependencies must remain healthy');

    file_put_contents($private . '/runtime.php', "<?php\nthrow new RuntimeException('broken runtime');\n");
    $invalidRuntimeStatus = $emergencyControl->status();
    $assertTrue(($invalidRuntimeStatus['action'] ?? '') === 'status', 'Malformed runtime must still return a structured status report');
    $assertTrue(($invalidRuntimeStatus['ok'] ?? true) === false, 'Malformed runtime status must fail closed');
    $assertTrue(($invalidRuntimeStatus['runtime_error'] ?? '') !== '', 'Malformed runtime status must expose a safe runtime error');
    unlink($private . '/runtime.php');

    $rollbackNoop = $emergencyControl->rollback();
    $assertTrue(($rollbackNoop['action'] ?? '') === 'rollback_noop', 'Rollback before any operation must be a no-op');
    $assertTrue(($rollbackNoop['state_written'] ?? true) === false, 'Rollback no-op must not create rolled_back state');
    $assertTrue(!is_file($private . '/production-cutover.json'), 'Rollback no-op must leave state absent');

    file_put_contents($private . '/production-cutover.json', json_encode(['state' => 'rolled_back'], JSON_THROW_ON_ERROR));
    file_put_contents($private . '/production-cutover.runtime.backup', '__MGW_RUNTIME_ABSENT__');
    $rearmed = $emergencyControl->rearm();
    $assertTrue(($rearmed['action'] ?? '') === 'rearmed', 'Reviewed rollback must support explicit safe rearm');
    $assertTrue(!is_file($private . '/production-cutover.json'), 'Rearm must archive the terminal state');
    $assertTrue(!is_file($private . '/production-cutover.runtime.backup'), 'Rearm must archive the runtime backup');
} finally {
    $removeFixture($fixture);
}

[$fixture, $private, $data, $configFile] = $makeFixture();
try {
    file_put_contents($private . '/runtime.php', "<?php\nreturn ['database_runtime' => ['enabled' => false]];\n");
    file_put_contents($private . '/production-cutover.runtime.backup', "<?php\nreturn ['database_runtime' => ['enabled' => false]];\n");
    file_put_contents($private . '/production-cutover.json', '{broken');

    $subject = $runner($data, $configFile, new ProductionCutoverConfig(false, false));
    $recovered = $subject->run();
    $assertTrue(($recovered['action'] ?? '') === 'automatic_rollback', 'Invalid state with recovery artifacts must trigger rollback');
    $assertTrue(($recovered['rollback']['ok'] ?? false) === true, 'Invalid state recovery must restore JSON runtime cleanly');
    $state = json_decode((string)file_get_contents($private . '/production-cutover.json'), true, 512, JSON_THROW_ON_ERROR);
    $assertTrue(($state['state'] ?? '') === 'rolled_back', 'Recovered invalid state must record reviewed rollback state');
} finally {
    $removeFixture($fixture);
}

fwrite(STDOUT, "ProductionCutoverRunnerStateTest passed: {$assertions} assertions.\n");
