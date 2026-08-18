<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/PresenceService.php';
require_once __DIR__ . '/services/ReconnectLifecycleService.php';

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $action = clean_string($payload['action'] ?? 'status', 24);
    if (!in_array($action, ['status', 'ping', 'background', 'leave'], true)) {
        throw new RuntimeException('Неизвестное действие присутствия.');
    }

    $sessionId = clean_string($payload['sessionId'] ?? '', 120);
    $presenceLeaseId = clean_string($payload['presenceLeaseId'] ?? '', 120);
    $auth = new AuthService($config);
    // Presence only needs the already verified provider user id to own a
    // document/session lease. Keep Telegram/staging/dev authentication intact,
    // but avoid redundant provider-neutral account/DB identity resolution on
    // this high-frequency path.
    $tgUser = $auth->getUserFromRequest($payload, false);
    $accountId = trim((string)($tgUser['id'] ?? ''));
    if ($accountId === '') throw new RuntimeException('Пользователь не найден.');
    if ($sessionId === '') throw new RuntimeException('Сессия устройства не найдена.');

    $presence = new PresenceService();
    $stats = new StatsService($presence);
    $reconnect = new ReconnectLifecycleService($config, $presence);
    $db = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));

    // Preserve the state that existed before a fresh ping. It lets a new
    // supported client recover a stale foreground session in the same request,
    // before bootstrap encounters the old device lock.
    $previousPresence = $presence->gameplaySnapshot($accountId);

    // Every visible document owns its own presence lease. Background is an
    // explicit connected-idle state: its normal move timer keeps running. Only
    // foreground lease loss or pagehide/leave enters the reconnect lifecycle.
    if ($action === 'ping' || $action === 'status') {
        $presence->touch($accountId, $sessionId, $presenceLeaseId);
    } elseif ($action === 'background') {
        $presence->background($accountId, $sessionId, $presenceLeaseId);
    } elseif ($action === 'leave') {
        $presence->leave($accountId, $sessionId, $presenceLeaseId);
    }

    // Normal four-second presence heartbeats stay read-only. We enter a storage
    // transaction only when reconnect state, expiry or settlement really needs
    // to change, avoiding a new high-frequency JSON write loop.
    $requiresMutation = $db->readOnly(static function (array $data) use (
        $reconnect,
        $accountId,
        $sessionId,
        $action,
        $previousPresence
    ): bool {
        return $reconnect->needsMutation($data, $accountId, $sessionId, $action, $previousPresence);
    });

    if ($requiresMutation) {
        $result = $db->transaction(static function (array &$data) use (
            $reconnect,
            $stats,
            $accountId,
            $sessionId,
            $action,
            $previousPresence
        ): array {
            $reconnect->synchronize($data, $accountId, $sessionId, $action, $previousPresence);
            return ['stats' => $stats->build($data)];
        });
    } else {
        $result = $db->readOnly(static function (array $data) use ($stats): array {
            return ['stats' => $stats->build($data)];
        });
    }

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
