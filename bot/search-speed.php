<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $tgUser = (new AuthService($config))->getUserFromRequest($payload);
    $userId = trim((string)($tgUser['id'] ?? ''));
    if ($userId === '') throw new RuntimeException('Пользователь не найден.');

    $db = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $result = $db->transaction(static function (array &$data) use ($userId): array {
        $accelerated = false;
        if (!isset($data['queue']) || !is_array($data['queue'])) {
            return ['accelerated' => false];
        }

        foreach ($data['queue'] as &$item) {
            if (!is_array($item) || (string)($item['user_id'] ?? '') !== $userId) continue;
            if ((string)($item['room'] ?? 'match') !== 'match') break;

            // The normal fallback is 15 seconds. At the explicit speed checkpoint
            // we preserve a real-human window but make the existing bot allocator
            // eligible on the next ordinary game-state poll.
            $target = time() - 12;
            $createdAt = strtotime((string)($item['created_at'] ?? '')) ?: time();
            if ($createdAt > $target) $item['created_at'] = gmdate('c', $target);
            $accelerated = true;
            break;
        }
        unset($item);

        return ['accelerated' => $accelerated];
    });

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
