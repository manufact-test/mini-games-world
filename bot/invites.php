<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/app/runtime/localization/LocalizationCatalog.php';

function mgw_invite_copy(string $key, array $params = [], string $emergencyFallback = ''): string
{
    try {
        static $catalog = null;
        if (!$catalog instanceof LocalizationCatalog) {
            $catalog = new LocalizationCatalog(dirname(__DIR__) . '/app/locales');
        }
        $rawLocale = strtolower(trim((string)($_SERVER['HTTP_X_MGW_LOCALE'] ?? '')));
        $requestedLocale = explode('-', str_replace('_', '-', $rawLocale), 2)[0] ?? '';
        $locale = in_array($requestedLocale, $catalog->supportedLocales(), true) ? $requestedLocale : null;
        return $catalog->translate($key, $params, $locale);
    } catch (Throwable $error) {
        error_log('[MiniGamesWorld invite localization] ' . $error->getMessage());
        $fallback = $emergencyFallback;
        foreach ($params as $name => $value) {
            $fallback = str_replace('{' . $name . '}', (string)$value, $fallback);
        }
        return $fallback;
    }
}
require_once __DIR__ . '/moderation/ModerationService.php';
require_once __DIR__ . '/helpers/WebAppLaunchUrl.php';
require_once __DIR__ . '/services/GameInviteService.php';
require_once __DIR__ . '/services/InviteSignalService.php';
require_once __DIR__ . '/invites/RuntimeInviteDeltaProjector.php';
require_once __DIR__ . '/social/SocialInviteGuard.php';

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
    $baseUrl = rtrim(trim((string)($config['base_url'] ?? '')), '/');
    $normalizedToken = strtolower(trim($token));
    if ($baseUrl === '' || preg_match('/^[a-f0-9]{24}$/', $normalizedToken) !== 1) return '';
    return $baseUrl . '/invite/' . rawurlencode($normalizedToken);
}

function mgw_invite_telegram_open_url(array $config, string $token): string
{
    $username = mgw_invite_bot_username($config);
    $normalizedToken = strtolower(trim($token));
    if ($username === '' || preg_match('/^[a-f0-9]{24}$/', $normalizedToken) !== 1) return '';
    return 'https://t.me/' . rawurlencode($username) . '?start=invite_' . rawurlencode($normalizedToken);
}

function mgw_invite_board_label(array $invite): string
{
    $gameType = (string)($invite['game_type'] ?? '');
    $size = (int)($invite['board_size'] ?? 0);
    if ($gameType === 'domino') return mgw_invite_copy('server.invites.domino_variant', [], 'Classic 0–6');
    if ($gameType === 'four_in_a_row') {
        return $size . '×' . max(5, (int)($invite['board_rows'] ?? ($size - 1)));
    }
    return $size . '×' . $size;
}

/** Keep stored game_title and game_type untouched; localize only presentation. */
function mgw_invite_game_title(array $invite): string
{
    $gameType = (string)($invite['game_type'] ?? '');
    $fallback = (string)($invite['game_title'] ?? mgw_invite_copy('server.invites.game_fallback', [], 'Game'));
    if (!in_array($gameType, [
        'tictactoe', 'four_in_a_row', 'battleship', 'checkers',
        'reversi', 'chess', 'go', 'domino',
    ], true)) return $fallback;
    return mgw_invite_copy('games.' . $gameType . '.name', [], $fallback);
}

function mgw_invite_share_text(array $invite): string
{
    $playerFallback = mgw_invite_copy('server.invites.player_fallback', [], 'Player');
    $gameFallback = mgw_invite_copy('server.invites.game_fallback', [], 'Game');
    return mgw_invite_copy(
        'server.invites.share_text',
        [
            'name' => (string)($invite['inviter_name'] ?? $playerFallback),
            'game' => mgw_invite_game_title($invite),
            'board' => mgw_invite_board_label($invite),
            'bet' => (int)($invite['bet'] ?? 0),
        ],
        "🎮 Mini Games World invitation\n\n{name} invites you to play!\n\n🎲 Game: {game}\n📐 Variant: {board}\n🪙 Bet: {bet} coins\n\nOpen the invitation and accept the challenge 👇"
    );
}

function mgw_prepare_invite_message(
    array $config,
    string $userId,
    array $invite,
    string $telegramOpenUrl,
    string $shareText
): string {
    if ($userId === '' || $telegramOpenUrl === '') return '';

    try {
        $response = (new TelegramService($config))->api('savePreparedInlineMessage', [
            'user_id' => (int)$userId,
            'result' => [
                'type' => 'article',
                'id' => 'invite_' . (string)($invite['token'] ?? ''),
                'title' => mgw_invite_copy('server.invites.prepared_title', [], 'Mini Games World invitation'),
                'description' => mgw_invite_game_title($invite)
                    . ' · ' . mgw_invite_board_label($invite),
                'input_message_content' => [
                    'message_text' => $shareText,
                    'link_preview_options' => ['is_disabled' => true],
                ],
                'reply_markup' => [
                    'inline_keyboard' => [[
                        ['text' => mgw_invite_copy('server.invites.open_button', [], '🎮 Open invitation'), 'url' => $telegramOpenUrl],
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

    $playerFallback = mgw_invite_copy('server.invites.player_fallback', [], 'Player');
    $gameFallback = mgw_invite_copy('server.invites.game_lower_fallback', [], 'game');
    $messageParams = [
        'name' => (string)($invite['inviter_name'] ?? $playerFallback),
        'game' => mgw_invite_game_title($invite),
        'board' => mgw_invite_board_label($invite),
        'bet' => (int)($invite['bet'] ?? 0),
    ];
    $text = (string)($invite['source'] ?? '') === 'rematch'
        ? mgw_invite_copy(
            'server.invites.rematch_message',
            $messageParams,
            "🎮 Rematch offered\n\n{name} is waiting for another game of “{game}”.\n\n{board} · {bet} coins"
        )
        : mgw_invite_copy(
            'server.invites.direct_message',
            $messageParams,
            "🎮 You were invited to play\n\n{name} invites you to “{game}”.\n\n{board} · {bet} coins"
        );

    try {
        $response = (new TelegramService($config))->api('sendMessage', [
            'chat_id' => $recipientId,
            'text' => $text,
            'reply_markup' => [
                'inline_keyboard' => [[
                    [
                        'text' => mgw_invite_copy('server.invites.open_button', [], '🎮 Open invitation'),
                        'web_app' => ['url' => $webAppUrl],
                    ],
                ]],
            ],
            'disable_web_page_preview' => true,
        ]);
        return !empty($response['ok']);
    } catch (Throwable $e) {
        $message = trim($e->getMessage());
        $botToken = trim((string)($config['bot_token'] ?? ''));
        if ($botToken !== '') $message = str_replace($botToken, '[redacted-bot-token]', $message);
        error_log('Mini Games World invite Telegram notification failed: ' . substr($message, 0, 600));
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
        $encoded = json_encode(
            $invite,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_THROW_ON_ERROR
        );
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

/**
 * Invite mutations commit to canonical JSON before compatibility DB projection.
 * For a successful mutation, release the HTTP response first so projection
 * latency can never repaint or stall the already-committed product action.
 */
function mgw_invite_api_ok_with_deferred_work(array $data, callable $afterResponse): void
{
    mgw_run_api_success_hooks();
    $payload = ['ok' => true] + mgw_normalize_api_data($data);

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        http_response_code(500);
        error_log('[MiniGamesWorld invite response] JSON encoding failed: ' . json_last_error_msg());
        echo '{"ok":false,"error_code":"INVITE_RESPONSE_ENCODING_FAILED"}';
        exit;
    }

    echo $json;

    // Never substitute post-response projection with a blocking fallback.
    // Hostinger may expose either FastCGI/FPM or LiteSpeed request finalization.
    $finished = false;
    if (function_exists('fastcgi_finish_request')) {
        $finished = fastcgi_finish_request() !== false;
    } elseif (function_exists('litespeed_finish_request')) {
        $finished = litespeed_finish_request() !== false;
    }
    if (!$finished) {
        error_log('[MiniGamesWorld invite deferred projection] finish-request API unavailable; projection skipped');
        exit;
    }

    ignore_user_abort(true);
    try {
        $afterResponse();
    } catch (Throwable $error) {
        // The product mutation and response are already authoritative. Projection
        // recovery/audit remains operational work and must not leak details.
        error_log('[MiniGamesWorld invite deferred projection] hook failed: ' . get_class($error));
    }
    exit;
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error(mgw_invite_copy('server.invites.invalid_request', [], 'Invalid request.'));

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

    $socialInviteGuard = null;
    $moderation = null;
    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    $socialRouter = new RuntimeStorageRouter($config);
    if ($databaseConfig->enabled()
        && (!$socialRouter->enabled() || $socialRouter->routeFor('accounts') === RuntimeStorageRouter::DRIVER_DATABASE)) {
        $socialDatabase = PdoConnectionFactory::create($databaseConfig);
        $socialInviteGuard = new SocialInviteGuard($socialDatabase);
        $moderation = new ModerationService($socialDatabase);
    }
    $actorMgwId = strtoupper(trim((string)($tgUser['mgw_id'] ?? '')));
    if ($moderation instanceof ModerationService
        && MgwIdGenerator::isValid($actorMgwId)
        && in_array($action, ['create_link_draft','confirm_shared','create_direct','open_link','accept','start','rematch'], true)) {
        $moderation->assertAllowed($actorMgwId, 'gameplay');
    }
    $identityProvider = SocialInviteGuard::providerForAuthenticatedUser($tgUser);

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
                    throw new RuntimeException(mgw_invite_copy('server.invites.user_not_found', [], 'User not found.'));
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
            $config,
            $socialInviteGuard,
            $actorMgwId,
            $identityProvider
        ): array {
            $inviteFingerprintsBefore = mgw_invite_row_fingerprints(
                is_array($data['invites'] ?? null) ? $data['invites'] : []
            );

            $user = $users->ensureUser($data, $tgUser);
            $userId = (string)($user['id'] ?? '');
            if ($userId === '') throw new RuntimeException(mgw_invite_copy('server.invites.user_not_found', [], 'User not found.'));
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
                    $inviteeInput = clean_string($payload['inviteeId'] ?? '', 40);
                    $targetMgwId = MgwIdGenerator::fromPublic($inviteeInput);
                    if ($targetMgwId !== null) {
                        if (!$socialInviteGuard instanceof SocialInviteGuard || !MgwIdGenerator::isValid($actorMgwId)) {
                            throw new RuntimeException(mgw_invite_copy('server.invites.social_unavailable', [], 'Social invitations are temporarily unavailable.'));
                        }
                        $inviteeId = $socialInviteGuard->runtimeSubjectForMgwId($actorMgwId, $targetMgwId, $identityProvider);
                    } else {
                        $inviteeId = $inviteeInput;
                        if ($socialInviteGuard instanceof SocialInviteGuard && MgwIdGenerator::isValid($actorMgwId)) {
                            $socialInviteGuard->assertRuntimeSubjectNotBlocked($actorMgwId, $inviteeId, $identityProvider);
                        }
                    }
                    if ($inviteeId === '' || !isset($data['users'][$inviteeId]) || !is_array($data['users'][$inviteeId])) {
                        throw new RuntimeException(mgw_invite_copy('server.invites.player_unavailable', [], 'The player is no longer available.'));
                    }
                    $invitee =& $data['users'][$inviteeId];
                    $core['invite'] = $invites->createDirect($data, $user, $invitee, $gameType, $room, $bet, $boardSize);
                    $core['recipient_id'] = $inviteeId;
                    $core['recipient_name'] = (string)($core['invite']['invitee_name'] ?? mgw_invite_copy('server.invites.player_fallback', [], 'Player'));
                    $lastSeen = strtotime((string)($invitee['last_seen_at'] ?? '')) ?: 0;
                    $core['recipient_recently_active'] = $lastSeen > 0 && time() - $lastSeen <= 60;
                    break;

                case 'open_link':
                    if ($socialInviteGuard instanceof SocialInviteGuard && MgwIdGenerator::isValid($actorMgwId)) {
                        foreach ($data['invites'] ?? [] as $storedInvite) {
                            if (!is_array($storedInvite) || (string)($storedInvite['token'] ?? '') !== $token) continue;
                            $inviterRuntimeId = trim((string)($storedInvite['inviter_id'] ?? ''));
                            if ($inviterRuntimeId !== '') {
                                $socialInviteGuard->assertRuntimeSubjectNotBlocked($actorMgwId, $inviterRuntimeId, $identityProvider);
                            }
                            break;
                        }
                    }
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
                    if ($opponentId !== ''
                        && $socialInviteGuard instanceof SocialInviteGuard
                        && MgwIdGenerator::isValid($actorMgwId)) {
                        $socialInviteGuard->assertRuntimeSubjectNotBlocked($actorMgwId, $opponentId, $identityProvider);
                    }
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
                    throw new RuntimeException(mgw_invite_copy('server.invites.unknown_action', [], 'Unknown invitation action.'));
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

    // A private link draft is not yet shared product state. For every other
    // mutation, preserve exact changed-token DB projection but move it behind
    // the successful HTTP response. JSON + InviteSignal already own product state.
    $deferredInviteProjection = null;
    if ($action !== 'sync'
        && $action !== 'create_link_draft'
        && $runtimeInviteProjector instanceof RuntimeInviteDeltaProjector
        && $runtimeInviteProjector->enabled()
        && $bridgeInviteTokens !== []) {
        $projectionTokens = $bridgeInviteTokens;
        $deferredInviteProjection = static function () use (
            $db,
            $runtimeInviteProjector,
            $projectionTokens
        ): void {
            if ($db instanceof ProjectionSnapshotStorageInterface) {
                $db->projectionReadOnlySections(
                    ['invites'],
                    static fn(array $data): array => $runtimeInviteProjector->synchronizeTokens($data, $projectionTokens)
                );
                return;
            }
            if ($db instanceof ExclusiveSnapshotStorageInterface) {
                $db->exclusiveReadOnlySections(
                    ['invites'],
                    static fn(array $data): array => $runtimeInviteProjector->synchronizeTokens($data, $projectionTokens)
                );
                return;
            }
            throw new RuntimeException('Invite DB bridge requires a stable JSON snapshot capability.');
        };
    }

    if ($action === 'create_link_draft' && is_array($result['invite'] ?? null)) {
        $token = (string)($result['invite']['token'] ?? '');
        $shareUrl = mgw_invite_share_url($config, $token);
        $telegramOpenUrl = mgw_invite_telegram_open_url($config, $token);
        if ($shareUrl === '' || $telegramOpenUrl === '') {
            throw new RuntimeException(mgw_invite_copy('server.invites.telegram_prepare_failed', [], 'The Telegram invitation could not be prepared.'));
        }
        $shareText = mgw_invite_share_text($result['invite']);
        $result['invite']['share_url'] = $shareUrl;
        $result['invite']['telegram_open_url'] = $telegramOpenUrl;
        $result['invite']['share_text'] = $shareText;
        $result['invite']['prepared_message_id'] = $prepareMessage
            ? mgw_prepare_invite_message(
                $config,
                (string)($tgUser['id'] ?? ''),
                $result['invite'],
                $telegramOpenUrl,
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

    if (is_callable($deferredInviteProjection)) {
        mgw_invite_api_ok_with_deferred_work($result, $deferredInviteProjection);
    }
    api_ok($result);
} catch (ModerationException $e) {
    json_response(['ok'=>false,'code'=>$e->reason,'error'=>$e->getMessage()], 403);
} catch (Throwable $e) {
    api_error($e->getMessage());
}