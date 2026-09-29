<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok'=>false,'error'=>'method_not_allowed'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}
if ($_GET !== []) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'query_not_allowed'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/TelegramWebhookSecurity.php';

if (!TelegramWebhookSecurity::setupBearerAuthorized($config, $_SERVER)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'forbidden'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

try {
    $telegram = new TelegramService($config);
    $webhookUrl = rtrim((string)$config['base_url'], '/') . '/bot/webhook.php';
    $result = $telegram->api('setWebhook', [
        'url' => $webhookUrl,
        'secret_token' => TelegramWebhookSecurity::secretToken($config),
        'allowed_updates' => ['message', 'edited_message', 'callback_query'],
        'drop_pending_updates' => false,
    ]);
    if (($result['ok'] ?? false) !== true) {
        throw new RuntimeException('Telegram rejected webhook configuration.');
    }

    echo json_encode([
        'ok'=>true,
        'webhook_url'=>$webhookUrl,
        'secret_token_configured'=>true,
        'pending_updates_preserved'=>true,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) {
    error_log('[MiniGamesWorld setup webhook] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'webhook_setup_failed'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
