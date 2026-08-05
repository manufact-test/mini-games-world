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
    $reader = static fn(array $data): array => $opponents->list($data, $userId, $onlineIds);

    // The picker needs only users and finished-game history. JSON storage can
    // preserve the same shared-lock snapshot while skipping unrelated ledgers,
    // payments, notifications, invites and support archives. Future storage
    // drivers remain correct through the ordinary full-snapshot fallback.
    $items = $storage instanceof SelectiveReadStorageInterface
        ? $storage->readOnlySections(['users', 'games'], $reader)
        : $storage->readOnly($reader);

    api_ok([
        'items' => $items,
        'authoritative' => true,
        'storage_driver' => $storage->driver(),
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
