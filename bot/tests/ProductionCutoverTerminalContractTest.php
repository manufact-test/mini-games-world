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
    public function enabled(): bool { return false; }
    public function assertApproved(string $build, string $fingerprint, int $now): void {}
    public function requirePrimaryBackup(): bool { return true; }
    public function requireExternalCopy(): bool { return true; }
    public function safeSummary(): array { return ['enabled' => false]; }
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
    private const MODULES = [
        'accounts', 'realtime', 'invites', 'notifications', 'economy',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    public function __construct(private array $config) {}
    public function enabled(): bool
    {
        return (bool)($this->settings()['enabled'] ?? false);
    }
    public function enabledModules(): array
    {
        if (!$this->enabled()) return [];
        $modules = is_array($this->settings()['modules'] ?? null) ? $this->settings()['modules'] : [];
        return array_values(array_filter(
            self::MODULES,
            static fn(string $module): bool => ($modules[$module] ?? false) === true
        ));
    }
    private function settings(): array
    {
        $flags = is_array($this->config['feature_flags'] ?? null) ? $this->config['feature_flags'] : [];
        return is_array($flags['database_runtime'] ?? null) ? $flags['database_runtime'] : [];
    }
}
final class MigrationRunner
{
    public function __construct(mixed ...$arguments) {}
    public function status(): array { return ['pending_count' => 0]; }
}
final class ProductionPreflightRunner
{
    public function __construct(mixed ...$arguments) {}
    public function run(): array { return ['technical_ready_for_window' => false, 'blockers' => ['test blocker']]; }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/cutover/ProductionCutoverRunner.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
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

$fixture = static function (string $stateSourceFingerprint, string $runtimeSourceFingerprint) use ($projectRoot): array {
    $root = sys_get_temp_dir() . '/mgw-terminal-contract-' . bin2hex(random_bytes(6));
    $private = $root . '/private';
    $data = $root . '/data';
    mkdir($private, 0700, true);
    mkdir($data, 0700, true);
    $configFile = $private . '/config.php';
    file_put_contents($configFile, "<?php\nreturn [];\n");

    $plan = str_repeat('a', 64);
    $modules = array_fill_keys([
        'accounts', 'realtime', 'invites', 'notifications', 'economy',
        'history', 'shop', 'payments', 'weekly_bonus',
    ], true);
    file_put_contents($private . '/runtime.php', "<?php\nreturn " . var_export([
        'database_runtime' => [
            'enabled' => true,
            'production_activated' => true,
            'activation_build' => 'v103-mvp14-production-cutover',
            'activation_plan_fingerprint' => $plan,
            'activation_source_fingerprint' => $runtimeSourceFingerprint,
            'activated_at_utc' => '2026-07-20T09:58:00+00:00',
            'rollback_driver' => 'json',
            'modules' => $modules,
        ],
    ], true) . ";\n");
    file_put_contents($private . '/production-cutover.runtime.backup', '__MGW_RUNTIME_ABSENT__');
    file_put_contents($private . '/production-cutover.json', json_encode([
        'state' => 'completed',
        'build' => 'v103-mvp14-production-cutover',
        'started_at_utc' => '2026-07-20T09:50:00+00:00',
        'completed_at_utc' => '2026-07-20T10:00:00+00:00',
        'plan_fingerprint' => $plan,
        'source_fingerprint' => $stateSourceFingerprint,
        'runtime_backup_present' => true,
        'database_runtime_published' => true,
        'json_write_block_active' => false,
        'rollback_driver' => 'json',
    ], JSON_THROW_ON_ERROR));

    $runner = new ProductionCutoverRunner(
        $projectRoot,
        ['environment' => 'production', 'data_dir' => $data, 'feature_flags' => []],
        $configFile,
        null,
        null,
        null,
        new ProductionCutoverConfig(),
        strtotime('2026-07-20T10:00:00+00:00')
    );
    return [$runner, $root, $private];
};

$source = str_repeat('b', 64);
[$runner, $root, $private] = $fixture($source, $source);
try {
    $status = $runner->status();
    $assertTrue(($status['ok'] ?? false) === true, 'Matching completed state and runtime markers must be healthy');
    $noop = $runner->run();
    $assertTrue(($noop['action'] ?? '') === 'cutover_noop', 'Matching completed state must remain idempotent');
    $assertTrue(($noop['ok'] ?? false) === true, 'Matching completed state no-op must report success');
} finally {
    $removeFixture($root);
}

[$runner, $root, $private] = $fixture(str_repeat('c', 64), $source);
try {
    $status = $runner->status();
    $assertTrue(($status['ok'] ?? true) === false, 'Mismatched completed source fingerprint must be unhealthy');
    $assertTrue(
        str_contains((string)($status['state_contract_error'] ?? ''), 'source fingerprint'),
        'Status must explain the completed source fingerprint mismatch'
    );

    $recovered = $runner->run();
    $assertTrue(($recovered['action'] ?? '') === 'automatic_rollback', 'Mismatched completed markers must trigger automatic rollback');
    $assertTrue(($recovered['rollback_succeeded'] ?? false) === true, 'Completed marker mismatch must restore exact JSON runtime');
    $state = json_decode((string)file_get_contents($private . '/production-cutover.json'), true, 512, JSON_THROW_ON_ERROR);
    $assertTrue(($state['state'] ?? '') === 'rolled_back', 'Completed marker mismatch recovery must persist rolled_back state');
    $assertTrue(!is_file($private . '/runtime.php'), 'Absent-runtime backup must remove the activated runtime during rollback');
} finally {
    $removeFixture($root);
}

fwrite(STDOUT, "ProductionCutoverTerminalContractTest passed: {$assertions} assertions.\n");
