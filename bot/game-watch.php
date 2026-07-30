<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $tgUser = (new AuthService($config))->getUserFromRequest($payload);
    $userId = trim((string)($tgUser['id'] ?? ''));
    $gameId = clean_string($payload['gameId'] ?? '', 80);
    if ($userId === '' || $gameId === '') throw new RuntimeException('Игра не найдена.');

    $catalog = new GameCatalogService($config);
    $games = new ChessRuntimeService($config, $catalog, new GameService($config));
    $storage = StorageFactory::create($config);

    $game = $storage->readOnly(static function (array $data) use ($games, $gameId, $userId): ?array {
        $candidate = $data['games'][$gameId] ?? null;
        if (!is_array($candidate)) return null;
        $participants = array_map('strval', $candidate['player_ids'] ?? []);
        if (!in_array($userId, $participants, true)) return null;
        return $games->publicGame($candidate, $userId);
    });

    api_ok([
        'game' => is_array($game) ? $game : null,
        'me' => ['id' => $userId],
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
