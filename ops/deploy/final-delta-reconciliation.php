<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/cutover/seal/SealedSnapshotControlService.php';
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

$options = getopt('', ['preview', 'run']);
$modeCount = (int)isset($options['preview']) + (int)isset($options['run']);
$lockHandle = null;
$exitCode = 0;

try {
    if ($modeCount > 1) throw new InvalidArgumentException('Choose only one mode: --preview or --run.');
    $mode = isset($options['run']) ? 'run' : 'preview';
    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($environment !== 'staging') {
        throw new RuntimeException('Final delta reconciliation CLI is enabled only in staging.');
    }

    $storage = StorageFactory::create($config);
    if ($storage->driver() !== 'json') {
        throw new RuntimeException('Global JSON rollback storage must remain active.');
    }
    $privateDir = is_string($configFile ?? null)
        ? dirname($configFile)
        : dirname($projectRoot) . '/_private_mgw';
    if (!is_dir($privateDir)) throw new RuntimeException('Private runtime directory is unavailable.');

    $lockHandle = fopen($privateDir . '/cutover-rehearsal.lock', 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another cutover rehearsal command is already running.');
    }

    $control = new SealedSnapshotControlService(
        $config,
        $storage,
        $privateDir . '/cutover-rehearsal.json'
    );
    $controlStatus = $control->status();
    if (empty($controlStatus['control_consistency']['ok'])
        || empty($controlStatus['freeze']['active'])
        || empty($controlStatus['freeze']['sealed'])
        || empty($controlStatus['freeze']['storage_write_block_active'])
        || empty($controlStatus['frozen_snapshot']['ready'])) {
        throw new RuntimeException('Final delta reconciliation requires a consistent sealed and drained staging snapshot.');
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) throw new RuntimeException('Database is not enabled in the private configuration.');
    $database = PdoConnectionFactory::create($databaseConfig);
    $migrationStatus = (new MigrationRunner($database, $projectRoot . '/bot/database/migrations'))->status();
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
    $service = new StagingJsonDbFinalDeltaService(
        $realtimeShadow,
        $economyShadow,
        new LegacyFinancialArchiveDeltaService($database, $archiveImport),
        $economyDelta,
        $reconciliation
    );

    $result = $mode === 'run' ? $service->run() : $service->preview();
    $result['report_type'] = 'mvp-14.8.4-final-delta-reconciliation';
    $result['environment'] = $environment;
    $result['execution_mode'] = $mode;
    $result['storage_driver'] = $storage->driver();
    $result['rollback_driver'] = 'json';
    $result['schema_current'] = true;
    $result['applied_migrations'] = (int)($migrationStatus['applied_count'] ?? 0);
    $result['sealed_snapshot'] = true;
    $result['production_changed'] = false;
    $result['generated_at_utc'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->format('Y-m-d H:i:s.u');
    if (empty($result['ok'])) $exitCode = 2;

    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
} catch (Throwable $error) {
    $exitCode = 1;
    fwrite(STDERR, json_encode([
        'ok' => false,
        'report_type' => 'mvp-14.8.4-final-delta-reconciliation',
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
