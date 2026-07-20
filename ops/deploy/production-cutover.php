<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

@set_time_limit(240);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/accounts/LegacyAccountImportService.php';
require_once $projectRoot . '/bot/accounts/LegacyAccountOwnershipLinkService.php';
require_once $projectRoot . '/bot/ledger/LegacyOpeningBalanceImportService.php';
require_once $projectRoot . '/bot/migration/LegacyRealtimeNormalizedImportService.php';
require_once $projectRoot . '/bot/operations/StagingOperationDefinition.php';
require_once $projectRoot . '/bot/operations/StagingDatabaseRuntimeRegressionOperation.php';
require_once $projectRoot . '/bot/cutover/ProductionPreflightService.php';
require_once $projectRoot . '/bot/cutover/ProductionPreflightRunner.php';
require_once $projectRoot . '/bot/cutover/ProductionCutoverConfig.php';
require_once $projectRoot . '/bot/cutover/ProductionCutoverRunner.php';
require_once $projectRoot . '/ops/backup/BackupConfigLoader.php';
require_once $projectRoot . '/ops/backup/BackupManager.php';

$options = getopt('', ['run', 'status', 'rollback']);
$modeCount = (int)isset($options['run']) + (int)isset($options['status']) + (int)isset($options['rollback']);
$cutoverLockHandle = null;
$migrationLockHandle = null;
$exitCode = 0;

$normalizePath = static fn(string $path): string => rtrim(str_replace('\\', '/', trim($path)), '/');
$isInside = static function (string $path, string $parent) use ($normalizePath): bool {
    $path = $normalizePath($path);
    $parent = $normalizePath($parent);
    return $path === $parent || str_starts_with($path . '/', $parent . '/');
};
$print = static function (array $result): void {
    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
};
$safeMessage = static function (string $message): string {
    $message = preg_replace('~/(?:home|var|tmp|srv)/[^\s\'\"]+~', '[private-path]', $message) ?? $message;
    $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $message) ?? $message;
    return mb_substr(trim($message), 0, 500);
};

try {
    if ($modeCount !== 1) {
        throw new InvalidArgumentException('Choose exactly one mode: --run, --status or --rollback.');
    }

    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($environment !== 'production') {
        throw new RuntimeException('Controlled cutover entrypoint is enabled only in production.');
    }

    $configPath = is_string($configFile ?? null) ? $configFile : '';
    $privateDir = $configPath !== '' ? dirname($configPath) : dirname($projectRoot) . '/_private_mgw';
    if ($privateDir === '' || $isInside($privateDir, $projectRoot) || !is_dir($privateDir)) {
        throw new RuntimeException('Production cutover private directory is unavailable or unsafe.');
    }

    $cutoverLockFile = $privateDir . '/production-cutover.lock';
    $migrationSettings = is_array($config['managed_migrations'] ?? null)
        ? $config['managed_migrations']
        : [];
    $migrationLockFile = trim((string)($migrationSettings['lock_file'] ?? ($privateDir . '/managed-migrations.lock')));
    foreach ([$cutoverLockFile, $migrationLockFile] as $lockFile) {
        if ($lockFile === '' || $isInside($lockFile, $projectRoot)) {
            throw new RuntimeException('Production cutover lock files must remain outside the deployed project.');
        }
    }

    $cutoverLockHandle = fopen($cutoverLockFile, 'c+');
    if ($cutoverLockHandle === false) throw new RuntimeException('Could not open the production cutover lock.');
    @chmod($cutoverLockFile, 0600);
    if (!flock($cutoverLockHandle, LOCK_EX | LOCK_NB)) {
        $print([
            'ok' => true,
            'report_type' => 'mvp-14.9-production-cutover',
            'action' => 'run_noop',
            'reason' => 'cutover_already_running',
            'idempotent' => true,
            'environment' => 'production',
            'build' => FeatureFlagService::BUILD,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => gmdate(DATE_ATOM),
        ]);
        exit(0);
    }

    $migrationLockHandle = fopen($migrationLockFile, 'c+');
    if ($migrationLockHandle === false) throw new RuntimeException('Could not open the shared migration lock.');
    @chmod($migrationLockFile, 0600);
    if (!flock($migrationLockHandle, LOCK_EX | LOCK_NB)) {
        $print([
            'ok' => true,
            'report_type' => 'mvp-14.9-production-cutover',
            'action' => 'run_noop',
            'reason' => 'managed_migrations_running',
            'idempotent' => true,
            'environment' => 'production',
            'build' => FeatureFlagService::BUILD,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => gmdate(DATE_ATOM),
        ]);
        exit(0);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('Production database is not enabled.');
    }
    $database = PdoConnectionFactory::create($databaseConfig);
    $storage = StorageFactory::create($config);

    $backupSettings = BackupConfigLoader::load($projectRoot, 'production');
    $backupManager = new BackupManager(
        $projectRoot,
        (string)($config['data_dir'] ?? ''),
        (string)$backupSettings['backup_root'],
        isset($backupSettings['external_dir']) ? (string)$backupSettings['external_dir'] : null,
        (int)$backupSettings['retention_days'],
        (int)$backupSettings['retention_count'],
        (bool)$backupSettings['include_release_files']
    );

    $runner = new ProductionCutoverRunner(
        $projectRoot,
        $config,
        $configPath,
        $storage,
        $database,
        $backupManager,
        ProductionCutoverConfig::fromApplicationConfig($config)
    );

    if (isset($options['status'])) {
        $result = $runner->status();
    } elseif (isset($options['rollback'])) {
        $result = $runner->rollback('manual production rollback requested by approved operator');
    } else {
        $result = $runner->run();
    }

    $ok = ($result['ok'] ?? false) === true;
    $print($result);
    $exitCode = $ok ? 0 : 2;
} catch (Throwable $error) {
    $exitCode = 1;
    $print([
        'ok' => false,
        'report_type' => 'mvp-14.9-production-cutover',
        'environment' => (string)($config['environment'] ?? 'unknown'),
        'build' => class_exists('FeatureFlagService') ? FeatureFlagService::BUILD : 'unknown',
        'error_class' => get_class($error),
        'error_message' => $safeMessage($error->getMessage()),
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ]);
} finally {
    if (is_resource($migrationLockHandle)) {
        flock($migrationLockHandle, LOCK_UN);
        fclose($migrationLockHandle);
    }
    if (is_resource($cutoverLockHandle)) {
        flock($cutoverLockHandle, LOCK_UN);
        fclose($cutoverLockHandle);
    }
}

exit($exitCode);
