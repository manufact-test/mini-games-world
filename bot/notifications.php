<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/NotificationService.php';

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

    $markRead = !empty($payload['markRead']);
    $db = new JsonDatabase((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $notifications = new NotificationService();

    if ($markRead) {
        $result = $db->transaction(function (array &$data) use ($notifications, $userId): array {
            $items = $notifications->userNotifications($data, $userId, 30);
            $notifications->markAllRead($data, $userId);
            return [
                'items' => $items,
                'unread_count' => 0,
            ];
        });
    } else {
        $result = $db->readOnly(function (array $data) use ($notifications, $userId): array {
            return [
                'items' => $notifications->userNotifications($data, $userId, 30),
                'unread_count' => $notifications->unreadCount($data, $userId),
            ];
        });
    }

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
