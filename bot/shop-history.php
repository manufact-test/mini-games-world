<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/ShopOrderHistoryService.php';

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        api_error('Некорректный запрос.');
    }

    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $userId = (string)($tgUser['id'] ?? '');

    if ($userId === '') {
        api_error('Пользователь не найден.');
    }

    $db = new JsonDatabase((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $history = new ShopOrderHistoryService();

    $orders = $db->readOnly(function (array $data) use ($history, $userId): array {
        return $history->userOrders($data, $userId, 20);
    });

    api_ok([
        'orders' => $orders,
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
