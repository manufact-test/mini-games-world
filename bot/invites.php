<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/GameInviteService.php';
require_once __DIR__ . '/services/GameInviteFlowService.php';

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
    return $baseUrl . '/app/?v=80&invite=' . rawurlencode($token);
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

function mgw_invite_share_text(array $invite, string $shareUrl): string
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
        . "Откройте приглашение и примите вызов 👇\n"
        . $shareUrl;
}

function mgw_prepare_invite_message(
    array $config,
    string $userId,
    array $invite,
    string $shareUrl,
    string $shareText
): string {
    if ($userId === '' || $shareUrl === '') return '';

    try {
        $telegram = new TelegramService($config);
        $response = $telegram->api('savePreparedInlineMessage', [
            'user_id' => (int)$userId,
            'result' => [
                'type' => 'article',
                'id' => 'invite_' . (string)($invite['token'] ?? ''),
                'title' => 'Приглашение в Mini Games World',
                'description' => (string)($invite['game_title'] ?? 'Игра')
                    . ' · ' . (string)($invite['room_label'] ?? 'Матч-комната')
                    . ' · ' . mgw_invite_board_label($invite),
                'input_message_content' => [
                    'message_text' => $shareText,
                    'link_preview_options' => ['is_disabled' => true],
                ],
                'reply_markup' => [
                    'inline_keyboard' => [[
                        ['text' => '🎮 Открыть приглашение', 'url' => $shareUrl],
                    ]],
                ],
            ],
            'allow_user_chats' => true,
            'allow_bot_chats' => false,
            'allow_group_chats' => false,
            'allow_channel_chats' => false,
        ]);

        if (!empty($response['ok']) && is_array($response['result'] ?? null)) {
            return (string)($response['result']['id'] ?? '');
        }
    } catch (Throwable $e) {
        error_log('Mini Games World prepared invite failed: ' . $e->getMessage());
    }

    return '';
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

    // A completed private match must never pull either player back from the menu.
    if ((string)($data['games'][$gameId]['status'] ?? '') !== 'active') return null;

    return $games->publicGame($data['games'][$gameId], $userId);
}

function mgw_raw_invite_by_token(array $data, string $token): ?array
{
    foreach ($data['invites'] ?? [] as $invite) {
        if (is_array($invite) && hash_equals((string)($invite['token'] ?? ''), $token)) {
            return $invite;
        }
    }
    return null;
}

function mgw_assert_no_other_ready_check(array $data, string $userId, string $currentToken): void
{
    if ($userId === '') return;

    foreach ($data['invites'] ?? [] as $invite) {
        if (!is_array($invite) || (string)($invite['status'] ?? '') !== 'awaiting_start') continue;
        if ((string)($invite['token'] ?? '') === $currentToken) continue;

        $deadline = strtotime((string)($invite['start_deadline_at'] ?? '')) ?: 0;
        if ($deadline > 0 && $deadline <= time()) continue;

        $isParticipant = (string)($invite['inviter_id'] ?? '') === $userId
            || (string)($invite['invitee_id'] ?? '') === $userId;
        if ($isParticipant) {
            throw new RuntimeException('Сначала завершите другое подтверждённое приглашение.');
        }
    }
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $action = clean_string($payload['action'] ?? '', 40);
    $sessionId = clean_string($payload['sessionId'] ?? '', 120);
    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $users = new UserService($config);
    $sessions = new SessionService($config);
    $catalog = new GameCatalogService($config);
    $games = new ChessRuntimeService($config, $catalog, new GameService($config));
    $legacyInvites = new GameInviteService($config, $catalog, $games);
    $inviteFlow = new GameInviteFlowService($config, $catalog, $games);
    $db = new JsonDatabase((string)($config['data_dir'] ?? (__DIR__ . '/data')));

    $result = $db->transaction(function (array &$data) use (
        $action,
        $payload,
        $tgUser,
        $users,
        $sessions,
        $games,
        $legacyInvites,
        $inviteFlow,
        $sessionId
    ): array {
        $user = $users->ensureUser($data, $tgUser);
        $userId = (string)($user['id'] ?? '');
        $data['users'][$userId] = $user;
        $user =& $data['users'][$userId];
        $sessions->ensureSessionShape($user);

        return match ($action) {
            'create' => (function () use (&$data, &$user, $payload, $users, $sessions, $legacyInvites, $inviteFlow, $sessionId): array {
                $sessions->assertCanPlay($user, $sessionId);
                $sessions->touch($user, $sessionId);
                $created = $legacyInvites->create(
                    $data,
                    $user,
                    clean_string($payload['gameType'] ?? 'tictactoe', 60),
                    clean_string($payload['room'] ?? 'match', 20),
                    (int)($payload['bet'] ?? 10),
                    (int)($payload['boardSize'] ?? 3)
                );
                $invite = $inviteFlow->resolve($data, $user, (string)($created['token'] ?? ''));
                return [
                    'invite' => $invite,
                    'user' => $users->publicUser($user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];
            })(),
            'active' => (function () use (&$data, &$user, $users, $sessions, $inviteFlow, $sessionId): array {
                return [
                    'invite' => $inviteFlow->activeForUser($data, $user),
                    'user' => $users->publicUser($user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];
            })(),
            'resolve' => (function () use (&$data, &$user, $payload, $users, $sessions, $games, $inviteFlow, $sessionId, $userId): array {
                $invite = $inviteFlow->resolve($data, $user, clean_string($payload['token'] ?? '', 80));
                return [
                    'invite' => $invite,
                    'game' => mgw_invite_game_for_viewer($data, $games, $invite, $userId),
                    'user' => $users->publicUser($user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];
            })(),
            'accept' => (function () use (&$data, &$user, $payload, $users, $sessions, $inviteFlow, $sessionId, $userId): array {
                $sessions->assertCanPlay($user, $sessionId);
                $sessions->touch($user, $sessionId);
                $token = clean_string($payload['token'] ?? '', 80);
                $rawInvite = mgw_raw_invite_by_token($data, $token);
                if (!$rawInvite) throw new RuntimeException('Приглашение не найдено или уже недоступно.');

                mgw_assert_no_other_ready_check($data, $userId, $token);
                mgw_assert_no_other_ready_check($data, (string)($rawInvite['inviter_id'] ?? ''), $token);

                return [
                    'invite' => $inviteFlow->accept($data, $user, $token),
                    'user' => $users->publicUser($user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];
            })(),
            'start' => (function () use (&$data, &$user, $payload, $users, $sessions, $games, $inviteFlow, $sessionId, $userId): array {
                $sessions->assertCanPlay($user, $sessionId);
                $sessions->touch($user, $sessionId);
                $invite = $inviteFlow->start($data, $user, clean_string($payload['token'] ?? '', 80));
                return [
                    'invite' => $invite,
                    'game' => mgw_invite_game_for_viewer($data, $games, $invite, $userId),
                    'user' => $users->publicUser($user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];
            })(),
            'decline' => (function () use (&$data, &$user, $payload, $users, $sessions, $inviteFlow, $sessionId): array {
                return [
                    'invite' => $inviteFlow->decline($data, $user, clean_string($payload['token'] ?? '', 80)),
                    'user' => $users->publicUser($user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];
            })(),
            'cancel' => (function () use (&$data, &$user, $payload, $users, $sessions, $inviteFlow, $sessionId): array {
                return [
                    'invite' => $inviteFlow->cancel($data, $user, clean_string($payload['token'] ?? '', 80)),
                    'user' => $users->publicUser($user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];
            })(),
            default => throw new RuntimeException('Неизвестное действие приглашения.'),
        };
    });

    if ($action === 'create' && is_array($result['invite'] ?? null)) {
        $token = (string)($result['invite']['token'] ?? '');
        $shareUrl = mgw_invite_share_url($config, $token);
        $shareText = mgw_invite_share_text($result['invite'], $shareUrl);
        $result['invite']['share_url'] = $shareUrl;
        $result['invite']['share_text'] = $shareText;
        $result['invite']['prepared_message_id'] = mgw_prepare_invite_message(
            $config,
            (string)($tgUser['id'] ?? ''),
            $result['invite'],
            $shareUrl,
            $shareText
        );
    }

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
