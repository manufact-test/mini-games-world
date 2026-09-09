<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.'], JSON_THROW_ON_ERROR);
    exit;
}

try {
    require_once __DIR__ . '/core/bootstrap.php';
    require_once __DIR__ . '/services/StagingReadinessService.php';

    if (strtolower(trim((string)($config['environment'] ?? ''))) !== 'staging') {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found.'], JSON_THROW_ON_ERROR);
        exit;
    }

    $service = new StagingReadinessService($config, dirname(__DIR__));
    $report = $service->report();
    http_response_code(200);
    if ($method !== 'HEAD') {
        echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
} catch (Throwable $error) {
    error_log('MGW staging readiness failure: ' . $error->getMessage());
    http_response_code(404);
    if ($method !== 'HEAD') {
        echo json_encode(['ok' => false, 'error' => 'Not found.'], JSON_THROW_ON_ERROR);
    }
}
