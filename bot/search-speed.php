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

            // A queue creation timestamp is immutable realtime identity. The speed
            // checkpoint therefore changes only the existing mutable queue status;
            // the ordinary game-state owner still performs human matching and the
            // eventual bot allocation.
            $createdAt = strtotime((string)($item['created_at'] ?? '')) ?: 0;
            $alreadyFastTracked = $createdAt > 0 && time() - $createdAt >= 10;
            if (!$alreadyFastTracked && (string)($item['status'] ?? 'waiting') !== 'bot_fallback_5s') {
                $item['status'] = 'bot_fallback_5s';
                $item['updated_at'] = now_iso();
            }
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
