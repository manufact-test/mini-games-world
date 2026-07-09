<?php
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || $key !== (string)$config['setup_secret'] || $key === 'CHANGE_THIS_SETUP_SECRET') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Доступ запрещён. Укажите свой setup_secret в bot/config/config.php и откройте setup-webhook.php?key=ВАШ_КЛЮЧ";
    exit;
}

try {
    $telegram = new TelegramService($config);
    $webhookUrl = rtrim((string)$config['base_url'], '/') . '/bot/webhook.php';
    $result = $telegram->api('setWebhook', [
        'url' => $webhookUrl,
        'allowed_updates' => ['message', 'callback_query'],
        'drop_pending_updates' => true,
    ]);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Webhook URL: {$webhookUrl}\n\n";
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Ошибка: ' . $e->getMessage();
}
