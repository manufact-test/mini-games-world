<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $sessionId = clean_string($payload['sessionId'] ?? '', 120);
    $gameId = clean_string($payload['gameId'] ?? '', 80);
    if ($gameId === '') api_error('Игра не найдена.');

    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $users = new UserService($config);
    $sessions = new SessionService($config);
    $catalog = new GameCatalogService($config);
    $games = new ChessRuntimeService($config, $catalog, new GameService($config));
    $db = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));

    $result = $db->transaction(function (array &$data) use ($tgUser, $users, $sessions, $games, $sessionId, $gameId): array {
        $user = $users->ensureUser($data, $tgUser);
        $userId = (string)($user['id'] ?? '');
        if ($userId === '') throw new RuntimeException('Пользователь не найден.');
        $data['users'][$userId] = $user;
        $user =& $data['users'][$userId];
        $sessions->ensureSessionShape($user);

        $game = $data['games'][$gameId] ?? null;
        if (!is_array($game) || !in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) {
            throw new RuntimeException('Игра не найдена.');
        }

        $isActiveTicTacToe = (string)($game['status'] ?? '') === 'active'
            && (string)($game['game_type'] ?? 'tictactoe') === 'tictactoe';
        $isBotGame = !empty($game['is_bot_game']);

        if ($isActiveTicTacToe && !$isBotGame) {
            $ready = array_values(array_unique(array_map('strval', $game['v108_ready_player_ids'] ?? [])));
            if (!in_array($userId, $ready, true)) $ready[] = $userId;
            $humanPlayers = array_values(array_filter(
                array_map('strval', $game['player_ids'] ?? []),
                static fn(string $id): bool => $id !== '' && !str_starts_with($id, 'bot_')
            ));
            $allReady = $humanPlayers !== [] && count(array_intersect($humanPlayers, $ready)) === count($humanPlayers);

            $game['v108_ready_player_ids'] = $ready;
            if (empty($game['v108_clock_started']) && $allReady) {
                $now = now_iso();
                $game['v108_clock_started'] = true;
                $game['turn_started_at'] = $now;
                $game['last_move_at'] = $now;
                $game['updated_at'] = $now;
            } elseif (empty($game['v108_clock_started'])) {
                $game['turn_started_at'] = now_iso();
                $game['updated_at'] = now_iso();
            }
            $data['games'][$gameId] = $game;
        }

        if ((string)($game['status'] ?? '') === 'active') {
            $sessions->assertCanPlay($user, $sessionId);
            $sessions->touch($user, $sessionId);
        } elseif ((string)($game['status'] ?? '') === 'finished') {
            $sessions->releaseIfCurrent($user, $sessionId);
        }

        $public = $games->publicGame($game, $userId);
        $timeout = max(1, (int)($public['move_timeout_sec'] ?? 60));
        $clockWaiting = $isActiveTicTacToe && !$isBotGame && empty($game['v108_clock_started']);
        $startedAt = strtotime((string)($game['turn_started_at'] ?? '')) ?: time();
        $deadlineMs = ($startedAt + $timeout) * 1000;
        $serverNowMs = (int)floor(microtime(true) * 1000);

        $public['clock_waiting_for_players'] = $clockWaiting;
        $public['turn_started_at'] = (string)($game['turn_started_at'] ?? '');
        $public['turn_deadline_ms'] = $clockWaiting ? 0 : $deadlineMs;
        $public['server_now_ms'] = $serverNowMs;
        $public['time_left'] = $clockWaiting
            ? $timeout
            : max(0, min($timeout, (int)ceil(($deadlineMs - $serverNowMs) / 1000)));

        return [
            'user' => $users->publicUser($user),
            'me' => ['id' => $userId],
            'game' => $public,
            'session' => $sessions->publicState($user, $sessionId),
            'server_now_ms' => $serverNowMs,
        ];
    });

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
