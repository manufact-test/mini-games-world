<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

@set_time_limit(300);
umask(0077);

$projectRoot = dirname(__DIR__, 2);
$print = static function (array $result): void {
    fwrite(STDOUT, json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
};
$safeMessage = static function (string $message): string {
    $message = preg_replace(
        '~/(?:home|var|tmp|srv)/[^\s\'\"]+~',
        '[private-path]',
        $message
    ) ?? $message;
    $message = preg_replace(
        '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
        '[redacted-email]',
        $message
    ) ?? $message;
    return mb_substr(trim($message), 0, 500);
};

$cutoverLockHandle = null;
$migrationLockHandle = null;
$config = [];
$exitCode = 1;

try {
    require $projectRoot . '/bot/core/bootstrap.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionWorkerInterface.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionAuditorInterface.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionProjectorInterface.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryModuleProjectorInterface.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryCallbackModuleProjector.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryAccountsModuleProjector.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryAllModuleProjector.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryRepositoryProjectorFactory.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxWriter.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionAuditorAdapter.php';
    require_once $projectRoot . '/bot/runtime/ProductionPrimaryRuntimeActivationContract.php';
    require_once $projectRoot . '/bot/cutover/ProductionCutoverConfig.php';
    require_once $projectRoot . '/bot/cutover/ProductionCutoverPackageManifest.php';
    require_once $projectRoot . '/bot/cutover/ProductionRuntimePrimaryContract.php';
    require_once $projectRoot . '/bot/cutover/ProductionCutoverReleaseReceiptVerifier.php';
    require_once $projectRoot . '/bot/cutover/ProductionCutoverRunner.php';
    require_once $projectRoot . '/bot/cutover/ProductionCutoverReleaseSmokeService.php';

    if (($config['environment'] ?? null) !== 'production') {
        throw new RuntimeException('Release smoke requires the exact production environment.');
    }
    if (($config['storage_driver'] ?? null) !== RuntimeStorageRouter::DRIVER_JSON) {
        throw new RuntimeException('Release smoke requires JSON as the global rollback driver.');
    }

    $configPath = is_string($configFile ?? null) ? $configFile : '';
    if ($configPath === '' || is_link($configPath) || !is_file($configPath)) {
        throw new RuntimeException('Release smoke private config is unavailable.');
    }
    $canonicalConfig = realpath($configPath);
    if (!is_string($canonicalConfig) || !hash_equals($configPath, $canonicalConfig)) {
        throw new RuntimeException('Release smoke private config is not canonical.');
    }
    clearstatcache(true, $configPath);
    $configMode = fileperms($configPath);
    if (!is_int($configMode) || ($configMode & 0777) !== 0600) {
        throw new RuntimeException('Release smoke private config must have exact mode 0600.');
    }
    $privateDir = dirname($configPath);
    if (is_link($privateDir) || !is_dir($privateDir)) {
        throw new RuntimeException('Release smoke private directory is unavailable.');
    }
    $canonicalPrivate = realpath($privateDir);
    if (!is_string($canonicalPrivate) || !hash_equals($privateDir, $canonicalPrivate)) {
        throw new RuntimeException('Release smoke private directory is not canonical.');
    }
    clearstatcache(true, $privateDir);
    $privateMode = fileperms($privateDir);
    if (!is_int($privateMode) || ($privateMode & 0022) !== 0) {
        throw new RuntimeException('Release smoke private directory is group/world writable.');
    }

    $cutoverLockFile = $privateDir . '/production-cutover.lock';
    $cutoverLockHandle = fopen($cutoverLockFile, 'c+');
    if ($cutoverLockHandle === false) {
        throw new RuntimeException('Could not open the production cutover lock.');
    }
    chmod($cutoverLockFile, 0600);
    if (!flock($cutoverLockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another production cutover operation is already running.');
    }

    $migrationSettings = is_array($config['managed_migrations'] ?? null)
        ? $config['managed_migrations']
        : [];
    $migrationLockFile = trim((string)(
        $migrationSettings['lock_file'] ?? ($privateDir . '/managed-migrations.lock')
    ));
    if ($migrationLockFile === ''
        || !str_starts_with($migrationLockFile, '/')
        || str_contains($migrationLockFile, '\\')
        || is_link($migrationLockFile)) {
        throw new RuntimeException('Release smoke migration lock path is invalid.');
    }
    $migrationLockHandle = fopen($migrationLockFile, 'c+');
    if ($migrationLockHandle === false) {
        throw new RuntimeException('Could not open the release smoke migration lock.');
    }
    chmod($migrationLockFile, 0600);
    if (!flock($migrationLockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Managed migrations are currently running.');
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('Release smoke database is not enabled.');
    }
    $database = PdoConnectionFactory::create($databaseConfig);
    $jsonStorage = StorageFactory::create($config);
    $policy = ProductionCutoverConfig::fromApplicationConfig($config);

    $result = (new ProductionCutoverReleaseSmokeService(
        $projectRoot,
        $config,
        $configPath,
        $database,
        $jsonStorage,
        $policy
    ))->run();
    $print($result);
    $exitCode = ($result['ok'] ?? false) === true ? 0 : 2;
} catch (Throwable $error) {
    $print([
        'ok' => false,
        'report_type' => 'mvp-14.10e-production-cutover-package',
        'action' => 'release_smoke_blocked',
        'environment' => is_array($config) ? (string)($config['environment'] ?? 'unknown') : 'unknown',
        'error_class' => get_class($error),
        'error_message' => $safeMessage($error->getMessage()),
        'database_write_executed' => false,
        'persistent_config_changed' => false,
        'webhook_changed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ]);
    $exitCode = 1;
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
