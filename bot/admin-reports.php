<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/AdminWebAuth.php';
require_once __DIR__ . '/social/PlayerReportService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok' => false, 'error' => 'Некорректный запрос.'], 400);
    }

    $admin = AdminWebAuth::authorize($config, (string)($payload['initData'] ?? ''));
    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        json_response(['ok' => false, 'error' => 'Очередь жалоб недоступна: DB отключена.'], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $reports = new PlayerReportService($database);
    $action = strtolower(trim((string)($payload['action'] ?? 'snapshot')));

    if ($action === 'set_status') {
        $reports->setStatus(
            trim((string)($payload['report_id'] ?? '')),
            trim((string)($payload['status'] ?? '')),
            'telegram:' . trim((string)($admin['id'] ?? 'unknown'))
        );
    } elseif ($action !== 'snapshot') {
        json_response(['ok' => false, 'error' => 'Некорректное действие очереди жалоб.'], 400);
    }

    $queue = $reports->queue(100);
    foreach ($queue as &$report) {
        $report['case_link'] = './admin.php?report=' . rawurlencode((string)$report['report_id']);
    }
    unset($report);

    json_response([
        'ok' => true,
        'generated_at' => gmdate(DATE_ATOM),
        'reports' => $queue,
        'statuses' => PlayerReportService::STATUSES,
    ]);
} catch (AdminWebAuthException $error) {
    json_response(['ok' => false, 'error' => $error->publicMessage()], $error->httpStatus());
} catch (PlayerReportException $error) {
    $status = $error->reason === 'report_not_found' ? 404 : 422;
    json_response(['ok' => false, 'error' => $error->getMessage()], $status);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld admin reports] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => 'Не удалось загрузить очередь жалоб.'], 500);
}
