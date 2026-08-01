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
    $accountId = trim((string)($tgUser['id'] ?? ''));
    if ($accountId === '') throw new RuntimeException('Пользователь не найден.');
    if ($sessionId === '') throw new RuntimeException('Сессия устройства не найдена.');

    $presence = new PresenceService();
    $stats = new StatsService($presence);
    $db = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));

    // Every authenticated visible status read confirms the requesting session
    // before the unique-account count is calculated. This closes the race where
    // the second account asked for status before its slower heartbeat finished.
    if ($action === 'ping' || $action === 'status') $presence->touch($accountId, $sessionId);
    elseif ($action === 'leave') $presence->leave($accountId, $sessionId);

    $result = $db->readOnly(static function (array $data) use ($stats): array {
        return ['stats' => $stats->build($data)];
    });

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
