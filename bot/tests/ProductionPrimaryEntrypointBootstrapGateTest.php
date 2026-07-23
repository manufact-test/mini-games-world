<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/runtime/ProductionPrimaryEntrypointBootstrap.php';

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
$remove = static function (string $path) use (&$remove): void {
    if (!file_exists($path) && !is_link($path)) return;
    if (is_dir($path) && !is_link($path)) {
        $items = scandir($path);
        if (!is_array($items)) throw new RuntimeException('Fixture directory could not be listed.');
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') continue;
            $remove($path . '/' . $name);
        }
        if (!rmdir($path)) throw new RuntimeException('Fixture directory could not be removed.');
        return;
    }
    if (!unlink($path)) throw new RuntimeException('Fixture file could not be removed.');
};
$writePrivate = static function (string $path, string $content): void {
    $written = file_put_contents($path, $content, LOCK_EX);
    if ($written !== strlen($content) || !chmod($path, 0600)) {
        throw new RuntimeException('Private fixture could not be written safely.');
    }
};

$nonProduction = ProductionPrimaryEntrypointBootstrap::installIfEnabled(
    $projectRoot,
    ['environment' => 'staging', 'feature_flags' => []],
    __FILE__,
    'api'
);
$assertTrue(($nonProduction['installed'] ?? true) === false, 'Non-production must not install production context');
$assertTrue(($nonProduction['storage_driver'] ?? '') === 'json', 'Non-production must remain JSON');
$assertTrue(($nonProduction['database_contacted'] ?? true) === false, 'Non-production must not contact database');

$plainProduction = ProductionPrimaryEntrypointBootstrap::installIfEnabled(
    $projectRoot,
    ['environment' => 'production', 'feature_flags' => []],
    __FILE__,
    'webhook'
);
$assertTrue(($plainProduction['installed'] ?? true) === false, 'Plain production must not install DB context');
$assertTrue(($plainProduction['reason'] ?? '') === 'production_activation_not_requested', 'Plain production must expose disabled reason');
$assertTrue(($plainProduction['database_contacted'] ?? true) === false, 'Plain production must not contact database');

$assertThrows(
    static fn() => ProductionPrimaryEntrypointBootstrap::installIfEnabled(
        $projectRoot,
        [
            'environment' => 'production',
            'feature_flags' => [
                'database_runtime' => [
                    'enabled' => true,
                    'production_activated' => false,
                ],
            ],
        ],
        __FILE__,
        'webhook'
    ),
    'markers are inconsistent'
);
$assertThrows(
    static fn() => ProductionPrimaryEntrypointBootstrap::installIfEnabled(
        $projectRoot,
        ['environment' => 'production', 'feature_flags' => []],
        __FILE__,
        'cron'
    ),
    'only API and webhook'
);

$root = sys_get_temp_dir() . '/mgw-production-bootstrap-gate-' . bin2hex(random_bytes(6));
$project = $root . '/project';
$private = $root . '/private';
$data = $root . '/data';
try {
    foreach ([$root, $project, $private, $data] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('Fixture directory could not be created.');
        }
        if (!chmod($directory, 0700)) {
            throw new RuntimeException('Fixture directory permissions could not be secured.');
        }
    }

    $configFile = $private . '/config.php';
    $writePrivate($configFile, "<?php\ndeclare(strict_types=1);\nreturn [];\n");
    $writePrivate($private . '/production-cutover.runtime.backup', '__MGW_RUNTIME_ABSENT__');

    $plan = str_repeat('a', 64);
    $source = str_repeat('b', 64);
    $modules = array_fill_keys([
        'accounts', 'realtime', 'invites', 'notifications', 'economy',
        'history', 'shop', 'payments', 'weekly_bonus',
    ], true);
    $config = [
        'environment' => 'production',
        'storage_driver' => 'json',
        'data_dir' => $data,
        'database' => [
            'enabled' => true,
            'driver' => 'mysql',
            'host' => 'invalid-never-contact.example',
            'port' => 3306,
            'name' => 'mgw_production',
            'user' => 'mgw_production',
            'password' => 'fixture-password',
            'charset' => 'utf8mb4',
        ],
        'feature_flags' => [
            'maintenance_mode' => true,
            'financial_read_only' => true,
            'database_runtime' => [
                'enabled' => true,
                'production_activated' => true,
                'activation_build' => ProductionPrimaryRuntimeActivationContract::ACTIVATION_BUILD,
                'activation_plan_fingerprint' => $plan,
                'activation_source_fingerprint' => $source,
                'activated_at_utc' => '2026-07-23T14:00:00+00:00',
                'rollback_driver' => 'json',
                'modules' => $modules,
            ],
        ],
    ];
    $state = [
        'state' => 'awaiting_release',
        'build' => ProductionPrimaryRuntimeActivationContract::ACTIVATION_BUILD,
        'plan_fingerprint' => $plan,
        'source_fingerprint' => $source,
        'runtime_backup_present' => true,
        'database_runtime_published' => true,
        'json_write_block_active' => true,
        'rollback_driver' => 'json',
        'maintenance_active' => true,
        'financial_read_only_active' => true,
    ];
    $writePrivate(
        $private . '/production-cutover.json',
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );
    $writePrivate(
        $data . '/.cutover-write-block',
        json_encode([
            'state' => 'sealed',
            'environment' => 'production',
            'build' => ProductionPrimaryRuntimeActivationContract::ACTIVATION_BUILD,
            'plan_fingerprint' => $plan,
            'activated_at_utc' => '2026-07-23T14:00:00+00:00',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );

    $assertThrows(
        static fn() => ProductionPrimaryEntrypointBootstrap::installIfEnabled(
            $project,
            $config,
            $configFile,
            'api'
        ),
        'completed released cutover state'
    );
} finally {
    $remove($root);
}

$assertTrue(
    ProductionPrimaryEntrypointStorageContext::installed() === false,
    'Blocked and disabled gates must never install production context'
);

fwrite(
    STDOUT,
    "ProductionPrimaryEntrypointBootstrapGateTest passed: {$assertions} assertions.\n"
);
