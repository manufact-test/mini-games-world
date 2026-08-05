<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/PresenceService.php';
require_once __DIR__ . '/services/InviteOpponentService.php';

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $userId = (string)($tgUser['id'] ?? '');
    if ($userId === '') api_error('Пользователь не найден.');

    // The picker and create_direct must read the same active runtime state.
    // A staging-only DB snapshot can lag behind JSON and omit newly active users,
    // producing asymmetric lists and an empty frame before a later refresh.
    $storage = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $onlineIds = (new PresenceService())->onlineAccountIds();
    $opponents = new InviteOpponentService();
    $items = $storage->readOnly(
        static fn(array $data): array => $opponents->list($data, $userId, $onlineIds)
    );

    api_ok([
        'items' => $items,
        'authoritative' => true,
        'storage_driver' => $storage->driver(),
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
