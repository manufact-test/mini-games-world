<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/GameReactionService.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/** @return array<string, array<string, mixed>> */
function mgw_game_watch_load_games(array $config): array
{
    $driver = strtolower(trim((string)($config['storage_driver'] ?? 'json')));
    if ($driver === '') $driver = 'json';

    if ($driver === 'json') {
        return mgw_game_watch_read_json_games($config);
    }

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
        $locked = flock($handle, LOCK_SH | LOCK_NB);
        if (!$locked) throw new RuntimeException('Состояние игры обновляется.');

        $raw = stream_get_contents($handle);
        $decoded = json_decode(is_string($raw) && $raw !== '' ? $raw : '[]', true);
        return is_array($decoded) ? $decoded : [];
    } finally {
        if ($locked) flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function mgw_game_watch_result_history(array $config, string $userId, string $gameId): array
{
    $storage = new JsonStorageAdapter((string)($config['data_dir'] ?? ''));

    return $storage->readOnlySections(
        ['users', 'games', 'transactions'],
        static function (array $data) use ($config, $userId, $gameId): array {
            $game = $data['games'][$gameId] ?? null;
            if (!is_array($game)) throw new RuntimeException('Итог матча ещё недоступен.');

            $participants = array_map('strval', $game['player_ids'] ?? []);
            if (!in_array($userId, $participants, true)) {
                throw new RuntimeException('Вы не участвуете в этой игре.');
            }
            if ((string)($game['status'] ?? '') !== 'finished') {
                throw new RuntimeException('Матч ещё не завершён.');
            }

            $formatter = new HistoryService($config, new UserService($config));
            $resultSnapshot = [
                'users' => is_array($data['users'] ?? null) ? $data['users'] : [],
                'games' => [$gameId => $game],
                'transactions' => is_array($data['transactions'] ?? null) ? $data['transactions'] : [],
            ];
            $matches = $formatter->matchHistory($resultSnapshot, $userId, 1);
            $match = $matches[0] ?? null;
            if (!is_array($match) || (string)($match['id'] ?? '') !== $gameId) {
                throw new RuntimeException('Итог матча ещё недоступен.');
            }
            if (!is_array($match['economy'] ?? null)) {
                throw new RuntimeException('Финансовый итог матча ещё недоступен.');
            }

            return [
                'history' => ['matches' => [$match]],
                'presentation_version' => 'mvp17-5-result-locked-projection-v1',
            ];
        }
    );
}

function mgw_game_watch_latest_reaction(array $config, string $gameId): ?array
{
    try {
        $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
        if (!$databaseConfig->enabled()) return null;
        $database = PdoConnectionFactory::create($databaseConfig);
        return (new GameReactionService($config, $database))->latest($gameId);
    } catch (Throwable $error) {
        return null;
    }
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $tgUser = (new AuthService($config))->getUserFromRequest($payload, false);
    $userId = trim((string)($tgUser['id'] ?? ''));
    $gameId = clean_string($payload['gameId'] ?? '', 80);
    if ($userId === '' || $gameId === '') throw new RuntimeException('Игра не найдена.');

    if (clean_string($payload['mode'] ?? '', 24) === 'result') {
        api_ok(mgw_game_watch_result_history($config, $userId, $gameId) + [
            'me' => ['id' => $userId],
        ]);
    }

    $catalog = new GameCatalogService($config);
    $games = new ChessRuntimeService($config, $catalog, new GameService($config));
    $storedGames = mgw_game_watch_load_games($config);
    $candidate = $storedGames[$gameId] ?? null;

    $game = null;
    $reaction = null;
    if (is_array($candidate)) {
        $participants = array_map('strval', $candidate['player_ids'] ?? []);
        if (in_array($userId, $participants, true)) {
            $game = $games->publicGame($candidate, $userId);
            $reaction = mgw_game_watch_latest_reaction($config, $gameId);
        }
    }

    api_ok([
        'game' => $game,
        'reaction' => $reaction,
        'me' => ['id' => $userId],
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
