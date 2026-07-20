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

$makeRunner = static function (string $statePayload = '') use ($projectRoot): array {
    $root = sys_get_temp_dir() . '/mgw-manual-rollback-state-' . bin2hex(random_bytes(6));
    $private = $root . '/private';
    $data = $root . '/data';
    mkdir($private, 0700, true);
    mkdir($data, 0700, true);
    $configFile = $private . '/config.php';
    file_put_contents($configFile, "<?php\nreturn [];\n");
    if ($statePayload !== '') {
        file_put_contents($private . '/production-cutover.json', $statePayload);
    }

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

[$runner, $fixture, $private] = $makeRunner();
try {
    $noop = $runner->rollback();
    $assertTrue(($noop['action'] ?? '') === 'rollback_noop', 'No state and no artifacts must remain an idempotent rollback no-op');
    $assertTrue(($noop['ok'] ?? false) === true, 'Clean rollback no-op must report success');
} finally {
    $removeFixture($fixture);
}

$runningState = json_encode([
    'state' => 'running',
    'started_at_utc' => '2026-07-20T09:55:00+00:00',
], JSON_THROW_ON_ERROR);
[$runner, $fixture, $private] = $makeRunner($runningState);
try {
    $blocked = $runner->rollback();
    $assertTrue(($blocked['action'] ?? '') === 'recovery_blocked', 'Recorded running state without recovery artifacts must block manual rollback');
    $assertTrue(($blocked['ok'] ?? true) === false, 'Blocked manual rollback must never report success');
    $assertTrue(($blocked['rollback']['attempted'] ?? true) === false, 'Blocked manual rollback must not claim an attempted recovery');
    $persisted = json_decode((string)file_get_contents($private . '/production-cutover.json'), true, 512, JSON_THROW_ON_ERROR);
    $assertTrue(($persisted['state'] ?? '') === 'running', 'Blocked manual rollback must preserve the incident state');
} finally {
    $removeFixture($fixture);
}

[$runner, $fixture, $private] = $makeRunner('{broken');
try {
    $blocked = $runner->rollback();
    $assertTrue(($blocked['action'] ?? '') === 'recovery_blocked', 'Invalid state without recovery artifacts must block manual rollback');
    $assertTrue(($blocked['state'] ?? '') === 'invalid', 'Invalid manual rollback state must remain explicit');
    $assertTrue((string)file_get_contents($private . '/production-cutover.json') === '{broken', 'Invalid incident state must remain untouched');
} finally {
    $removeFixture($fixture);
}

fwrite(STDOUT, "ProductionCutoverManualRollbackStateTest passed: {$assertions} assertions.\n");
