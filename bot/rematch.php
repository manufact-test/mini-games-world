<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/GameInviteService.php';
require_once __DIR__ . '/services/GameInviteFlowService.php';
require_once __DIR__ . '/services/GameInviteInboxService.php';

function mgw_rematch_active_invite(array $data, string $sourceGameId, string $inviterId): ?array
{
    $now = time();
    foreach (array_reverse($data['invites'] ?? []) as $invite) {
        if (!is_array($invite)) continue;
        if ((string)($invite['source'] ?? '') !== 'rematch') continue;
        if ((string)($invite['source_game_id'] ?? '') !== $sourceGameId) continue;
        if ((string)($invite['inviter_id'] ?? '') !== $inviterId) continue;

        $status = (string)($invite['status'] ?? 'pending');
        if ($status === 'pending') {
            $expiresAt = strtotime((string)($invite['expires_at'] ?? '')) ?: 0;
            if ($expiresAt <= 0 || $expiresAt > $now) return $invite;
        }
        if ($status === 'awaiting_start') {
            $deadline = strtotime((string)($invite['start_deadline_at'] ?? '')) ?: 0;
            if ($deadline <= 0 || $deadline > $now) return $invite;
        }
    }
    return null;
}

function mgw_rematch_assert_available(
    array $data,
    ChessRuntimeService $games,
    array $user,
    string $message
): void {
    $userId = (string)($user['id'] ?? '');
    if (in_array((string)($user['status'] ?? 'idle'), ['searching', 'playing'], true)) {
        throw new RuntimeException($message);
    }
    if ($userId !== '' && $games->findActiveGameForUser($data, $userId)) {
        throw new RuntimeException($message);
    }

    foreach ($data['invites'] ?? [] as $invite) {
        if (!is_array($invite) || (string)($invite['status'] ?? '') !== 'awaiting_start') continue;
        $deadline = strtotime((string)($invite['start_deadline_at'] ?? '')) ?: 0;
        if ($deadline > 0 && $deadline <= time()) continue;
        if ((string)($invite['inviter_id'] ?? '') === $userId
            || (string)($invite['invitee_id'] ?? '') === $userId) {
            throw new RuntimeException($message);
        }
    }
}

function mgw_rematch_user_name(array $user): string
{
    $username = trim((string)($user['username'] ?? ''));
    if ($username !== '') return '@' . ltrim($username, '@');
    $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
    return $name !== '' ? $name : 'Игрок';
}

function mgw_rematch_annotate(array &$data, string $token, string $sourceGameId): void
{
    foreach ($data['invites'] ?? [] as &$invite) {
        if (!is_array($invite) || !hash_equals((string)($invite['token'] ?? ''), $token)) continue;
        $invite['source'] = 'rematch';
        $invite['source_game_id'] = $sourceGameId;
        $invite['updated_at'] = now_iso();
        break;
    }
    unset($invite);
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $gameId = clean_string($payload['gameId'] ?? '', 120);
    $sessionId = clean_string($payload['sessionId'] ?? '', 120);
    if ($gameId === '') api_error('Матч для реванша не найден.');

    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $users = new UserService($config);
    $sessions = new SessionService($config);
    $catalog = new GameCatalogService($config);
    $games = new ChessRuntimeService($config, $catalog, new GameService($config));
    $invites = new GameInviteService($config, $catalog, $games);
    $inviteFlow = new GameInviteFlowService($config, $catalog, $games);
    $inbox = new GameInviteInboxService();
    $db = new JsonDatabase((string)($config['data_dir'] ?? (__DIR__ . '/data')));

    $result = $db->transaction(function (array &$data) use (
        $gameId,
        $sessionId,
        $tgUser,
        $users,
        $sessions,
        $catalog,
        $games,
        $invites,
        $inviteFlow,
        $inbox
    ): array {
        $user = $users->ensureUser($data, $tgUser);
        $userId = (string)($user['id'] ?? '');
        if ($userId === '') throw new RuntimeException('Пользователь не найден.');
        $data['users'][$userId] = $user;
        $user =& $data['users'][$userId];

        $sessions->ensureSessionShape($user);
        $sessions->assertCanPlay($user, $sessionId);
        $sessions->touch($user, $sessionId);

        $game = $data['games'][$gameId] ?? null;
        if (!is_array($game) || (string)($game['status'] ?? '') !== 'finished') {
            throw new RuntimeException('Реванш доступен только после завершённой партии.');
        }
        if (!empty($game['is_bot_game'])) {
            throw new RuntimeException('Реванш можно предложить только живому сопернику.');
        }

        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        if (count($playerIds) !== 2 || !in_array($userId, $playerIds, true)) {
            throw new RuntimeException('Вы не участвуете в этом матче.');
        }

        $opponentId = $playerIds[0] === $userId ? $playerIds[1] : $playerIds[0];
        if ($opponentId === '' || str_starts_with($opponentId, 'bot_')
            || !isset($data['users'][$opponentId]) || !is_array($data['users'][$opponentId])) {
            throw new RuntimeException('Живой соперник для реванша недоступен.');
        }
        $opponent =& $data['users'][$opponentId];

        mgw_rematch_assert_available($data, $games, $user, 'Сначала завершите текущий поиск, матч или другое приглашение.');
        mgw_rematch_assert_available($data, $games, $opponent, 'Соперник сейчас занят в другой игре или приглашении.');

        $existing = mgw_rematch_active_invite($data, $gameId, $userId);
        if (is_array($existing)) {
            return [
                'invite' => $inviteFlow->resolve($data, $user, (string)($existing['token'] ?? '')),
                'user' => $users->publicUser($user),
                'session' => $sessions->publicState($user, $sessionId),
                'reused' => true,
            ];
        }

        $gameType = $catalog->normalizeGameType((string)($game['game_type'] ?? 'tictactoe'));
        $room = (string)($game['room'] ?? 'match') === 'gold' ? 'gold' : 'match';
        $bet = (int)($game['bet'] ?? ($room === 'match' ? 10 : 0));
        $boardSize = (int)($game['board_size'] ?? 3);

        $created = $invites->create($data, $user, $gameType, $room, $bet, $boardSize);
        $token = (string)($created['token'] ?? '');
        if ($token === '') throw new RuntimeException('Не удалось создать предложение реванша.');

        mgw_rematch_annotate($data, $token, $gameId);
        $registered = $inbox->registerDirectRecipient($data, $opponent, $token);
        if (!is_array($registered)) {
            throw new RuntimeException('Не удалось отправить предложение сопернику.');
        }

        return [
            'invite' => $inviteFlow->resolve($data, $user, $token),
            'user' => $users->publicUser($user),
            'session' => $sessions->publicState($user, $sessionId),
            'opponent_name' => mgw_rematch_user_name($opponent),
            'reused' => false,
        ];
    });

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
