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
    echo json_encode(['ok'=>false,'error'=>'method_not_allowed'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}
if ($_GET !== []) {
    http_response_code(400);
    if ($method !== 'HEAD') {
        echo json_encode(['ok'=>false,'error'=>'query_not_allowed'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
    exit;
}

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/TelegramWebhookSecurity.php';

if (!TelegramWebhookSecurity::setupBearerAuthorized($config, $_SERVER)) {
    http_response_code(403);
    if ($method !== 'HEAD') {
        echo json_encode(['ok'=>false,'error'=>'forbidden'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
    exit;
}

try {
    $telegram = new TelegramService($config);
    $result = $telegram->api('getWebhookInfo');
    $info = is_array($result['result'] ?? null) ? $result['result'] : [];
    $payload = [
        'ok'=>($result['ok'] ?? false) === true,
        'webhook'=>[
            'url'=>(string)($info['url'] ?? ''),
            'pending_update_count'=>(int)($info['pending_update_count'] ?? 0),
            'last_error_date'=>isset($info['last_error_date']) ? (int)$info['last_error_date'] : null,
            'last_error_message'=>(string)($info['last_error_message'] ?? ''),
            'max_connections'=>isset($info['max_connections']) ? (int)$info['max_connections'] : null,
            'allowed_updates'=>array_values(array_map('strval', (array)($info['allowed_updates'] ?? []))),
        ],
    ];
    if ($method !== 'HEAD') {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
} catch (Throwable $e) {
    error_log('[MiniGamesWorld check webhook] ' . $e->getMessage());
    http_response_code(500);
    if ($method !== 'HEAD') {
        echo json_encode(['ok'=>false,'error'=>'webhook_check_failed'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
}
