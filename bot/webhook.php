<?php
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';

try {
    $update = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($update)) {
        http_response_code(200);
        exit('ok');
    }
    $telegram = new TelegramService($config);
    $handler = new WebhookHandler($telegram, $config);
    $handler->handle($update);
    http_response_code(200);
    echo 'ok';
} catch (Throwable $e) {
    error_log('[MiniGamesWorld webhook] ' . $e->getMessage());
    http_response_code(200);
    echo 'ok';
}
