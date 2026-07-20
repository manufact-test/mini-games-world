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
    public function __construct(private array $config) {}
    public function maintenanceEnabled(): bool
    {
        return (bool)($this->flags()['maintenance_mode'] ?? false);
    }
    public function financialReadOnly(): bool
    {
        return (bool)($this->flags()['financial_read_only'] ?? false);
    }
    public function featureEnabled(string $feature): bool
    {
        $features = is_array($this->flags()['features'] ?? null) ? $this->flags()['features'] : [];
        return (bool)($features[$feature] ?? true);
    }
    private function flags(): array
    {
        return is_array($this->config['feature_flags'] ?? null) ? $this->config['feature_flags'] : [];
    }
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
final class LedgerIntegrity
{
    public static function canonicalJson(mixed $value): string
    {
        return json_encode(self::canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonicalize($item);
        return $value;
    }
}
final class StagingOperationDefinition
{
    public function execute(): array
    {
        return [
            'ok' => true,
            'enabled_modules' => [
                'accounts', 'realtime', 'invites', 'notifications', 'economy',
                'history', 'shop', 'payments', 'weekly_bonus',
            ],
            'missing_required_modules' => [],
            'accounts' => ['ok' => true],
            'realtime' => ['ok' => true],
            'invites' => ['ok' => true],
            'notifications' => ['ok' => true],
            'history' => ['ok' => true],
            'economy' => ['ok' => true],
            'shop' => ['ok' => true],
            'payments' => ['ok' => true],
            'weekly_bonus' => ['ok' => true],
            'blockers' => [],
        ];
    }
}
final class StagingDatabaseRuntimeRegressionOperation
{
    public function __construct(mixed ...$arguments) {}
    public function definition(): StagingOperationDefinition { return new StagingOperationDefinition(); }
}
final class ProductionCutoverReleaseStateStorage implements StorageAdapterInterface
{
    public function __construct(private array $snapshot) {}
    public function driver(): string { return 'json'; }
    public function readOnly(callable $callback): mixed { return $callback($this->snapshot); }
    public function transaction(callable $callback): mixed
    {
        $snapshot = $this->snapshot;
        return $callback($snapshot);
    }
}
final class ProductionCutoverReleaseStateDatabase implements DatabaseConnectionInterface {}

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

$root = sys_get_temp_dir() . '/mgw-release-state-' . bin2hex(random_bytes(6));
$private = $root . '/private';
$data = $root . '/data';
mkdir($private, 0700, true);
mkdir($data, 0700, true);
$configFile = $private . '/config.php';
file_put_contents($configFile, "<?php\nreturn [];\n");

$snapshot = [
    'users' => [], 'games' => [], 'queue' => [], 'transactions' => [],
    'support' => [], 'shop_orders' => [], 'payments' => [],
    'notifications' => [], 'invites' => [], 'system' => [],
];
$sourceFingerprint = hash('sha256', LedgerIntegrity::canonicalJson($snapshot));
$planFingerprint = str_repeat('a', 64);
$modules = array_fill_keys([
    'accounts', 'realtime', 'invites', 'notifications', 'economy',
    'history', 'shop', 'payments', 'weekly_bonus',
], true);
$runtime = [
    'maintenance_mode' => true,
    'financial_read_only' => true,
    'features' => [
        'matchmaking' => false,
        'invitations' => false,
        'payments' => false,
        'shop' => false,
    ],
    'database_runtime' => [
        'enabled' => true,
        'production_activated' => true,
        'activation_build' => 'v103-mvp14-production-cutover',
        'activation_plan_fingerprint' => $planFingerprint,
        'activation_source_fingerprint' => $sourceFingerprint,
        'activated_at_utc' => '2026-07-20T09:58:00+00:00',
        'rollback_driver' => 'json',
        'modules' => $modules,
    ],
];
file_put_contents($private . '/runtime.php', "<?php\nreturn " . var_export($runtime, true) . ";\n");
file_put_contents($private . '/production-cutover.runtime.backup', '__MGW_RUNTIME_ABSENT__');
file_put_contents($data . '/.cutover-write-block', "sealed\n");
file_put_contents($private . '/production-cutover.json', json_encode([
    'state' => 'awaiting_release',
    'build' => 'v103-mvp14-production-cutover',
    'started_at_utc' => '2026-07-20T09:50:00+00:00',
    'release_ready_at_utc' => '2026-07-20T10:00:00+00:00',
    'plan_fingerprint' => $planFingerprint,
    'source_fingerprint' => $sourceFingerprint,
    'backup_id' => 'test-backup',
    'backup_snapshot_sha256' => str_repeat('b', 64),
    'runtime_backup_present' => true,
    'database_runtime_published' => true,
    'json_write_block_active' => true,
    'maintenance_active' => true,
    'financial_read_only_active' => true,
    'rollback_driver' => 'json',
], JSON_THROW_ON_ERROR));

try {
    $runner = new ProductionCutoverRunner(
        $projectRoot,
        ['environment' => 'production', 'data_dir' => $data, 'feature_flags' => []],
        $configFile,
        new ProductionCutoverReleaseStateStorage($snapshot),
        new ProductionCutoverReleaseStateDatabase(),
        new BackupManager(),
        new ProductionCutoverConfig(),
        strtotime('2026-07-20T10:01:00+00:00')
    );

    $status = $runner->status();
    $assertTrue(($status['ok'] ?? false) === true, 'Protected awaiting-release status must be healthy');
    $assertTrue(($status['release_required'] ?? false) === true, 'Awaiting-release status must require explicit release');
    $assertTrue(($status['operator_action_required'] ?? false) === true, 'Awaiting-release status must require operator action');

    $noop = $runner->run();
    $assertTrue(($noop['action'] ?? '') === 'awaiting_release_noop', 'Repeated run must preserve protected awaiting-release state');
    $assertTrue(is_file($data . '/.cutover-write-block'), 'Repeated run must preserve the JSON write block');

    $released = $runner->release();
    $assertTrue(($released['action'] ?? '') === 'cutover_completed', 'Explicit release must complete the cutover');
    $assertTrue(($released['maintenance_released'] ?? false) === true, 'Explicit release must remove maintenance mode');
    $assertTrue(!is_file($data . '/.cutover-write-block'), 'Explicit release must remove the JSON write block');

    $finalRuntime = require $private . '/runtime.php';
    $assertTrue(($finalRuntime['database_runtime']['enabled'] ?? false) === true, 'Release must preserve DB runtime routing');
    $assertTrue(($finalRuntime['maintenance_mode'] ?? false) === false, 'Release must restore the original maintenance setting');
    $state = json_decode((string)file_get_contents($private . '/production-cutover.json'), true, 512, JSON_THROW_ON_ERROR);
    $assertTrue(($state['state'] ?? '') === 'completed', 'Release must persist completed state');
} finally {
    $removeFixture($root);
}

fwrite(STDOUT, "ProductionCutoverReleaseStateTest passed: {$assertions} assertions.\n");
