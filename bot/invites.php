<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/WebAppLaunchUrl.php';
require_once __DIR__ . '/services/GameInviteService.php';
require_once __DIR__ . '/services/InviteSignalService.php';
require_once __DIR__ . '/invites/RuntimeInviteDeltaProjector.php';

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
    return WebAppLaunchUrl::invitation($config, $token);
}

function mgw_invite_share_url(array $config, string $token): string
{
    $username = mgw_invite_bot_username($config);
    if ($username === '') return '';
    return 'https://t.me/' . rawurlencode($username) . '?start=invite_' . rawurlencode($token);
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

    $webAppUrl = mgw_invite_webapp_url($config, (string)$invite['token']);
    if ($webAppUrl === '') return false;

    $text = (string)($invite['source'] ?? '') === 'rematch'
        ? "🎮 Вам предлагают реванш\n\n"
            . (string)($invite['inviter_name'] ?? 'Игрок') . ' ждёт повторную партию в «'
            . (string)($invite['game_title'] ?? 'игру') . '».'
        : "🎮 Вас пригласили сыграть\n\n"
            . (string)($invite['inviter_name'] ?? 'Игрок') . ' приглашает вас в «'
            . (string)($invite['game_title'] ?? 'игру') . '».';

    $text .= "\n\n"
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
                        'web_app' => ['url' => $webAppUrl],
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

/** @return array<string,string> */
function mgw_invite_row_fingerprints(array $invites): array
{
    $result = [];
    foreach ($invites as $invite) {
        if (!is_array($invite)) continue;
        $token = strtolower(trim((string)($invite['token'] ?? '')));
        if ($token === '') continue;
        $encoded = json_encode($invite, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $result[$token] = hash('sha256', $encoded);
    }
    return $result;
}

/** @return list<string> */
function mgw_changed_invite_tokens(array $before, array $afterInvites): array
{
    $after = mgw_invite_row_fingerprints($afterInvites);
    $changed = [];
    foreach ($after as $token => $fingerprint) {
        if (!isset($before[$token]) || !hash_equals((string)$before[$token], $fingerprint)) {
            $changed[] = $token;
        }
    }
    sort($changed, SORT_STRING);
    return $changed;
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $action = clean_string($payload['action'] ?? '', 40);
    $sessionId = clean_string($payload['sessionId'] ?? '', 120);
    // Warm draft creation explicitly opts out of Telegram PreparedInlineMessage.
    // A normal caller that omits the flag retains the historical prepared path.
    $prepareMessage = $action === 'create_link_draft'
        && (!array_key_exists('prepareMessage', $payload) || filter_var($payload['prepareMessage'], FILTER_VALIDATE_BOOL));
    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $users = new UserService($config);
    $sessions = new SessionService($config);
    $catalog = new GameCatalogService($config);
    $games = new ChessRuntimeService($config, $catalog, new GameService($config));
    $invites = new GameInviteService($config, $catalog, $games);
    $inviteSignals = new InviteSignalService($config);
    $db = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $legacyBridgeAllowed = RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed();
    $runtimeInviteProjector = $legacyBridgeAllowed
        ? new RuntimeInviteDeltaProjector($config, $runtimeStorageRouter)
        : null;

    if ($action === 'sync') {
        $result = $db->readOnlySections(
            ['users', 'games', 'invites', 'notifications'],
            function (array $data) use (
                $payload,
                $sessionId,
                $tgUser,
                $users,
                $sessions,
                $invites
            ): array {
                $userId = trim((string)($tgUser['id'] ?? ''));
                if ($userId === '' || !isset($data['users'][$userId]) || !is_array($data['users'][$userId])) {
                    throw new RuntimeException('Пользователь не найден.');
                }
                $user = $data['users'][$userId];
                $sessions->ensureSessionShape($user);
                $token = clean_string($payload['token'] ?? '', 80);
                $core = $invites->sync($data, $user, $token);
                $core['user'] = $users->publicUser($user);
                $core['session'] = $sessions->publicState($user, $sessionId);
                return $core;
            }
        );
    } else {
        $result = $db->transaction(function (array &$data) use (
            $action,
            $payload,
            $sessionId,
            $tgUser,
            $users,
            $sessions,
            $invites,
            $config
        ): array {
            $inviteFingerprintsBefore = mgw_invite_row_fingerprints(
                is_array($data['invites'] ?? null) ? $data['invites'] : []
            );

            $user = $users->ensureUser($data, $tgUser);
            $userId = (string)($user['id'] ?? '');
            if ($userId === '') throw new RuntimeException('Пользователь не найден.');
            $data['users'][$userId] = $user;
            $user =& $data['users'][$userId];
            $sessions->ensureSessionShape($user);

            $gameType = clean_string($payload['gameType'] ?? 'tictactoe', 60);
            $room = UnifiedGameZonePolicy::storageRoom();
            $bet = UnifiedGameZonePolicy::entryCost($config);
            $boardSize = (int)($payload['boardSize'] ?? 3);
            $token = clean_string($payload['token'] ?? '', 80);
            $core = [];

            if ($token !== '' && in_array($action, ['accept', 'start', 'decline', 'cancel'], true)) {
                foreach ($data['invites'] ?? [] as $storedInvite) {
                    if (!is_array($storedInvite) || (string)($storedInvite['token'] ?? '') !== $token) continue;
                    $inviterId = (string)($storedInvite['inviter_id'] ?? '');
                    $inviteeId = (string)($storedInvite['invitee_id'] ?? '');
                    $core['signal_recipient_id'] = $userId === $inviterId ? $inviteeId : $inviterId;
                    break;
                }
            }

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
                    $invites->bindFromLink($data, $user, $token, true, false);
                    $core = $invites->sync($data, $user, $token);
                    break;

                case 'accept':
                    $sessions->assertCanPlay($user, $sessionId);
                    $sessions->touch($user, $sessionId);
                    $core += $invites->accept($data, $user, $token);
                    break;

                case 'start':
                    $sessions->assertCanPlay($user, $sessionId);
                    $sessions->touch($user, $sessionId);
                    $core += $invites->start($data, $user, $token);
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

            $core['_bridge_invite_tokens'] = mgw_changed_invite_tokens(
                $inviteFingerprintsBefore,
                is_array($data['invites'] ?? null) ? $data['invites'] : []
            );
            $core['user'] = $users->publicUser($user);
            $core['session'] = $sessions->publicState($user, $sessionId);
            return $core;
        });
    }

    $bridgeInviteTokens = array_values(array_filter(
        is_array($result['_bridge_invite_tokens'] ?? null) ? $result['_bridge_invite_tokens'] : [],
        static fn($token): bool => is_string($token) && $token !== ''
    ));
    unset($result['_bridge_invite_tokens']);

    $actorId = (string)($tgUser['id'] ?? '');
    $signalToken = (string)($result['invite']['token'] ?? $payload['token'] ?? '');
    $signalRecipientId = (string)($result['signal_recipient_id'] ?? '');
    if ($action === 'create_direct'
        && is_array($result['invite'] ?? null)
        && (string)($result['invite']['status'] ?? '') === 'pending') {
        $inviteSignals->publish((string)($result['recipient_id'] ?? ''), $result['invite']);
    } elseif ($action === 'rematch'
        && is_array($result['invite'] ?? null)
        && (string)($result['invite']['status'] ?? '') === 'pending') {
        // Canonical JSON is already committed. Wake the active opponent before
        // DB projection or Telegram delivery; invite-watch only triggers a
        // canonical sync and never becomes a second state owner.
        $inviteSignals->publish((string)($result['opponent_id'] ?? ''), $result['invite']);
    } elseif (in_array($action, ['accept', 'start', 'decline', 'cancel'], true) && $signalToken !== '') {
        $inviteSignals->clear($actorId, $signalToken);
        if ($signalRecipientId !== '' && is_array($result['invite'] ?? null)) {
            $inviteSignals->publish($signalRecipientId, $result['invite']);
        }
    }
    unset($result['signal_recipient_id']);

    if ($action !== 'sync'
        && $runtimeInviteProjector instanceof RuntimeInviteDeltaProjector
        && $runtimeInviteProjector->enabled()
        && $bridgeInviteTokens !== []) {
        if ($db instanceof ProjectionSnapshotStorageInterface) {
            $db->projectionReadOnlySections(
                ['invites'],
                static fn(array $data): array => $runtimeInviteProjector->synchronizeTokens($data, $bridgeInviteTokens)
            );
        } elseif ($db instanceof ExclusiveSnapshotStorageInterface) {
            $db->exclusiveReadOnlySections(
                ['invites'],
                static fn(array $data): array => $runtimeInviteProjector->synchronizeTokens($data, $bridgeInviteTokens)
            );
        } else {
            throw new RuntimeException('Invite DB bridge requires a stable JSON snapshot capability.');
        }
    }

    if ($action === 'create_link_draft' && is_array($result['invite'] ?? null)) {
        $token = (string)($result['invite']['token'] ?? '');
        $shareUrl = mgw_invite_share_url($config, $token);
        if ($shareUrl === '') {
            throw new RuntimeException('Не удалось подготовить Telegram-приглашение.');
        }
        $shareText = mgw_invite_share_text($result['invite'], $shareUrl);
        $result['invite']['share_url'] = $shareUrl;
        $result['invite']['share_text'] = $shareText;
        $result['invite']['prepared_message_id'] = $prepareMessage
            ? mgw_prepare_invite_message(
                $config,
                (string)($tgUser['id'] ?? ''),
                $result['invite'],
                $shareUrl,
                $shareText
            )
            : '';
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
