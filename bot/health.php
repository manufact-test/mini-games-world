<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$build = 'v86-mvp13-runtime-controls';

try {
    require __DIR__ . '/core/bootstrap.php';

    $flags = new FeatureFlagService($config);
    $storage = StorageFactory::create($config);
    $dataDir = (string)($config['data_dir'] ?? '');
    $storageReady = $dataDir !== ''
        && is_dir($dataDir)
        && is_readable($dataDir)
        && is_writable($dataDir);

    $runtime = $flags->publicStatus();
    $ok = $storageReady;
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
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
