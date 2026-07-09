<?php
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || $key !== (string)$config['setup_secret']) {
    http_response_code(403);
    echo 'Доступ запрещён.';
    exit;
}

try {
    $telegram = new TelegramService($config);
    $result = $telegram->api('getWebhookInfo');
    header('Content-Type: text/plain; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Ошибка: ' . $e->getMessage();
}
