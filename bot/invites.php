<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/GameInviteService.php';

function mgw_invite_bot_username(array $config): string
{
    $username = ltrim(trim((string)($config['bot_username'] ?? '')), '@');
    if ($username !== '') return $username;

    try {
        $response = (new TelegramService($config))->api('getMe');
        if (!empty($response['ok']) && is_array($response['result'] ?? null)) {
            return ltrim(trim((string)($response['result']['username'] ?? '')), '@');
        }
    } catch (Throwable $e) {
        error_log('Mini Games World invite getMe failed: ' . $e->getMessage());
    }

    return '';
}

function mgw_invite_webapp_url(array $config, string $token): string
{
    $baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
    return $baseUrl . '/app/?v=85&invite=' . rawurlencode($token);
}

function mgw_invite_share_url(array $config, string $token): string
{
    $username = mgw_invite_bot_username($config);
    if ($username !== '') {
        return 'https://t.me/' . rawurlencode($username) . '?start=invite_' . rawurlencode($token);
    }
    return mgw_invite_webapp_url($config, $token);
}

function mgw_invite_board_label(array $invite): string
{
    $gameType = (string)($invite['game_type'] ?? '');
    $size = (int)($invite['board_size'] ?? 0);
    if ($gameType === 'domino') return 'Классика 0–6';
    if ($gameType === 'four_in_a_row') {
        return $size . '×' . max(5, (int)($invite['board_rows'] ?? ($size - 1)));
    }
    return $size . '×' . $size;
}

function mgw_invite_share_text(array $invite, string $shareUrl): string
{
    return "🎮 Приглашение в Mini Games World\n\n"
        . (string)($invite['inviter_name'] ?? 'Игрок') . " приглашает вас сыграть!\n\n"
        . '🎲 Игра: ' . (string)($invite['game_title'] ?? 'Игра') . "\n"
        . '🏠 Комната: ' . (string)($invite['room_label'] ?? 'Матч-комната') . "\n"
        . '📐 Вариант: ' . mgw_invite_board_label($invite) . "\n"
        . '🪙 Ставка: ' . (int)($invite['bet'] ?? 0) . " коинов\n\n"
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
        $response = (new TelegramService($config))->api('savePreparedInlineMessage', [
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

        return !empty($response['ok']) && is_array($response['result'] ?? null)
            ? (string)($response['result']['id'] ?? '')
            : '';
    } catch (Throwable $e) {
        error_log('Mini Games World prepared invite failed: ' . $e->getMessage());
        return '';
    }
}

function mgw_send_invite_message(array $config, array $invite, string $recipientId): bool
{
    if ($recipientId === '' || (string)($invite['token'] ?? '') === '') return false;

    $text = (string)($invite['source'] ?? '') === 'rematch'
        ? "🎮 Вам предлагают реванш\n\n"
            . (string)($invite['inviter_name'] ?? 'Игрок') . ' ждёт повторную партию в «'
            . (string)($invite['game_title'] ?? 'игру') . '».'
        : "🎮 Вас пригласили сыграть\n\n"
            . (string)($invite['inviter_name'] ?? 'Игрок') . ' приглашает вас в «'
            . (string)($invite['game_title'] ?? 'игру') . '».';

    $text .= "\n\n"
        . (string)($invite['room_label'] ?? 'Матч-комната') . ' · '
        . mgw_invite_board_label($invite) . ' · '
        . (int)($invite['bet'] ?? 0) . ' коинов';

    try {
        $response = (new TelegramService($config))->api('sendMessage', [
            'chat_id' => $recipientId,
            'text' => $text,
            'reply_markup' => [
                'inline_keyboard' => [[
                    [
                        'text' => '🎮 Открыть приглашение',
                        'web_app' => ['url' => mgw_invite_webapp_url($config, (string)$invite['token'])],
                    ],
                ]],
            ],
            'disable_web_page_preview' => true,
        ]);
        return !empty($response['ok']);
    } catch (Throwable $e) {
        error_log('Mini Games World invite Telegram notification failed for ' . $recipientId . ': ' . $e->getMessage());
        return false;
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
    $invites = new GameInviteService($config, $catalog, $games);
    $db = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));

    $result = $db->transaction(function (array &$data) use (
        $action,
        $payload,
        $sessionId,
        $tgUser,
        $users,
        $sessions,
        $invites
    ): array {
        $user = $users->ensureUser($data, $tgUser);
        $userId = (string)($user['id'] ?? '');
        if ($userId === '') throw new RuntimeException('Пользователь не найден.');
        $data['users'][$userId] = $user;
        $user =& $data['users'][$userId];
        $sessions->ensureSessionShape($user);

        $gameType = clean_string($payload['gameType'] ?? 'tictactoe', 60);
        $room = clean_string($payload['room'] ?? 'match', 20);
        $bet = (int)($payload['bet'] ?? 10);
        $boardSize = (int)($payload['boardSize'] ?? 3);
        $token = clean_string($payload['token'] ?? '', 80);
        $core = [];

        switch ($action) {
            case 'create_link_draft':
                $sessions->assertCanPlay($user, $sessionId);
                $sessions->touch($user, $sessionId);
                $core['invite'] = $invites->createLinkDraft($data, $user, $gameType, $room, $bet, $boardSize);
                break;

            case 'confirm_shared':
                $core['invite'] = $invites->confirmShared($data, $user, $token);
                break;

            case 'discard_draft':
                $core['invite'] = $invites->discardDraft($data, $user, $token);
                break;

            case 'create_direct':
                $sessions->assertCanPlay($user, $sessionId);
                $sessions->touch($user, $sessionId);
                $inviteeId = clean_string($payload['inviteeId'] ?? '', 40);
                if ($inviteeId === '' || !isset($data['users'][$inviteeId]) || !is_array($data['users'][$inviteeId])) {
                    throw new RuntimeException('Игрок больше недоступен.');
                }
                $invitee =& $data['users'][$inviteeId];
                $core['invite'] = $invites->createDirect($data, $user, $invitee, $gameType, $room, $bet, $boardSize);
                $core['recipient_id'] = $inviteeId;
                $core['recipient_name'] = (string)($core['invite']['invitee_name'] ?? 'Игрок');
                $lastSeen = strtotime((string)($invitee['last_seen_at'] ?? '')) ?: 0;
                $core['recipient_recently_active'] = $lastSeen > 0 && time() - $lastSeen <= 60;
                break;

            case 'open_link':
                $core['invite'] = $invites->bindFromLink($data, $user, $token, true, true);
                $invites->markSeen($data, $userId, $token);
                break;

            case 'sync':
                $core = $invites->sync($data, $user, $token);
                break;

            case 'accept':
                $sessions->assertCanPlay($user, $sessionId);
                $sessions->touch($user, $sessionId);
                $core = $invites->accept($data, $user, $token);
                break;

            case 'start':
                $sessions->assertCanPlay($user, $sessionId);
                $sessions->touch($user, $sessionId);
                $core = $invites->start($data, $user, $token);
                break;

            case 'decline':
                $core['invite'] = $invites->decline($data, $user, $token);
                break;

            case 'cancel':
                $core['invite'] = $invites->cancel($data, $user, $token);
                break;

            case 'rematch':
                $sessions->assertCanPlay($user, $sessionId);
                $sessions->touch($user, $sessionId);
                $core = $invites->createRematch(
                    $data,
                    $user,
                    clean_string($payload['gameId'] ?? '', 120)
                );
                $opponentId = (string)($core['opponent_id'] ?? '');
                if ($opponentId !== '' && isset($data['users'][$opponentId]) && is_array($data['users'][$opponentId])) {
                    $lastSeen = strtotime((string)($data['users'][$opponentId]['last_seen_at'] ?? '')) ?: 0;
                    $core['opponent_recently_active'] = $lastSeen > 0 && time() - $lastSeen <= 60;
                }
                break;

            case 'seen':
                $invites->markSeen($data, $userId, $token);
                $core['seen'] = true;
                break;

            default:
                throw new RuntimeException('Неизвестное действие приглашения.');
        }

        $core['user'] = $users->publicUser($user);
        $core['session'] = $sessions->publicState($user, $sessionId);
        return $core;
    });

    if ($action === 'create_link_draft' && is_array($result['invite'] ?? null)) {
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

    if (in_array($action, ['create_direct', 'rematch'], true)
        && is_array($result['invite'] ?? null)
        && (string)($result['invite']['status'] ?? '') === 'pending') {
        $recipientId = (string)($result['recipient_id'] ?? $result['opponent_id'] ?? '');
        $recipientRecent = !empty($result['recipient_recently_active'])
            || !empty($result['opponent_recently_active']);
        $result['telegram_sent'] = !$recipientRecent
            && mgw_send_invite_message($config, $result['invite'], $recipientId);
    }

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
