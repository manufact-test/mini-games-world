<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/realtime/LegacyRealtimeShadowSyncService.php';
require_once $projectRoot . '/bot/ledger/LedgerIntegrity.php';
require_once $projectRoot . '/bot/ledger/LedgerWriteService.php';
require_once $projectRoot . '/bot/ledger/LedgerIntegrityVerifier.php';
require_once $projectRoot . '/bot/ledger/LegacyEconomyShadowSyncService.php';
require_once $projectRoot . '/bot/ledger/LegacyOpeningBalanceImportService.php';
require_once $projectRoot . '/bot/ledger/LegacyFinancialStatusNormalizer.php';
require_once $projectRoot . '/bot/ledger/LegacyFinancialArchiveImportService.php';
require_once $projectRoot . '/bot/migration/StagingJsonDbReconciliationService.php';
require_once $projectRoot . '/bot/migration/LegacyOpeningBalanceOwnershipReconciliationService.php';
require_once $projectRoot . '/bot/migration/StagingJsonDbFinalReconciliationService.php';
require_once $projectRoot . '/bot/cutover/CutoverReadinessService.php';
require_once $projectRoot . '/ops/backup/BackupConfigLoader.php';
require_once $projectRoot . '/ops/backup/BackupManager.php';

$exitCode = 0;
$lockHandle = null;

$verifyLatestBackup = static function (BackupManager $manager, ?string $root, string $label): array {
    if ($root === null || trim($root) === '') {
        return ['ok' => false, 'source' => $label, 'error' => 'backup source is not configured'];
    }
    try {
        $snapshot = $manager->latestSnapshot($root);
        $verified = $manager->verify($snapshot);
        $manifest = is_array($verified['manifest'] ?? null) ? $verified['manifest'] : [];
        return [
            'ok' => true,
            'source' => $label,
            'backup_id' => (string)($verified['backup_id'] ?? ''),
            'snapshot_sha256' => (string)($verified['snapshot_sha256'] ?? ''),
            'created_at_utc' => (string)($manifest['created_at_utc'] ?? ''),
            'environment' => (string)($manifest['environment'] ?? ''),
            'verified_files' => (int)($verified['verified_files'] ?? 0),
            'verified_bytes' => (int)($verified['verified_bytes'] ?? 0),
        ];
    } catch (Throwable $error) {
        return ['ok' => false, 'source' => $label, 'error' => $error->getMessage()];
    }
};

try {
    $environmentValue = $config['environment'] ?? 'production';
    $environment = strtolower(trim($environmentValue instanceof BackedEnum
        ? (string)$environmentValue->value
        : (string)$environmentValue));
    if (!in_array($environment, ['staging', 'local'], true)) {
        throw new RuntimeException('MVP-14.8.1 readiness is disabled outside staging/local.');
    }

    $privateDir = is_string($configFile ?? null)
        ? dirname($configFile)
        : dirname($projectRoot) . '/_private_mgw';
    if (!is_dir($privateDir)) {
        throw new RuntimeException('Private runtime directory is unavailable.');
    }
    $lockHandle = fopen($privateDir . '/cutover-readiness.lock', 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another cutover readiness report is already running.');
    }

    $storage = StorageFactory::create($config);
    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('Database is not enabled in the private configuration.');
    }
    $database = PdoConnectionFactory::create($databaseConfig);
    $databaseConnected = (int)$database->fetchValue('SELECT 1') === 1;
    $migrationStatus = (new MigrationRunner(
        $database,
        $projectRoot . '/bot/database/migrations'
    ))->status();

    $realtimeShadow = new LegacyRealtimeShadowSyncService($storage, $database);
    $economyShadow = new LegacyEconomyShadowSyncService($storage, $database);
    $openingBalances = new LegacyOpeningBalanceImportService(
        $database,
        new LedgerWriteService($database),
        new LedgerIntegrityVerifier($database)
    );
    $financialArchive = new LegacyFinancialArchiveImportService(
        $storage,
        $database,
        new LegacyFinancialStatusNormalizer()
    );
    $reconciliation = (new StagingJsonDbFinalReconciliationService(
        $database,
        $realtimeShadow,
        $economyShadow,
        $openingBalances,
        $financialArchive
    ))->report();

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
        'primary' => $verifyLatestBackup($backupManager, (string)$backupSettings['backup_root'], 'primary'),
        'external' => $verifyLatestBackup(
            $backupManager,
            isset($backupSettings['external_dir']) ? (string)$backupSettings['external_dir'] : null,
            'external'
        ),
    ];

    $readiness = new CutoverReadinessService($projectRoot);
    $sourceInventory = $readiness->inspectSource();
    $runtime = [
        'environment' => $environment,
        'storage_driver' => $storage->driver(),
        'database_enabled' => $databaseConfig->enabled(),
        'database_connected' => $databaseConnected,
        'schema_current' => (int)($migrationStatus['pending_count'] ?? -1) === 0,
        'available_migrations' => (int)($migrationStatus['available_count'] ?? 0),
        'applied_migrations' => (int)($migrationStatus['applied_count'] ?? 0),
        'pending_migrations' => (int)($migrationStatus['pending_count'] ?? -1),
        'database' => $databaseConfig->safeSummary(),
    ];
    $result = $readiness->evaluate($runtime, $reconciliation, $backups, $sourceInventory);
    $result['report_type'] = 'mvp-14.8.1-cutover-readiness';
    $result['generated_at_utc'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->format('Y-m-d H:i:s.u');
    $result['execution_mode'] = 'read-only';
    $result['next_step'] = $result['ready_for_mvp_14_8_2']
        ? 'Implement the DB runtime adapter behind a staging-only feature flag.'
        : 'Resolve every blocker and repeat this report.';

    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    $exitCode = $result['ready_for_mvp_14_8_2'] ? 0 : 1;
} catch (Throwable $error) {
    $exitCode = 1;
    fwrite(STDERR, json_encode([
        'ok' => false,
        'report_type' => 'mvp-14.8.1-cutover-readiness',
        'production_cutover_allowed' => false,
        'production_switch_performed' => false,
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
} finally {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

exit($exitCode);
