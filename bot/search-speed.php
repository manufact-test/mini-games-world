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
    $result = $db->readOnly(static function (array $data) use ($userId): array {
        foreach ($data['queue'] ?? [] as $item) {
            if (!is_array($item) || (string)($item['user_id'] ?? '') !== $userId) continue;
            return [
                'accelerated' => (string)($item['room'] ?? 'match') === 'match',
            ];
        }

        return ['accelerated' => false];
    });

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
