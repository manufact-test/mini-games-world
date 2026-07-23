<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

@set_time_limit(300);
umask(0077);

$options = getopt('', [
    'package',
    'preflight',
    'run',
    'status',
    'release',
    'rollback',
    'rearm',
]);
$modes = [
    'package',
    'preflight',
    'run',
    'status',
    'release',
    'rollback',
    'rearm',
];
$selected = array_values(array_filter(
    $modes,
    static fn(string $mode): bool => isset($options[$mode])
));
$requestedMode = count($selected) === 1 ? $selected[0] : 'invalid';
$projectRoot = dirname(__DIR__, 2);

$print = static function (array $result): void {
    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
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
$exitCode = 1;
$config = [];

try {
    if (count($selected) !== 1) {
        throw new InvalidArgumentException(
            'Choose exactly one mode: --package, --preflight, --run, --status, '
            . '--release, --rollback or --rearm.'
        );
    }

    require_once $projectRoot . '/bot/cutover/ProductionCutoverPackageManifest.php';
    if ($requestedMode === 'package') {
        $manifest = (new ProductionCutoverPackageManifest($projectRoot))->inspect();
        $print($manifest + [
            'report_type' => 'mvp-14.10e-production-cutover-package',
            'action' => 'package_status',
            'requested_mode' => 'package',
        ]);
        exit(($manifest['ready'] ?? false) === true ? 0 : 2);
    }

    if (!defined('MINIGAMES_CUTOVER_CONTROL_BOOTSTRAP')) {
        define('MINIGAMES_CUTOVER_CONTROL_BOOTSTRAP', true);
    }
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
    require_once $projectRoot . '/bot/cutover/ProductionCutoverExactPreflight.php';
    require_once $projectRoot . '/bot/cutover/ProductionCutoverReleaseReceiptVerifier.php';
    require_once $projectRoot . '/bot/cutover/ProductionCutoverRunner.php';
    require_once $projectRoot . '/ops/backup/BackupConfigLoader.php';
    require_once $projectRoot . '/ops/backup/BackupManager.php';

    if (($config['environment'] ?? null) !== 'production') {
        throw new RuntimeException(
            'Controlled cutover entrypoint requires the exact production environment.'
        );
    }
    if (($config['storage_driver'] ?? null) !== RuntimeStorageRouter::DRIVER_JSON) {
        throw new RuntimeException(
            'Controlled cutover entrypoint requires JSON as the global rollback driver.'
        );
    }

    $configPath = is_string($configFile ?? null) ? $configFile : '';
    if ($configPath === '' || is_link($configPath) || !is_file($configPath)) {
        throw new RuntimeException('Production cutover private config is unavailable.');
    }
    $canonicalConfig = realpath($configPath);
    if (!is_string($canonicalConfig) || !hash_equals($configPath, $canonicalConfig)) {
        throw new RuntimeException('Production cutover private config path is not canonical.');
    }
    clearstatcache(true, $configPath);
    $configMode = fileperms($configPath);
    if (!is_int($configMode) || ($configMode & 0777) !== 0600) {
        throw new RuntimeException('Production cutover private config must have exact mode 0600.');
    }

    $privateDir = dirname($configPath);
    if (is_link($privateDir) || !is_dir($privateDir)) {
        throw new RuntimeException('Production cutover private directory is unavailable.');
    }
    $canonicalPrivate = realpath($privateDir);
    if (!is_string($canonicalPrivate) || !hash_equals($privateDir, $canonicalPrivate)) {
        throw new RuntimeException('Production cutover private directory is not canonical.');
    }
    if ($privateDir === $projectRoot || str_starts_with($privateDir . '/', $projectRoot . '/')) {
        throw new RuntimeException('Production cutover private directory must remain outside deployment.');
    }
    clearstatcache(true, $privateDir);
    $privateMode = fileperms($privateDir);
    if (!is_int($privateMode) || ($privateMode & 0022) !== 0) {
        throw new RuntimeException('Production cutover private directory is group/world writable.');
    }

    $lockFile = $privateDir . '/production-cutover.lock';
    $cutoverLockHandle = fopen($lockFile, 'c+');
    if ($cutoverLockHandle === false) {
        throw new RuntimeException('Could not open the production cutover lock.');
    }
    chmod($lockFile, 0600);
    $lockMode = $requestedMode === 'status' ? LOCK_SH : LOCK_EX;
    if (!flock($cutoverLockHandle, $lockMode | LOCK_NB)) {
        $print([
            'ok' => false,
            'report_type' => 'mvp-14.10e-production-cutover-package',
            'action' => $requestedMode . '_blocked',
            'requested_mode' => $requestedMode,
            'reason' => 'cutover_operation_already_running',
            'retry_required' => true,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => gmdate(DATE_ATOM),
        ]);
        exit(2);
    }

    $policy = ProductionCutoverConfig::fromApplicationConfig($config);
    if ($requestedMode === 'preflight') {
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
            throw new RuntimeException('Managed migration lock path is invalid.');
        }
        $migrationLockHandle = fopen($migrationLockFile, 'c+');
        if ($migrationLockHandle === false) {
            throw new RuntimeException('Could not open the shared migration lock.');
        }
        chmod($migrationLockFile, 0600);
        if (!flock($migrationLockHandle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Managed migrations are currently running.');
        }

        $result = (new ProductionCutoverExactPreflight(
            $projectRoot,
            $config,
            $configPath,
            $policy
        ))->run();
        $print($result);
        $exitCode = ($result['technical_ready_for_window'] ?? false) === true ? 0 : 2;
    } else {
        $storage = null;
        $database = null;
        $backupManager = null;

        if (in_array($requestedMode, ['run', 'release'], true)) {
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
                throw new RuntimeException('Managed migration lock path is invalid.');
            }
            $migrationLockHandle = fopen($migrationLockFile, 'c+');
            if ($migrationLockHandle === false) {
                throw new RuntimeException('Could not open the shared migration lock.');
            }
            chmod($migrationLockFile, 0600);
            if (!flock($migrationLockHandle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Managed migrations are currently running.');
            }

            $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
            if (!$databaseConfig->enabled()) {
                throw new RuntimeException('Production database is not enabled.');
            }
            $database = PdoConnectionFactory::create($databaseConfig);
            if ((int)$database->fetchValue('SELECT 1') !== 1) {
                throw new RuntimeException('Production database readiness probe failed.');
            }
            $storage = StorageFactory::create($config);
        }

        if ($requestedMode === 'run') {
            $backupSettings = BackupConfigLoader::load($projectRoot, 'production');
            $backupManager = new BackupManager(
                $projectRoot,
                (string)($config['data_dir'] ?? ''),
                (string)$backupSettings['backup_root'],
                isset($backupSettings['external_dir'])
                    ? (string)$backupSettings['external_dir']
                    : null,
                (int)$backupSettings['retention_days'],
                (int)$backupSettings['retention_count'],
                (bool)$backupSettings['include_release_files']
            );
        }

        $runner = new ProductionCutoverRunner(
            $projectRoot,
            $config,
            $configPath,
            $storage,
            $database,
            $backupManager,
            $policy
        );
        $result = match ($requestedMode) {
            'status' => $runner->status(),
            'release' => $runner->release(),
            'rollback' => $runner->rollback(
                'manual production rollback requested through the controlled package'
            ),
            'rearm' => $runner->rearm(),
            default => $runner->run(),
        };
        $print($result);
        $exitCode = ($result['ok'] ?? false) === true ? 0 : 2;
    }
} catch (Throwable $error) {
    $print([
        'ok' => false,
        'report_type' => 'mvp-14.10e-production-cutover-package',
        'requested_mode' => $requestedMode,
        'environment' => is_array($config) ? (string)($config['environment'] ?? 'unknown') : 'unknown',
        'build' => class_exists('ProductionCutoverRunner')
            ? ProductionCutoverRunner::BUILD
            : ProductionCutoverPackageManifest::BUILD,
        'package_version' => class_exists('ProductionCutoverRunner')
            ? ProductionCutoverRunner::PACKAGE_VERSION
            : ProductionCutoverPackageManifest::PACKAGE_VERSION,
        'error_class' => get_class($error),
        'error_message' => $safeMessage($error->getMessage()),
        'production_changed' => false,
        'webhook_changed' => false,
        'cron_changed' => false,
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
