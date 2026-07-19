<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/ledger/LedgerIntegrityVerifier.php';
require $root . '/realtime/LegacyRealtimeShadowSyncService.php';
require $root . '/ledger/LegacyEconomyShadowSyncService.php';
require $root . '/ledger/LegacyOpeningBalanceImportService.php';
require $root . '/ledger/LegacyFinancialArchiveImportService.php';
require $root . '/ledger/LegacyFinancialArchiveDeltaService.php';
require $root . '/ledger/LegacyEconomyDeltaImportService.php';
require $root . '/ledger/LegacyEconomyRuntimeReconciliationService.php';
require $root . '/migration/StagingJsonDbReconciliationService.php';
require $root . '/migration/StagingJsonDbFinalReconciliationService.php';
require $root . '/migration/StagingJsonDbFinalDeltaService.php';

$classes = [
    LegacyFinancialArchiveDeltaService::class,
    LegacyEconomyDeltaImportService::class,
    LegacyEconomyRuntimeReconciliationService::class,
    StagingJsonDbFinalDeltaService::class,
];
foreach ($classes as $class) {
    if (!class_exists($class)) throw new RuntimeException('Class did not load: ' . $class);
}

fwrite(STDOUT, "FinalDeltaClassLoadTest passed: " . count($classes) . " classes.\n");
