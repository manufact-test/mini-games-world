<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/storage/RuntimeModuleActivationController.php';

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
        throw new RuntimeException('History runtime activation is enabled only in staging.');
    }
    if (FeatureFlagService::BUILD !== 'v96-mvp14-db-history-routing') {
        throw new RuntimeException('Unexpected application build for history runtime activation.');
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

    $lockHandle = fopen($privateDir . '/history-runtime-activation.lock', 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another history runtime activation command is already running.');
    }

    $configForRuntime = static function (array $runtime) use ($config): array {
        $next = $config;
        $flags = $next['feature_flags'] ?? [];
        if (!is_array($flags)) $flags = [];
        $next['feature_flags'] = array_replace_recursive($flags, $runtime);
        return $next;
    };

    $snapshot = static function () use ($storage): array {
        $value = $storage->readOnly(static fn(array $data): array => $data);
        if (!is_array($value)) throw new RuntimeException('JSON history snapshot is invalid.');
        return $value;
    };

    $repository = static function (array $runtime) use ($configForRuntime, $database): RuntimeHistoryRepository {
        $runtimeConfig = $configForRuntime($runtime);
        $router = new RuntimeStorageRouter($runtimeConfig);
        return new RuntimeHistoryRepository(
            $runtimeConfig,
            $router,
            $database,
            new HistoryService($runtimeConfig, new UserService($runtimeConfig))
        );
    };

    $synchronize = static function (array $runtime) use ($storage, $database, $snapshot): array {
        $json = $snapshot();
        $realtime = (new LegacyRealtimeShadowSyncService($storage, $database))->run();
        $economy = (new LegacyEconomyShadowSyncService($storage, $database))->run();
        return [
            'ok' => !empty($realtime['ok']) && !empty($economy['ok']),
            'realtime' => [
                'source_fingerprint' => (string)($realtime['source_fingerprint'] ?? ''),
                'sections' => $realtime['sections'] ?? [],
            ],
            'economy' => [
                'source_fingerprint' => (string)($economy['source_fingerprint'] ?? ''),
                'sections' => $economy['sections'] ?? [],
                'shadow_integrity' => $economy['shadow_integrity'] ?? [],
            ],
            'snapshot_user_count' => count(is_array($json['users'] ?? null) ? $json['users'] : []),
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    };

    $audit = static function (array $runtime) use ($repository, $snapshot): array {
        return $repository($runtime)->auditParity($snapshot());
    };

    $controller = new RuntimeModuleActivationController(
        'history',
        ['accounts', 'realtime', 'economy'],
        $privateDir . '/runtime.php',
        $privateDir . '/history-runtime-activation.json',
        $privateDir . '/history-runtime-activation.runtime.backup',
        $synchronize,
        $audit,
        'mvp-14.8.4e-history-runtime-activation'
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
        'report_type' => 'mvp-14.8.4e-history-runtime-activation',
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
