<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/ledger/EconomyRuntimeActivationController.php';

$options = getopt('', ['run', 'status', 'disable', 'reset', 'reason:']);
$modeCount = (int)isset($options['run']) + (int)isset($options['status']) + (int)isset($options['disable']);
$lockHandle = null;
$exitCode = 0;

try {
    if ($modeCount > 1) {
        throw new InvalidArgumentException('Choose only one mode: --run, --status or --disable.');
    }

    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($environment !== 'staging') {
        throw new RuntimeException('Economy runtime activation is enabled only in staging.');
    }
    if (FeatureFlagService::BUILD !== 'v95-mvp14-db-economy-routing') {
        throw new RuntimeException('Unexpected application build for economy runtime activation.');
    }

    $storage = StorageFactory::create($config);
    if ($storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
        throw new RuntimeException('Global JSON rollback storage must remain active.');
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('Database is not enabled in the private configuration.');
    }
    $database = PdoConnectionFactory::create($databaseConfig);
    $migrationStatus = (new MigrationRunner(
        $database,
        $projectRoot . '/bot/database/migrations'
    ))->status();
    if ((int)($migrationStatus['pending_count'] ?? -1) !== 0) {
        throw new RuntimeException('Database schema has pending migrations.');
    }

    $privateDir = is_string($configFile ?? null)
        ? dirname($configFile)
        : dirname($projectRoot) . '/_private_mgw';
    if (!is_dir($privateDir)) {
        throw new RuntimeException('Private runtime directory is unavailable.');
    }

    $lockHandle = fopen($privateDir . '/economy-runtime-activation.lock', 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another economy runtime activation command is already running.');
    }

    $configForRuntime = static function (array $runtime) use ($config): array {
        $next = $config;
        $flags = $next['feature_flags'] ?? [];
        if (!is_array($flags)) $flags = [];
        $next['feature_flags'] = array_replace_recursive($flags, $runtime);

        if (!isset($next['feature_flags']['database_runtime'])
            || !is_array($next['feature_flags']['database_runtime'])) {
            $next['feature_flags']['database_runtime'] = [];
        }
        if (!isset($next['feature_flags']['database_runtime']['modules'])
            || !is_array($next['feature_flags']['database_runtime']['modules'])) {
            $next['feature_flags']['database_runtime']['modules'] = [];
        }
        $next['feature_flags']['database_runtime']['enabled'] = true;
        $next['feature_flags']['database_runtime']['modules']['economy'] =
            $runtime['database_runtime']['modules']['economy'] ?? false;
        return $next;
    };

    $synchronize = static function (array $runtime) use (
        $configForRuntime,
        $storage,
        $database
    ): array {
        $runtimeConfig = $configForRuntime($runtime);
        $router = new RuntimeStorageRouter($runtimeConfig);
        $snapshot = $storage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) throw new RuntimeException('JSON economy snapshot is invalid.');
        return (new RuntimeEconomyRepository($runtimeConfig, $router, $database))->synchronize($snapshot);
    };

    $audit = static function (array $runtime) use (
        $configForRuntime,
        $storage,
        $database
    ): array {
        $runtimeConfig = $configForRuntime($runtime);
        $router = new RuntimeStorageRouter($runtimeConfig);
        $snapshot = $storage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) throw new RuntimeException('JSON economy audit snapshot is invalid.');
        return (new RuntimeEconomyRepository($runtimeConfig, $router, $database))->auditParity($snapshot);
    };

    $controller = new EconomyRuntimeActivationController(
        $privateDir . '/runtime.php',
        $privateDir . '/economy-runtime-activation.json',
        $privateDir . '/economy-runtime-activation.runtime.backup',
        $synchronize,
        $audit
    );

    if (isset($options['run'])) {
        $result = $controller->run(isset($options['reset']));
        $executionMode = 'run';
    } elseif (isset($options['disable'])) {
        $result = $controller->disable((string)($options['reason'] ?? 'manual staging rollback'));
        $executionMode = 'disable';
    } else {
        $result = $controller->status();
        $executionMode = 'status';
    }

    $result['environment'] = $environment;
    $result['execution_mode'] = $executionMode;
    $result['build'] = FeatureFlagService::BUILD;
    $result['storage_driver'] = $storage->driver();
    $result['rollback_driver'] = RuntimeStorageRouter::DRIVER_JSON;
    $result['database_driver'] = $database->driver();
    $result['schema_current'] = true;
    $result['applied_migrations'] = (int)($migrationStatus['applied_count'] ?? 0);
    $result['state_file_private'] = true;

    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);

    if (($result['ok'] ?? false) !== true && $executionMode !== 'status') {
        $exitCode = 2;
    }
} catch (Throwable $error) {
    $exitCode = 1;
    fwrite(STDERR, json_encode([
        'ok' => false,
        'report_type' => 'mvp-14.8.4d1-economy-runtime-activation',
        'environment' => (string)($config['environment'] ?? 'unknown'),
        'error_class' => get_class($error),
        'error_message' => $error->getMessage(),
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
} finally {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

exit($exitCode);
