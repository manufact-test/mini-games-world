<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $token = clean_string($payload['token'] ?? '', 80);
    if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
        api_error('Приглашение не найдено.');
    }

    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $userId = (string)($tgUser['id'] ?? '');
    if ($userId === '') api_error('Пользователь не найден.');

    $db = new JsonDatabase((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $db->transaction(function (array &$data) use ($token, $userId): void {
        $now = now_iso();
        foreach ($data['notifications'] ?? [] as &$notification) {
            if (!is_array($notification)) continue;
            if ((string)($notification['user_id'] ?? '') !== $userId) continue;
            if ((string)($notification['invite_token'] ?? '') !== $token) continue;
            if (!empty($notification['read_at'])) continue;
            $notification['read_at'] = $now;
        }
        unset($notification);
    });

    api_ok(['seen' => true]);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
