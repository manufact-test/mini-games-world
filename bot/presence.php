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
    $presenceLeaseId = clean_string($payload['presenceLeaseId'] ?? '', 120);
    $auth = new AuthService($config);
    // Presence only needs the already verified provider user id to own a
    // document/session lease. Keep Telegram/staging/dev authentication intact,
    // but avoid redundant provider-neutral account/DB identity resolution on
    // this high-frequency status/ping path.
    $tgUser = $auth->getUserFromRequest($payload, false);
    $accountId = trim((string)($tgUser['id'] ?? ''));
    if ($accountId === '') throw new RuntimeException('Пользователь не найден.');
    if ($sessionId === '') throw new RuntimeException('Сессия устройства не найдена.');

    $presence = new PresenceService();
    $stats = new StatsService($presence);
    $db = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));

    // Every visible document owns its own presence lease. A delayed pagehide
    // from an older Telegram document therefore cannot mark the newly opened
    // document offline even when both use the same persistent device session.
    // Room is document presence metadata, not matchmaking state: queue/game data
    // remains authoritative once the account starts searching or playing.
    if ($action === 'ping' || $action === 'status') {
        $presence->touch($accountId, $sessionId, $presenceLeaseId);
    } elseif ($action === 'leave') {
        $presence->leave($accountId, $sessionId, $presenceLeaseId);
    }

    $result = $db->readOnly(static function (array $data) use ($stats): array {
        return ['stats' => $stats->build($data)];
    });

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
