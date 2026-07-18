<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/bot/core/bootstrap.php';
require_once dirname(__DIR__, 2) . '/bot/realtime/LegacyRealtimeShadowSyncService.php';
require_once dirname(__DIR__, 2) . '/bot/ledger/LedgerIntegrity.php';
require_once dirname(__DIR__, 2) . '/bot/ledger/LedgerWriteService.php';
require_once dirname(__DIR__, 2) . '/bot/ledger/LedgerIntegrityVerifier.php';
require_once dirname(__DIR__, 2) . '/bot/ledger/LegacyEconomyShadowSyncService.php';
require_once dirname(__DIR__, 2) . '/bot/ledger/LegacyOpeningBalanceImportService.php';
require_once dirname(__DIR__, 2) . '/bot/ledger/LegacyFinancialStatusNormalizer.php';
require_once dirname(__DIR__, 2) . '/bot/ledger/LegacyFinancialArchiveImportService.php';
require_once dirname(__DIR__, 2) . '/bot/migration/StagingJsonDbReconciliationService.php';
require_once dirname(__DIR__, 2) . '/bot/migration/LegacyOpeningBalanceOwnershipReconciliationService.php';
require_once dirname(__DIR__, 2) . '/bot/migration/StagingJsonDbFinalReconciliationService.php';

$exitCode = 0;
$lockHandle = null;

try {
    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($environment === 'production') {
        throw new RuntimeException('Staging JSON to DB reconciliation is disabled in production.');
    }
    if (!in_array($environment, ['staging', 'local'], true)) {
        throw new RuntimeException('Staging JSON to DB reconciliation requires staging or local environment.');
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('Database is not enabled in the private configuration.');
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $migrationStatus = (new MigrationRunner(
        $database,
        dirname(__DIR__, 2) . '/bot/database/migrations'
    ))->status();
    if ((int)($migrationStatus['pending_count'] ?? 0) > 0) {
        throw new RuntimeException('Database schema has pending migrations.');
    }

    $privateDir = is_string($configFile ?? null)
        ? dirname($configFile)
        : dirname(__DIR__, 2) . '/_private_mgw';
    if (!is_dir($privateDir)) {
        throw new RuntimeException('Private runtime directory is unavailable.');
    }
    $lockHandle = fopen($privateDir . '/staging-json-db-reconciliation.lock', 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another staging reconciliation report is already running.');
    }

    $storage = StorageFactory::create($config);
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

    $result = (new StagingJsonDbFinalReconciliationService(
        $database,
        $realtimeShadow,
        $economyShadow,
        $openingBalances,
        $financialArchive
    ))->report();
    $result['environment'] = $environment;
    $result['storage_driver'] = $storage->driver();
    $result['database'] = $databaseConfig->safeSummary();
    $result['generated_at_utc'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->format('Y-m-d H:i:s.u');

    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
} catch (Throwable $error) {
    $exitCode = 1;
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
} finally {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

exit($exitCode);
