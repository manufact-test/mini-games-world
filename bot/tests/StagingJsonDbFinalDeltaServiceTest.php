<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/realtime/LegacyRealtimeShadowSyncService.php';
require $root . '/ledger/LegacyEconomyShadowSyncService.php';
require $root . '/ledger/LegacyFinancialArchiveDeltaService.php';
require $root . '/ledger/LegacyEconomyDeltaImportService.php';
require $root . '/migration/StagingJsonDbFinalReconciliationService.php';
require $root . '/migration/StagingJsonDbFinalDeltaService.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};

$assertSame(true, class_exists(StagingJsonDbFinalDeltaService::class), 'Final delta orchestrator must be loadable');
$assertSame(true, method_exists(StagingJsonDbFinalDeltaService::class, 'preview'), 'Final delta orchestrator must expose preview');
$assertSame(true, method_exists(StagingJsonDbFinalDeltaService::class, 'run'), 'Final delta orchestrator must expose run');

fwrite(STDOUT, "StagingJsonDbFinalDeltaServiceTest passed: {$assertions} assertions.\n");
