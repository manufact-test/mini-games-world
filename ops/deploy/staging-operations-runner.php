<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/operations/StagingOperationDefinition.php';
require_once $projectRoot . '/bot/operations/StagingOperationsRunner.php';
require_once $projectRoot . '/bot/operations/StagingOperationRegistry.php';

$options = getopt('', ['run', 'status']);
$modeCount = (int)isset($options['run']) + (int)isset($options['status']);
$lockHandle = null;
$exitCode = 0;

try {
    if ($modeCount > 1) throw new InvalidArgumentException('Choose only one mode: --run or --status.');

    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($environment !== 'staging') {
        throw new RuntimeException('The permanent operations runner is enabled only in staging.');
    }
    if (FeatureFlagService::BUILD !== 'v97-mvp14-staging-operations-runner') {
        throw new RuntimeException('Unexpected application build for the staging operations runner.');
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
    if (!is_dir($privateDir)) throw new RuntimeException('Private runtime directory is unavailable.');

    $lockHandle = fopen($privateDir . '/staging-operations-runner.lock', 'c+');
    if ($lockHandle === false) throw new RuntimeException('Could not open the staging operations runner lock.');
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        $busy = [
            'ok' => true,
            'report_type' => 'mvp-14.8.4f-staging-operations-runner',
            'runner_state' => 'busy',
            'action' => 'run_noop',
            'idempotent' => true,
            'environment' => $environment,
            'build' => FeatureFlagService::BUILD,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => gmdate(DATE_ATOM),
        ];
        fwrite(STDOUT, json_encode(
            $busy,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL);
        exit(0);
    }

    $runner = new StagingOperationsRunner(
        FeatureFlagService::BUILD,
        $privateDir . '/staging-operations-runner.json',
        StagingOperationRegistry::definitions($config, $storage, $database, $migrationStatus)
    );
    $executionMode = isset($options['status']) ? 'status' : 'run';
    $result = $executionMode === 'status' ? $runner->status() : $runner->run();
    $result['environment'] = $environment;
    $result['execution_mode'] = $executionMode;
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
    if (($result['ok'] ?? false) !== true && $executionMode === 'run') $exitCode = 2;
} catch (Throwable $error) {
    $exitCode = 1;
    fwrite(STDERR, json_encode([
        'ok' => false,
        'report_type' => 'mvp-14.8.4f-staging-operations-runner',
        'environment' => (string)($config['environment'] ?? 'unknown'),
        'build' => class_exists('FeatureFlagService') ? FeatureFlagService::BUILD : 'unknown',
        'error_class' => get_class($error),
        'error_message' => mb_substr($error->getMessage(), 0, 500),
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
