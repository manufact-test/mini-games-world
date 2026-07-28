<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/PresenceService.php';

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $action = clean_string($payload['action'] ?? 'status', 24);
    if (!in_array($action, ['status', 'ping', 'leave'], true)) {
        throw new RuntimeException('Неизвестное действие присутствия.');
    }

    $sessionId = clean_string($payload['sessionId'] ?? '', 120);
    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $users = new UserService($config);
    $presence = new PresenceService();
    $stats = new StatsService($presence);
    $db = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));

    if ($action === 'status') {
        $result = $db->readOnly(static function (array $data) use ($stats): array {
            return ['stats' => $stats->build($data)];
        });
        api_ok($result);
    }

    $result = $db->transaction(function (array &$data) use (
        $action,
        $sessionId,
        $tgUser,
        $users,
        $presence,
        $stats
    ): array {
        $user = $users->ensureUser($data, $tgUser);
        $userId = (string)($user['id'] ?? '');
        if ($userId === '') throw new RuntimeException('Пользователь не найден.');
        $data['users'][$userId] = $user;
        $stored =& $data['users'][$userId];

        if ($action === 'leave') $presence->leave($stored, $sessionId);
        else $presence->touch($stored, $sessionId);

        return ['stats' => $stats->build($data)];
    });

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
