<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/cutover/seal/SealedSnapshotControlService.php';
require_once $projectRoot . '/bot/cutover/frozen/FrozenSnapshotRehearsalService.php';
require_once $projectRoot . '/ops/backup/BackupConfigLoader.php';
require_once $projectRoot . '/ops/backup/BackupManager.php';
require_once $projectRoot . '/bot/realtime/LegacyRealtimeShadowSyncService.php';
require_once $projectRoot . '/bot/ledger/LedgerIntegrity.php';
require_once $projectRoot . '/bot/ledger/LedgerWriteService.php';
require_once $projectRoot . '/bot/ledger/LedgerIntegrityVerifier.php';
require_once $projectRoot . '/bot/ledger/LegacyEconomyShadowSyncService.php';
require_once $projectRoot . '/bot/ledger/LegacyOpeningBalanceImportService.php';
require_once $projectRoot . '/bot/ledger/LegacyFinancialStatusNormalizer.php';
require_once $projectRoot . '/bot/ledger/LegacyFinancialArchiveImportService.php';
require_once $projectRoot . '/bot/ledger/LegacyEconomyDeltaImportService.php';
require_once $projectRoot . '/bot/ledger/LegacyEconomyRuntimeReconciliationService.php';
require_once $projectRoot . '/bot/migration/StagingJsonDbReconciliationService.php';
require_once $projectRoot . '/bot/migration/StagingJsonDbFinalReconciliationService.php';

$options = getopt('', ['prepare', 'status']);
$modeCount = (int)isset($options['prepare']) + (int)isset($options['status']);
$lockHandle = null;
$exitCode = 0;

$detectBuild = static function (string $root): string {
    $source = is_file($root . '/app/index.html') ? (file_get_contents($root . '/app/index.html') ?: '') : '';
    if (preg_match('/data-build=["\']([^"\']+)["\']/', $source, $matches)) return trim($matches[1]);
    return FeatureFlagService::BUILD;
};

try {
    if ($modeCount > 1) throw new InvalidArgumentException('Choose only one mode: --prepare or --status.');
    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($environment !== 'staging') throw new RuntimeException('Frozen snapshot rehearsal CLI is enabled only in staging.');

    $storage = StorageFactory::create($config);
    if ($storage->driver() !== 'json') throw new RuntimeException('Global JSON rollback storage must remain active.');
    $privateDir = is_string($configFile ?? null) ? dirname($configFile) : dirname($projectRoot) . '/_private_mgw';
    if (!is_dir($privateDir)) throw new RuntimeException('Private runtime directory is unavailable.');

    $lockHandle = fopen($privateDir . '/cutover-rehearsal.lock', 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another cutover rehearsal command is already running.');
    }

    $backupSettings = BackupConfigLoader::load($projectRoot, $environment);
    $externalRoot = trim((string)($backupSettings['external_dir'] ?? ''));
    if ($externalRoot === '') throw new RuntimeException('Frozen snapshot rehearsal requires an external backup directory.');
    $router = $runtimeStorageRouter instanceof RuntimeStorageRouter ? $runtimeStorageRouter : new RuntimeStorageRouter($config);
    $service = new FrozenSnapshotRehearsalService(
        $config,
        new SealedSnapshotControlService($config, $storage, $privateDir . '/cutover-rehearsal.json'),
        new BackupManager(
            $projectRoot,
            (string)($config['data_dir'] ?? ''),
            (string)$backupSettings['backup_root'],
            $externalRoot,
            (int)$backupSettings['retention_days'],
            (int)$backupSettings['retention_count'],
            (bool)$backupSettings['include_release_files']
        ),
        $router,
        (string)$backupSettings['backup_root'],
        $externalRoot,
        $privateDir . '/cutover-restore-rehearsals',
        $privateDir . '/frozen-snapshot-rehearsal.json'
    );

    if (isset($options['prepare'])) {
        $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
        if (!$databaseConfig->enabled()) throw new RuntimeException('Database is not enabled in the private configuration.');
        $database = PdoConnectionFactory::create($databaseConfig);
        $migrationStatus = (new MigrationRunner($database, $projectRoot . '/bot/database/migrations'))->status();
        if ((int)($migrationStatus['pending_count'] ?? -1) !== 0) throw new RuntimeException('Database schema has pending migrations.');

        $reconciliation = static function () use ($storage, $database): array {
            return (new StagingJsonDbFinalReconciliationService(
                $database,
                new LegacyRealtimeShadowSyncService($storage, $database),
                new LegacyEconomyShadowSyncService($storage, $database),
                new LegacyOpeningBalanceImportService(
                    $database,
                    new LedgerWriteService($database),
                    new LedgerIntegrityVerifier($database)
                ),
                new LegacyFinancialArchiveImportService(
                    $storage,
                    $database,
                    new LegacyFinancialStatusNormalizer()
                )
            ))->report();
        };
        $result = $service->prepare($detectBuild($projectRoot), $reconciliation);
        $result['execution_mode'] = 'prepare';
        $result['database_driver'] = $database->driver();
        $result['schema_current'] = true;
        $result['applied_migrations'] = (int)($migrationStatus['applied_count'] ?? 0);
        if (($result['ok'] ?? false) !== true) $exitCode = 2;
    } else {
        $result = $service->status();
        $result['execution_mode'] = 'status';
    }

    $result['storage_driver'] = $storage->driver();
    $result['state_file_private'] = true;
    $result['production_changed'] = false;
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $error) {
    $exitCode = 1;
    fwrite(STDERR, json_encode([
        'ok' => false,
        'report_type' => 'mvp-14.8.4-frozen-snapshot-rehearsal',
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
