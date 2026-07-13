<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/GameInviteInboxService.php';

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

    $users = new UserService($config);
    $sessions = new SessionService($config);
    $inbox = new GameInviteInboxService();
    $sessionId = clean_string($payload['sessionId'] ?? '', 120);
    $db = new JsonDatabase((string)($config['data_dir'] ?? (__DIR__ . '/data')));

    $result = $db->readOnly(function (array $data) use ($tgUser, $userId, $users, $sessions, $inbox, $sessionId): array {
        $user = is_array($data['users'][$userId] ?? null)
            ? $data['users'][$userId]
            : [
                'id' => $userId,
                'first_name' => (string)($tgUser['first_name'] ?? 'Игрок'),
                'username' => (string)($tgUser['username'] ?? $tgUser['first_name'] ?? 'Игрок'),
                'status' => 'idle',
            ];
        $sessions->ensureSessionShape($user);

        return [
            'invite' => $inbox->actionableForUser($data, $user),
            'user' => $users->publicUser($user),
            'session' => $sessions->publicState($user, $sessionId),
        ];
    });

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
