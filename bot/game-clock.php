<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $sessionId = clean_string($payload['sessionId'] ?? '', 120);
    $gameId = clean_string($payload['gameId'] ?? '', 120);
    if ($gameId === '') api_error('Игра не найдена.');

    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $users = new UserService($config);
    $sessions = new SessionService($config);
    $catalog = new GameCatalogService($config);
    $games = new ChessRuntimeService($config, $catalog, new GameService($config));
    $db = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));

    $result = $db->transaction(function (array &$data) use (
        $tgUser,
        $users,
        $sessions,
        $games,
        $sessionId,
        $gameId
    ): array {
        $user = $users->ensureUser($data, $tgUser);
        $userId = (string)($user['id'] ?? '');
        if ($userId === '') throw new RuntimeException('Пользователь не найден.');
        $data['users'][$userId] = $user;
        $user =& $data['users'][$userId];
        $sessions->ensureSessionShape($user);
        $sessions->assertCanPlay($user, $sessionId);

        if (!isset($data['games'][$gameId]) || !is_array($data['games'][$gameId])) {
            throw new RuntimeException('Игра не найдена.');
        }
        $game =& $data['games'][$gameId];
        if (!in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) {
            throw new RuntimeException('Вы не участвуете в этой игре.');
        }

        $gameType = (string)($game['game_type'] ?? 'tictactoe');
        $size = max(1, (int)($game['board_size'] ?? 3));
        $emptyBoard = str_repeat('-', $size * $size);
        $eligible = ($game['status'] ?? '') === 'active'
            && $gameType === 'tictactoe'
            && !empty($game['is_bot_game'])
            && (string)($game['board'] ?? '') === $emptyBoard;

        if ($eligible && empty($game['v106_first_turn_clock_armed_at'])) {
            $now = now_iso();
            $game['turn_started_at'] = $now;
            $game['updated_at'] = $now;
            $game['v106_first_turn_clock_armed_at'] = $now;

            $botId = (string)($game['bot_id'] ?? '');
            if ($botId !== '' && (string)($game['turn'] ?? '') === $botId) {
                $game['bot_move_after_at'] = gmdate('c', time() + 1);
            } else {
                unset($game['bot_move_after_at']);
            }
        }

        return [
            'user' => $users->publicUser($user),
            'me' => ['id' => $userId],
            'game' => $games->publicGame($game, $userId),
            'session' => $sessions->publicState($user, $sessionId),
            'clock_armed' => !empty($game['v106_first_turn_clock_armed_at']),
        ];
    });

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
