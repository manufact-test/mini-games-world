<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/GameInviteService.php';

function mgw_invite_share_url(array $config, string $token): string
{
    $username = ltrim(trim((string)($config['bot_username'] ?? '')), '@');

    if ($username === '') {
        try {
            $result = (new TelegramService($config))->api('getMe');
            if (!empty($result['ok']) && is_array($result['result'] ?? null)) {
                $username = ltrim(trim((string)($result['result']['username'] ?? '')), '@');
            }
        } catch (Throwable $e) {
            error_log('Mini Games World invite getMe failed: ' . $e->getMessage());
        }
    }

    if ($username !== '') {
        return 'https://t.me/' . rawurlencode($username) . '?start=invite_' . rawurlencode($token);
    }

    $baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
    return $baseUrl . '/app/?v=77&invite=' . rawurlencode($token);
}

function mgw_invite_board_label(array $invite): string
{
    $gameType = (string)($invite['game_type'] ?? '');
    $size = (int)($invite['board_size'] ?? 0);
    if ($gameType === 'domino') return 'Классика 0–6';
    if ($gameType === 'four_in_a_row') {
        $rows = max(5, (int)($invite['board_rows'] ?? ($size - 1)));
        return $size . '×' . $rows;
    }
    return $size . '×' . $size;
}

function mgw_invite_share_text(array $invite): string
{
    $inviter = trim((string)($invite['inviter_name'] ?? 'Игрок'));
    $game = (string)($invite['game_title'] ?? 'Игра');
    $room = (string)($invite['room_label'] ?? 'Матч-комната');
    $bet = (int)($invite['bet'] ?? 0);
    $variant = mgw_invite_board_label($invite);

    return "🎮 Приглашение в Mini Games World\n\n"
        . $inviter . " приглашает вас сыграть!\n\n"
        . "🎲 Игра: {$game}\n"
        . "🏠 Комната: {$room}\n"
        . "📐 Вариант: {$variant}\n"
        . "🪙 Ставка: {$bet} коинов\n\n"
        . "Откройте приглашение и примите вызов 👇";
}

function mgw_invite_game_for_viewer(
    array $data,
    ChessRuntimeService $games,
    array $invite,
    string $userId
): ?array {
    $gameId = trim((string)($invite['game_id'] ?? ''));
    if ($gameId === '' || empty($invite['is_participant'])) return null;
    if (!isset($data['games'][$gameId]) || !is_array($data['games'][$gameId])) return null;
    return $games->publicGame($data['games'][$gameId], $userId);
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        api_error('Некорректный запрос.');
    }

    $action = clean_string($payload['action'] ?? '', 40);
    $sessionId = clean_string($payload['sessionId'] ?? '', 120);
    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $users = new UserService($config);
    $sessions = new SessionService($config);
    $catalog = new GameCatalogService($config);
    $games = new ChessRuntimeService($config, $catalog, new GameService($config));
    $invites = new GameInviteService($config, $catalog, $games);
    $db = new JsonDatabase((string)($config['data_dir'] ?? (__DIR__ . '/data')));

    $result = $db->transaction(function (array &$data) use (
        $action,
        $payload,
        $tgUser,
        $users,
        $sessions,
        $games,
        $invites,
        $sessionId
    ): array {
        $user = $users->ensureUser($data, $tgUser);
        $userId = (string)($user['id'] ?? '');
        $data['users'][$userId] = $user;
        $user =& $data['users'][$userId];
        $sessions->ensureSessionShape($user);

        return match ($action) {
            'create' => (function () use (&$data, &$user, $payload, $users, $sessions, $invites, $sessionId): array {
                $sessions->assertCanPlay($user, $sessionId);
                $sessions->touch($user, $sessionId);
                return [
                    'invite' => $invites->create(
                        $data,
                        $user,
                        clean_string($payload['gameType'] ?? 'tictactoe', 60),
                        clean_string($payload['room'] ?? 'match', 20),
                        (int)($payload['bet'] ?? 10),
                        (int)($payload['boardSize'] ?? 3)
                    ),
                    'user' => $users->publicUser($user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];
            })(),
            'resolve' => (function () use (&$data, &$user, $payload, $users, $sessions, $games, $invites, $sessionId, $userId): array {
                $invite = $invites->resolve($data, $user, clean_string($payload['token'] ?? '', 80));
                return [
                    'invite' => $invite,
                    'game' => mgw_invite_game_for_viewer($data, $games, $invite, $userId),
                    'user' => $users->publicUser($user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];
            })(),
            'accept' => (function () use (&$data, &$user, $payload, $users, $sessions, $games, $invites, $sessionId, $userId): array {
                $sessions->assertCanPlay($user, $sessionId);
                $sessions->touch($user, $sessionId);
                $invite = $invites->accept($data, $user, clean_string($payload['token'] ?? '', 80));
                return [
                    'invite' => $invite,
                    'game' => mgw_invite_game_for_viewer($data, $games, $invite, $userId),
                    'user' => $users->publicUser($user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];
            })(),
            'decline' => (function () use (&$data, &$user, $payload, $users, $sessions, $invites, $sessionId): array {
                $invite = $invites->decline($data, $user, clean_string($payload['token'] ?? '', 80));
                return [
                    'invite' => $invite,
                    'user' => $users->publicUser($user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];
            })(),
            default => throw new RuntimeException('Неизвестное действие приглашения.'),
        };
    });

    if ($action === 'create' && is_array($result['invite'] ?? null)) {
        $token = (string)($result['invite']['token'] ?? '');
        $result['invite']['share_url'] = mgw_invite_share_url($config, $token);
        $result['invite']['share_text'] = mgw_invite_share_text($result['invite']);
    }

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
