<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/** @return array<string, array<string, mixed>> */
function mgw_game_watch_load_games(array $config): array
{
    $driver = strtolower(trim((string)($config['storage_driver'] ?? 'json')));
    if ($driver === '') $driver = 'json';

    // Production currently uses JSON. Read only games.json under that file's own
    // shared lock, never the global transaction lock used by write operations.
    if ($driver === 'json') {
        return mgw_game_watch_read_json_games($config);
    }

    // Database-primary remains a guarded fallback; no routing/config is changed.
    $storage = StorageFactory::create($config);
    return $storage->readOnly(static function (array $data): array {
        return is_array($data['games'] ?? null) ? $data['games'] : [];
    });
}

/** @return array<string, array<string, mixed>> */
function mgw_game_watch_read_json_games(array $config): array
{
    $dataDir = rtrim((string)($config['data_dir'] ?? (__DIR__ . '/data')), DIRECTORY_SEPARATOR);
    $path = $dataDir . DIRECTORY_SEPARATOR . 'games.json';
    $handle = @fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Игра не найдена.');

    $locked = false;
    try {
        $locked = flock($handle, LOCK_SH);
        if (!$locked) throw new RuntimeException('Не удалось прочитать состояние игры.');

        $raw = stream_get_contents($handle);
        $decoded = json_decode(is_string($raw) && $raw !== '' ? $raw : '[]', true);
        return is_array($decoded) ? $decoded : [];
    } finally {
        if ($locked) flock($handle, LOCK_UN);
        fclose($handle);
    }
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $tgUser = (new AuthService($config))->getUserFromRequest($payload);
    $userId = trim((string)($tgUser['id'] ?? ''));
    $gameId = clean_string($payload['gameId'] ?? '', 80);
    if ($userId === '' || $gameId === '') throw new RuntimeException('Игра не найдена.');

    $catalog = new GameCatalogService($config);
    $games = new ChessRuntimeService($config, $catalog, new GameService($config));
    $storedGames = mgw_game_watch_load_games($config);
    $candidate = $storedGames[$gameId] ?? null;

    $game = null;
    if (is_array($candidate)) {
        $participants = array_map('strval', $candidate['player_ids'] ?? []);
        if (in_array($userId, $participants, true)) {
            $game = $games->publicGame($candidate, $userId);
        }
    }

    json_response([
        'ok' => true,
        'game' => $game,
        'me' => ['id' => $userId],
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
