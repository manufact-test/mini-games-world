<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

@set_time_limit(240);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/cutover/ProductionPreflightService.php';
require_once $projectRoot . '/bot/database/ManagedMigrationController.php';
require_once $projectRoot . '/ops/backup/BackupConfigLoader.php';
require_once $projectRoot . '/ops/backup/BackupManager.php';

$options = getopt('', ['run']);
$exitCode = 0;
$lockHandle = null;

$normalizePath = static fn(string $path): string => rtrim(str_replace('\\', '/', trim($path)), '/');
$isInside = static function (string $path, string $parent) use ($normalizePath): bool {
    $path = $normalizePath($path);
    $parent = $normalizePath($parent);
    return $path === $parent || str_starts_with($path . '/', $parent . '/');
};
$boolValue = static function (mixed $value): bool {
    if (is_bool($value)) return $value;
    if (is_int($value)) return $value !== 0;
    if (!is_string($value)) return false;
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
};
$safeMessage = static function (string $message): string {
    $message = preg_replace('~/(?:home|var|tmp|srv)/[^\s\'\"]+~', '[private-path]', $message) ?? $message;
    $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $message) ?? $message;
    return mb_substr(trim($message), 0, 500);
};
$print = static function (array $result, int $code = 0): void {
    $stream = $code === 0 ? STDOUT : STDERR;
    fwrite($stream, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
};
$verifyLatestBackup = static function (
    BackupManager $manager,
    ?string $root,
    string $label,
    int $maxAgeSeconds
) use ($safeMessage): array {
    if ($root === null || trim($root) === '') {
        return [
            'ok' => false,
            'source' => $label,
            'fresh' => false,
            'error' => 'backup source is not configured',
        ];
    }
    try {
        $snapshot = $manager->latestSnapshot($root);
        $verified = $manager->verify($snapshot);
        $manifest = is_array($verified['manifest'] ?? null) ? $verified['manifest'] : [];
        $createdAt = trim((string)($manifest['created_at_utc'] ?? ''));
        $createdTimestamp = $createdAt !== '' ? strtotime($createdAt) : false;
        $ageSeconds = $createdTimestamp === false ? null : max(0, time() - $createdTimestamp);
        $fresh = $ageSeconds !== null && $ageSeconds <= $maxAgeSeconds;
        return [
            'ok' => true,
            'source' => $label,
            'backup_id' => (string)($verified['backup_id'] ?? ''),
            'snapshot_sha256' => (string)($verified['snapshot_sha256'] ?? ''),
            'created_at_utc' => $createdAt,
            'age_seconds' => $ageSeconds,
            'max_age_seconds' => $maxAgeSeconds,
            'fresh' => $fresh,
            'environment' => (string)($manifest['environment'] ?? ''),
            'build' => (string)($manifest['build'] ?? ''),
            'verified_files' => (int)($verified['verified_files'] ?? 0),
            'verified_bytes' => (int)($verified['verified_bytes'] ?? 0),
        ];
    } catch (Throwable $error) {
        return [
            'ok' => false,
            'source' => $label,
            'fresh' => false,
            'error' => $safeMessage($error->getMessage()),
        ];
    }
};

try {
    if (!isset($options['run'])) {
        throw new InvalidArgumentException('Production preflight requires --run.');
    }

    $environmentValue = $config['environment'] ?? 'production';
    $environment = strtolower(trim($environmentValue instanceof BackedEnum
        ? (string)$environmentValue->value
        : (string)$environmentValue));
    if ($environment !== 'production') {
        throw new RuntimeException('Production preflight is enabled only in production.');
    }
    if (FeatureFlagService::BUILD !== 'v102-mvp14-production-preflight') {
        throw new RuntimeException('Unexpected application build for production preflight.');
    }

    $privateDir = is_string($configFile ?? null)
        ? dirname($configFile)
        : dirname($projectRoot) . '/_private_mgw';
    if (!is_dir($privateDir) || $isInside($privateDir, $projectRoot)) {
        throw new RuntimeException('Production private runtime directory is unavailable or unsafe.');
    }

    $lockFile = $privateDir . '/production-preflight.lock';
    $lockHandle = fopen($lockFile, 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another production preflight is already running.');
    }
    @chmod($lockFile, 0600);

    $storage = StorageFactory::create($config);
    $snapshot = $storage->readOnly(static fn(array $data): array => $data);
    if (!is_array($snapshot)) throw new RuntimeException('Production JSON snapshot is unavailable.');

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    $databaseConnected = false;
    $migrationStatus = [
        'available_count' => 0,
        'applied_count' => 0,
        'pending_count' => -1,
        'pending' => [],
    ];
    $databaseError = '';
    if ($databaseConfig->enabled()) {
        try {
            $database = PdoConnectionFactory::create($databaseConfig);
            $databaseConnected = (int)$database->fetchValue('SELECT 1') === 1;
            $migrationStatus = (new MigrationRunner(
                $database,
                $projectRoot . '/bot/database/migrations'
            ))->status();
        } catch (Throwable $error) {
            $databaseError = $safeMessage($error->getMessage());
        }
    }

    $preflightSettings = is_array($config['production_preflight'] ?? null)
        ? $config['production_preflight']
        : [];
    $maxBackupAge = filter_var(
        $preflightSettings['max_backup_age_seconds'] ?? 108000,
        FILTER_VALIDATE_INT
    );
    if ($maxBackupAge === false || $maxBackupAge < 3600) $maxBackupAge = 108000;

    $backupSettings = BackupConfigLoader::load($projectRoot, $environment);
    $backupManager = new BackupManager(
        $projectRoot,
        (string)($config['data_dir'] ?? ''),
        (string)$backupSettings['backup_root'],
        isset($backupSettings['external_dir']) ? (string)$backupSettings['external_dir'] : null,
        (int)$backupSettings['retention_days'],
        (int)$backupSettings['retention_count'],
        (bool)$backupSettings['include_release_files']
    );
    $backups = [
        'primary' => $verifyLatestBackup(
            $backupManager,
            (string)$backupSettings['backup_root'],
            'primary',
            (int)$maxBackupAge
        ),
        'external' => $verifyLatestBackup(
            $backupManager,
            isset($backupSettings['external_dir']) ? (string)$backupSettings['external_dir'] : null,
            'external',
            (int)$maxBackupAge
        ),
        'require_external_copy' => (bool)$backupSettings['require_external_copy'],
    ];

    $runtimeFlags = is_array($config['feature_flags'] ?? null) ? $config['feature_flags'] : [];
    $databaseRuntime = is_array($runtimeFlags['database_runtime'] ?? null)
        ? $runtimeFlags['database_runtime']
        : [];
    $requestedModules = [];
    foreach (is_array($databaseRuntime['modules'] ?? null) ? $databaseRuntime['modules'] : [] as $module => $enabled) {
        if ($boolValue($enabled)) $requestedModules[] = (string)$module;
    }
    sort($requestedModules, SORT_STRING);

    $dataDir = rtrim((string)($config['data_dir'] ?? ''), '/\\');
    $runtimeFile = $privateDir . '/runtime.php';
    $controlFile = $privateDir . '/cutover-rehearsal.json';
    $controlState = 'absent';
    $controlActive = false;
    if (is_file($controlFile)) {
        try {
            $decoded = json_decode((string)file_get_contents($controlFile), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new RuntimeException('control root is invalid');
            $controlState = strtolower(trim((string)($decoded['state'] ?? 'unknown')));
            $controlActive = in_array($controlState, ['frozen', 'sealed'], true);
        } catch (Throwable) {
            $controlState = 'invalid';
            $controlActive = true;
        }
    }

    $flags = new FeatureFlagService($config);
    $runtime = [
        'environment' => $environment,
        'build' => FeatureFlagService::BUILD,
        'storage_driver' => $storage->driver(),
        'database_enabled' => $databaseConfig->enabled(),
        'database_connected' => $databaseConnected,
        'database_error' => $databaseError,
        'schema_current' => $databaseConnected && (int)($migrationStatus['pending_count'] ?? -1) === 0,
        'available_migrations' => (int)($migrationStatus['available_count'] ?? 0),
        'applied_migrations' => (int)($migrationStatus['applied_count'] ?? 0),
        'pending_migrations' => (int)($migrationStatus['pending_count'] ?? -1),
        'migration_plan_fingerprint' => ManagedMigrationController::fingerprint(
            is_array($migrationStatus['pending'] ?? null) ? $migrationStatus['pending'] : []
        ),
        'database' => $databaseConfig->safeSummary(),
        'database_runtime_requested' => $boolValue($databaseRuntime['enabled'] ?? false),
        'database_runtime_requested_modules' => $requestedModules,
        'maintenance_enabled' => $flags->maintenanceEnabled(),
        'financial_read_only' => $flags->financialReadOnly(),
        'data_directory_readable' => $dataDir !== '' && is_dir($dataDir) && is_readable($dataDir),
        'data_directory_writable' => $dataDir !== '' && is_dir($dataDir) && is_writable($dataDir),
        'private_config_loaded' => is_string($configFile ?? null)
            && is_file((string)$configFile)
            && !$isInside((string)$configFile, $projectRoot),
        'runtime_file_readable' => is_file($runtimeFile) && is_readable($runtimeFile),
        'runtime_file_writable' => is_file($runtimeFile) && is_writable($runtimeFile),
        'cutover_control_state' => $controlState,
        'cutover_control_active' => $controlActive,
        'json_write_block_active' => $dataDir !== '' && is_file($dataDir . '/.cutover-write-block'),
    ];

    $service = new ProductionPreflightService();
    $inventory = $service->inspectSnapshot($snapshot);
    $rollback = [
        'restore_utility_present' => is_file($projectRoot . '/ops/backup/restore.php'),
        'verify_utility_present' => is_file($projectRoot . '/ops/backup/verify.php'),
        'runtime_file_restorable' => is_file($runtimeFile)
            && is_readable($runtimeFile)
            && is_writable($runtimeFile)
            && is_writable(dirname($runtimeFile)),
    ];
    $result = $service->evaluate($runtime, $backups, $inventory, $rollback);
    $result['execution_mode'] = 'read-only';
    $result['generated_at_utc'] = gmdate(DATE_ATOM);
    $result['next_step'] = ($result['technical_ready_for_window'] ?? false) === true
        ? 'Agree an exact maintenance window and issue a separate short-lived cutover approval. Do not switch yet.'
        : 'Resolve every blocker and repeat the read-only production preflight.';

    $print($result, ($result['ok'] ?? false) === true ? 0 : 2);
    $exitCode = ($result['ok'] ?? false) === true ? 0 : 2;
} catch (Throwable $error) {
    $exitCode = 1;
    $print([
        'ok' => false,
        'report_type' => 'mvp-14.8.5-production-preflight',
        'execution_mode' => 'read-only',
        'production_switch_allowed' => false,
        'production_switch_performed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'error_class' => get_class($error),
        'error_message' => $safeMessage($error->getMessage()),
    ], 1);
} finally {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

exit($exitCode);
