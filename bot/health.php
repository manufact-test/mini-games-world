<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$build = 'v92-mvp14-db-notification-routing';

try {
    require __DIR__ . '/core/bootstrap.php';

    $flags = new FeatureFlagService($config);
    $storage = StorageFactory::create($config);
    $dataDir = (string)($config['data_dir'] ?? '');
    $storageReady = $dataDir !== ''
        && is_dir($dataDir)
        && is_readable($dataDir)
        && is_writable($dataDir);

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    $managedMigrationConfig = ManagedMigrationConfig::fromApplicationConfig($config);
    $databaseStatus = $databaseConfig->safeSummary();
    $databaseStatus['connected'] = null;
    $databaseStatus['schema_current'] = null;
    $databaseStatus['applied_migrations'] = null;
    $databaseStatus['pending_migrations'] = null;
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
            error_log('[MiniGamesWorld database health] ' . $databaseError->getMessage());
        }
    }

    $databaseReady = !$databaseConfig->enabled()
        || ($databaseStatus['connected'] === true && $databaseStatus['schema_current'] === true);
    $runtime = $flags->publicStatus();
    $ok = $storageReady && $databaseReady;
    if (!$ok) http_response_code(503);

    echo json_encode([
        'ok' => $ok,
        'service' => 'mini-games-world',
        'status' => !$ok ? 'degraded' : ($flags->maintenanceEnabled() ? 'maintenance' : 'ok'),
        'build' => FeatureFlagService::BUILD,
        'environment' => (string)($config['environment'] ?? 'production'),
        'server_time' => gmdate(DATE_ATOM),
        'checks' => [
            'config' => true,
            'storage' => $storageReady,
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
        'server_time' => gmdate(DATE_ATOM),
        'checks' => [
            'config' => false,
            'storage' => false,
            'database' => [
                'enabled' => false,
                'configured' => false,
                'connected' => null,
                'schema_current' => null,
                'applied_migrations' => null,
                'pending_migrations' => null,
                'managed_migrations' => null,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
