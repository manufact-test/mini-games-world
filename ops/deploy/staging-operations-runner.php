<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

@set_time_limit(240);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/operations/StagingOperationDefinition.php';
require_once $projectRoot . '/bot/operations/StagingOperationsRunner.php';
require_once $projectRoot . '/bot/operations/StagingOperationRegistry.php';

$options = getopt('', ['run', 'status']);
$modeCount = (int)isset($options['run']) + (int)isset($options['status']);
$runnerLockHandle = null;
$migrationLockHandle = null;
$exitCode = 0;

$normalizePath = static function (string $path): string {
    return rtrim(str_replace('\\', '/', trim($path)), '/');
};
$isInside = static function (string $path, string $parent) use ($normalizePath): bool {
    $path = $normalizePath($path);
    $parent = $normalizePath($parent);
    return $path === $parent || str_starts_with($path . '/', $parent . '/');
};
$print = static function (array $result, int $code = 0): void {
    $stream = $code === 0 ? STDOUT : STDERR;
    fwrite($stream, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
};

try {
    if ($modeCount > 1) throw new InvalidArgumentException('Choose only one mode: --run or --status.');

    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($environment !== 'staging') {
        throw new RuntimeException('The permanent operations runner is enabled only in staging.');
    }
    if (FeatureFlagService::BUILD !== 'v97-mvp14-staging-operations-runner') {
        throw new RuntimeException('Unexpected application build for the staging operations runner.');
    }

    $privateDir = is_string($configFile ?? null)
        ? dirname($configFile)
        : dirname($projectRoot) . '/_private_mgw';
    if ($isInside($privateDir, $projectRoot)) {
        throw new RuntimeException('The operations runner private directory must be outside the deployed project.');
    }
    if (!is_dir($privateDir)) throw new RuntimeException('Private runtime directory is unavailable.');

    $runnerLockFile = $privateDir . '/staging-operations-runner.lock';
    $migrationSettings = is_array($config['managed_migrations'] ?? null)
        ? $config['managed_migrations']
        : [];
    $migrationLockFile = trim((string)($migrationSettings['lock_file'] ?? ($privateDir . '/managed-migrations.lock')));
    foreach ([$runnerLockFile, $migrationLockFile] as $lockFile) {
        if ($lockFile === '' || $isInside($lockFile, $projectRoot)) {
            throw new RuntimeException('Operations runner lock files must remain outside the deployed project.');
        }
    }

    $runnerLockHandle = fopen($runnerLockFile, 'c+');
    if ($runnerLockHandle === false) throw new RuntimeException('Could not open the staging operations runner lock.');
    @chmod($runnerLockFile, 0600);
    if (!flock($runnerLockHandle, LOCK_EX | LOCK_NB)) {
        $print([
            'ok' => true,
            'report_type' => 'mvp-14.8.4f-staging-operations-runner',
            'runner_state' => 'busy',
            'busy_reason' => 'operations_runner_already_running',
            'action' => 'run_noop',
            'idempotent' => true,
            'environment' => $environment,
            'build' => FeatureFlagService::BUILD,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => gmdate(DATE_ATOM),
        ]);
        $exitCode = 0;
    } else {
        $migrationLockHandle = fopen($migrationLockFile, 'c+');
        if ($migrationLockHandle === false) throw new RuntimeException('Could not open the shared migration lock.');
        @chmod($migrationLockFile, 0600);
        if (!flock($migrationLockHandle, LOCK_EX | LOCK_NB)) {
            $print([
                'ok' => true,
                'report_type' => 'mvp-14.8.4f-staging-operations-runner',
                'runner_state' => 'busy',
                'busy_reason' => 'managed_migrations_running',
                'action' => 'run_noop',
                'idempotent' => true,
                'environment' => $environment,
                'build' => FeatureFlagService::BUILD,
                'production_changed' => false,
                'sensitive_identifiers_exposed' => false,
                'generated_at_utc' => gmdate(DATE_ATOM),
            ]);
            $exitCode = 0;
        } else {
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

            $print($result, (($result['ok'] ?? false) === true || $executionMode === 'status') ? 0 : 2);
            if (($result['ok'] ?? false) !== true && $executionMode === 'run') $exitCode = 2;
        }
    }
} catch (Throwable $error) {
    $exitCode = 1;
    $message = preg_replace(
        '~/(?:home|var|tmp|srv)/[^\s\'\"]+~',
        '[private-path]',
        $error->getMessage()
    ) ?? $error->getMessage();
    $print([
        'ok' => false,
        'report_type' => 'mvp-14.8.4f-staging-operations-runner',
        'environment' => (string)($config['environment'] ?? 'unknown'),
        'build' => class_exists('FeatureFlagService') ? FeatureFlagService::BUILD : 'unknown',
        'error_class' => get_class($error),
        'error_message' => mb_substr($message, 0, 500),
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
    ], 1);
} finally {
    if (is_resource($migrationLockHandle)) {
        flock($migrationLockHandle, LOCK_UN);
        fclose($migrationLockHandle);
    }
    if (is_resource($runnerLockHandle)) {
        flock($runnerLockHandle, LOCK_UN);
        fclose($runnerLockHandle);
    }
}

exit($exitCode);
