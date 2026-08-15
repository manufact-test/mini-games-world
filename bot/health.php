<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/database/DatabaseFailureClassifier.php';

$build = 'v1158-mvp15-3-runtime-integrity';

try {
    require __DIR__ . '/core/bootstrap.php';

    $flags = new FeatureFlagService($config);
    $storage = StorageFactory::create($config);
    $dataDir = (string)($config['data_dir'] ?? '');
    $storageReady = $dataDir !== ''
        && is_dir($dataDir)
        && is_readable($dataDir)
        && is_writable($dataDir);

    // A green bootstrap alone is not enough after MVP-15.3. The accepted v110
    // graph depends on the canonical MGW profile endpoint plus the complete
    // unified-balance runtime. A partial Hostinger upload must therefore make
    // health fail instead of silently serving a broken application.
    $criticalRuntimeSources = [
        __DIR__ . '/profile.php',
        __DIR__ . '/accounts/MgwProfileService.php',
        __DIR__ . '/economy/UnifiedBalanceMigrationRule.php',
        __DIR__ . '/economy/UnifiedBalanceMigrationPlanner.php',
        __DIR__ . '/economy/UnifiedBalanceMigrationExecutor.php',
        __DIR__ . '/economy/UnifiedBalanceRuntimeState.php',
        __DIR__ . '/economy/UnifiedBalanceMigrationCoordinator.php',
        __DIR__ . '/economy/UnifiedEconomyRuntimeSyncService.php',
        __DIR__ . '/economy/unified_balance_mapping.php',
        __DIR__ . '/ledger/LegacyEconomyDeltaImportService.php',
        __DIR__ . '/ledger/RuntimeEconomyRepository.php',
        __DIR__ . '/services/UserService.php',
        __DIR__ . '/services/GameSettlementService.php',
        __DIR__ . '/services/ShopService.php',
        __DIR__ . '/services/PaymentService.php',
        __DIR__ . '/services/WeeklyMatchEconomyService.php',
        dirname(__DIR__) . '/app/v110.php',
        dirname(__DIR__) . '/app/assets/js/api/client.js',
        dirname(__DIR__) . '/app/assets/js/ui.js',
        dirname(__DIR__) . '/app/assets/js/screens/home-screen.js',
        dirname(__DIR__) . '/app/assets/js/screens/profile-screen-v110.js',
        dirname(__DIR__) . '/app/assets/js/screens/store-screen.js',
    ];
    $missingRuntimeSourceCount = 0;
    foreach ($criticalRuntimeSources as $runtimeSource) {
        if (!is_file($runtimeSource)) $missingRuntimeSourceCount++;
    }
    $runtimeSourcesReady = $missingRuntimeSourceCount === 0;

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    $managedMigrationConfig = ManagedMigrationConfig::fromApplicationConfig($config);
    $databaseStatus = $databaseConfig->safeSummary();
    $databaseStatus['connected'] = null;
    $databaseStatus['schema_current'] = null;
    $databaseStatus['applied_migrations'] = null;
    $databaseStatus['pending_migrations'] = null;
    $databaseStatus['failure'] = null;
    $databaseStatus['managed_migrations'] = $managedMigrationConfig->safeSummary();
    if ($databaseConfig->enabled()) {
        try {
            $database = PdoConnectionFactory::create($databaseConfig);
            $databaseStatus['connected'] = (int)$database->fetchValue('SELECT 1') === 1;
            $migrationStatus = (new MigrationRunner($database, __DIR__ . '/database/migrations'))->status();
            $databaseStatus['applied_migrations'] = (int)$migrationStatus['applied_count'];
            $databaseStatus['pending_migrations'] = (int)$migrationStatus['pending_count'];
            $databaseStatus['schema_current'] = $databaseStatus['pending_migrations'] === 0;
        } catch (Throwable $databaseError) {
            $databaseStatus['connected'] = false;
            $databaseStatus['schema_current'] = false;
            $databaseStatus['failure'] = DatabaseFailureClassifier::classify($databaseError);
            error_log('[MiniGamesWorld database health] ' . $databaseError->getMessage());
        }
    }

    $databaseReady = !$databaseConfig->enabled()
        || ($databaseStatus['connected'] === true && $databaseStatus['schema_current'] === true);
    $runtime = $flags->publicStatus();
    $ok = $storageReady && $databaseReady && $runtimeSourcesReady;
    if (!$ok) http_response_code(503);

    echo json_encode([
        'ok' => $ok,
        'service' => 'mini-games-world',
        'status' => !$ok ? 'degraded' : ($flags->maintenanceEnabled() ? 'maintenance' : 'ok'),
        'build' => FeatureFlagService::BUILD,
        'deployment_contract' => 'mvp15.3-unified-balance-v1',
        'environment' => (string)($config['environment'] ?? 'production'),
        'server_time' => gmdate(DATE_ATOM),
        'checks' => [
            'config' => true,
            'storage' => $storageReady,
            'runtime_sources' => [
                'complete' => $runtimeSourcesReady,
                'required_count' => count($criticalRuntimeSources),
                'missing_count' => $missingRuntimeSourceCount,
            ],
            'database' => $databaseStatus,
        ],
        'storage_driver' => $storage->driver(),
        'runtime' => $runtime,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[MiniGamesWorld health] ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'service' => 'mini-games-world',
        'status' => 'unavailable',
        'build' => $build,
        'deployment_contract' => 'mvp15.3-unified-balance-v1',
        'server_time' => gmdate(DATE_ATOM),
        'checks' => [
            'config' => false,
            'storage' => false,
            'runtime_sources' => [
                'complete' => false,
                'required_count' => null,
                'missing_count' => null,
            ],
            'database' => [
                'enabled' => false,
                'configured' => false,
                'connected' => null,
                'schema_current' => null,
                'applied_migrations' => null,
                'pending_migrations' => null,
                'failure' => null,
                'managed_migrations' => null,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
