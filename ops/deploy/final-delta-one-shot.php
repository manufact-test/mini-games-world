<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/cutover/FreezeDrainRehearsalService.php';
require_once $projectRoot . '/bot/cutover/FinalDeltaRehearsalOrchestrator.php';
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
require_once $projectRoot . '/bot/ledger/LegacyFinancialArchiveDeltaService.php';
require_once $projectRoot . '/bot/ledger/LegacyEconomyDeltaImportService.php';
require_once $projectRoot . '/bot/ledger/LegacyEconomyRuntimeReconciliationService.php';
require_once $projectRoot . '/bot/migration/StagingJsonDbReconciliationService.php';
require_once $projectRoot . '/bot/migration/StagingJsonDbFinalReconciliationService.php';
require_once $projectRoot . '/bot/migration/StagingJsonDbFinalDeltaService.php';

$options = getopt('', ['run', 'retry', 'reset', 'status']);
$modeCount = (int)isset($options['run'])
    + (int)isset($options['retry'])
    + (int)isset($options['reset'])
    + (int)isset($options['status']);
$lockHandle = null;
$sealedControl = null;
$exitCode = 0;

$detectBuild = static function (string $root): string {
    $source = is_file($root . '/app/index.html') ? (file_get_contents($root . '/app/index.html') ?: '') : '';
    if (preg_match('/data-build=["\']([^"\']+)["\']/', $source, $matches)) {
        return trim($matches[1]);
    }
    return FeatureFlagService::BUILD;
};

try {
    if ($modeCount > 1) {
        throw new InvalidArgumentException('Choose only one mode: --run, --retry, --reset or --status.');
    }
    $mode = isset($options['status']) ? 'status'
        : (isset($options['retry']) ? 'retry'
            : (isset($options['reset']) ? 'reset' : 'run'));
    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($environment !== 'staging') {
        throw new RuntimeException('Final delta one-shot is enabled only in staging.');
    }

    $storage = StorageFactory::create($config);
    if ($storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
        throw new RuntimeException('Global JSON rollback storage must remain active.');
    }
    $router = $runtimeStorageRouter instanceof RuntimeStorageRouter
        ? $runtimeStorageRouter
        : new RuntimeStorageRouter($config);
    $privateDir = is_string($configFile ?? null)
        ? dirname($configFile)
        : dirname($projectRoot) . '/_private_mgw';
    if (!is_dir($privateDir)) {
        throw new RuntimeException('Private runtime directory is unavailable.');
    }

    $lockHandle = fopen($privateDir . '/cutover-rehearsal.lock', 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another cutover rehearsal command is already running.');
    }

    $controlFile = $privateDir . '/cutover-rehearsal.json';
    $freeze = new FreezeDrainRehearsalService($config, $storage, $controlFile, $router);
    $sealedControl = new SealedSnapshotControlService($config, $storage, $controlFile);

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

    $ledger = new LedgerWriteService($database);
    $integrity = new LedgerIntegrityVerifier($database);
    $realtimeShadow = new LegacyRealtimeShadowSyncService($storage, $database);
    $economyShadow = new LegacyEconomyShadowSyncService($storage, $database);
    $openingBalances = new LegacyOpeningBalanceImportService($database, $ledger, $integrity);
    $archiveImport = new LegacyFinancialArchiveImportService(
        $storage,
        $database,
        new LegacyFinancialStatusNormalizer()
    );
    $economyDelta = new LegacyEconomyDeltaImportService($database, $ledger, $integrity);
    $reconciliation = new StagingJsonDbFinalReconciliationService(
        $database,
        $realtimeShadow,
        $economyShadow,
        $openingBalances,
        $archiveImport
    );
    $finalDelta = new StagingJsonDbFinalDeltaService(
        $realtimeShadow,
        $economyShadow,
        new LegacyFinancialArchiveDeltaService($database, $archiveImport),
        $economyDelta,
        $reconciliation
    );

    $backupSettings = BackupConfigLoader::load($projectRoot, $environment);
    $externalRoot = trim((string)($backupSettings['external_dir'] ?? ''));
    if ($externalRoot === '') {
        throw new RuntimeException('Final delta one-shot requires an external backup directory.');
    }
    $frozenSnapshot = new FrozenSnapshotRehearsalService(
        $config,
        $sealedControl,
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
    $build = $detectBuild($projectRoot);

    $orchestrator = new FinalDeltaRehearsalOrchestrator(
        static fn(): array => $freeze->freeze(),
        static fn(): array => $freeze->status(),
        static fn(): array => $sealedControl->status(),
        static fn(): array => $sealedControl->seal(),
        static fn(): array => $finalDelta->run(),
        static fn(): array => $frozenSnapshot->prepare(
            $build,
            static fn(): array => $reconciliation->report()
        ),
        static fn(): array => $sealedControl->release('final delta one-shot completed'),
        static fn(): array => $sealedControl->emergencyRelease('recover failed final delta one-shot'),
        $privateDir . '/final-delta-one-shot.json'
    );

    $result = match ($mode) {
        'status' => $orchestrator->status(),
        'retry' => $orchestrator->run(true, false),
        'reset' => $orchestrator->run(false, true),
        default => $orchestrator->run(false, false),
    };
    $result['environment'] = $environment;
    $result['execution_mode'] = $mode;
    $result['storage_driver'] = $storage->driver();
    $result['rollback_driver'] = RuntimeStorageRouter::DRIVER_JSON;
    $result['database_driver'] = $database->driver();
    $result['schema_current'] = true;
    $result['applied_migrations'] = (int)($migrationStatus['applied_count'] ?? 0);
    $result['build'] = $build;
    $result['state_file_private'] = true;
    $result['production_changed'] = false;
    $result['sensitive_identifiers_exposed'] = false;
    unset($result['report_fingerprint']);
    $result['report_fingerprint'] = hash('sha256', LedgerIntegrity::canonicalJson($result));

    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    if (empty($result['ok'])) $exitCode = 2;
} catch (Throwable $error) {
    $recovery = [];
    $recoveryError = '';
    if ($sealedControl instanceof SealedSnapshotControlService) {
        try {
            $recovery = $sealedControl->emergencyRelease('recover one-shot bootstrap failure');
        } catch (Throwable $failure) {
            $recoveryError = $failure->getMessage();
        }
    }
    $exitCode = 1;
    fwrite(STDERR, json_encode([
        'ok' => false,
        'report_type' => 'mvp-14.8.4c-final-delta-one-shot',
        'environment' => (string)($config['environment'] ?? 'unknown'),
        'error_class' => get_class($error),
        'error_message' => $error->getMessage(),
        'recovery' => [
            'attempted' => $sealedControl instanceof SealedSnapshotControlService,
            'ok' => $recoveryError === '' && !empty($recovery['ok']),
            'write_block_active' => (bool)($recovery['freeze']['storage_write_block_active'] ?? false),
            'control_consistent' => (bool)($recovery['control_consistency']['ok'] ?? false),
            'error_message' => $recoveryError,
        ],
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
