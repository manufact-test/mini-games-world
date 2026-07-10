<?php
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';

function mgw_randomize_symbols_for_new_game(array &$data, array $game): array
{
    if (($game['status'] ?? '') !== 'active') {
        return $game;
    }

    $gameId = (string)($game['id'] ?? '');
    if ($gameId === '' || !isset($data['games'][$gameId]) || !is_array($data['games'][$gameId])) {
        return $game;
    }

    $stored =& $data['games'][$gameId];
    if (!empty($stored['symbols_randomized'])) {
        return $stored;
    }

    $boardSize = (int)($stored['board_size'] ?? 3);
    $emptyBoard = str_repeat('-', max(1, $boardSize * $boardSize));
    if ((string)($stored['board'] ?? '') !== $emptyBoard) {
        $stored['symbols_randomized'] = true;
        return $stored;
    }

    $playerIds = array_values(array_map('strval', $stored['player_ids'] ?? []));
    if (count($playerIds) < 2) {
        $stored['symbols_randomized'] = true;
        return $stored;
    }

    if (random_int(0, 1) === 0) {
        $xPlayerId = $playerIds[0];
        $oPlayerId = $playerIds[1];
    } else {
        $xPlayerId = $playerIds[1];
        $oPlayerId = $playerIds[0];
    }

    $now = now_iso();
    $stored['symbols'] = [$xPlayerId => 'X', $oPlayerId => 'O'];
    $stored['turn'] = $xPlayerId;
    $stored['turn_started_at'] = $now;
    $stored['updated_at'] = $now;
    $stored['symbols_randomized'] = true;

    if (!empty($stored['is_bot_game'])) {
        $botId = (string)($stored['bot_id'] ?? '');
        if ($botId !== '' && $xPlayerId === $botId) {
            $stored['bot_move_after_at'] = gmdate('c', time() + 1);
        } else {
            unset($stored['bot_move_after_at']);
        }
    }

    return $stored;
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        api_error('Некорректный запрос.');
    }

    $action = (string)($payload['action'] ?? '');
    $sessionId = clean_string($payload['sessionId'] ?? '', 120);

    $db = new JsonDatabase((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $auth = new AuthService($config);
    $users = new UserService($config);
    $games = new GameService($config);
    $shop = new ShopService($config, $users);
    $payments = new PaymentService($config, $users);
    $telegram = new TelegramService($config);
    $sessions = new SessionService($config);
    $statsService = new StatsService();
    $history = new HistoryService($config, $users);

    $tgUser = $auth->getUserFromRequest($payload);

    $result = $db->transaction(function (array &$data) use ($action, $payload, $tgUser, $users, $games, $shop, $payments, $sessions, $statsService, $history, $sessionId) {
        $user = $users->ensureUser($data, $tgUser);
        $userId = (string)$user['id'];
        $data['users'][$userId] = $user;
        $user =& $data['users'][$userId];

        $sessions->ensureSessionShape($user);

        // MVP-3: каждая API-команда чистит старую очередь и просроченные ходы.
        $games->cleanup($data);

        switch ($action) {
            case 'bootstrap':
                $sessions->touch($user, $sessionId);
                $active = $games->findActiveGameForUser($data, $userId);
                return [
                    'user' => $users->publicUser($user),
                    'session' => $sessions->publicState($user, $sessionId),
                    'shop' => $shop->status($user),
                    'stats' => $statsService->build($data),
                    'active_game' => $active ? $games->publicGame($active, $userId) : null,
                ];

            case 'stats':
                return [
                    'stats' => $statsService->build($data),
                    'session' => $sessions->publicState($user, $sessionId),
                ];

            case 'profile':
                return [
                    'user' => $users->publicUser($user),
                    'stats' => $users->profileStats($user, $data),
                    'shop' => $shop->status($user),
                    'history' => $history->userHistory($data, $userId, 8),
                    'session' => $sessions->publicState($user, $sessionId),
                ];

            case 'history':
                return [
                    'user' => $users->publicUser($user),
                    'history' => $history->userHistory($data, $userId, 24),
                    'topups' => $payments->userTopupHistory($data, $userId, 20),
                    'shop' => $shop->status($user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];

            case 'shop_status':
                return [
                    'user' => $users->publicUser($user),
                    'shop' => $shop->status($user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];

            case 'payment_status':
                return [
                    'user' => $users->publicUser($user),
                    'payments' => $payments->status($data, $user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];

            case 'payment_plans':
                return [
                    'payments' => [
                        'enabled' => false,
                        'mode' => 'prepared',
                        'message' => 'Заявку на пополнение можно создать. Реальная оплата подключается отдельно.',
                        'plans' => $payments->plans(),
                    ],
                    'session' => $sessions->publicState($user, $sessionId),
                ];

            case 'payment_create_draft':
                $sessions->assertCanPlay($user, $sessionId);
                $sessions->touch($user, $sessionId);

                $room = (string)($payload['room'] ?? 'gold');
                $amount = (int)($payload['amount'] ?? $payload['amountRub'] ?? 0);
                $provider = clean_string($payload['provider'] ?? 'manual_test', 60);
                $payment = $payments->createDraftFromAmount($data, $user, $room, $amount, $provider);

                return [
                    'saved' => true,
                    'payment' => $payment,
                    'user' => $users->publicUser($user),
                    'payments' => $payments->status($data, $user),
                    'session' => $sessions->publicState($user, $sessionId),
                    'message' => 'Заявка на пополнение создана. Баланс не изменён.',
                ];

            case 'shop_order':
                $sessions->assertCanPlay($user, $sessionId);
                $sessions->touch($user, $sessionId);

                $country = clean_string($payload['country'] ?? '', 40);
                $provider = clean_string($payload['provider'] ?? '', 80);
                $amount = (int)($payload['amount'] ?? 0);
                $order = $shop->createOrder($data, $user, $country, $provider, $amount);

                return [
                    'saved' => true,
                    'order' => $order,
                    'user' => $users->publicUser($user),
                    'shop' => $shop->status($user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];

            case 'start_search':
                $sessions->assertCanPlay($user, $sessionId);
                $sessions->touch($user, $sessionId);

                $room = (string)($payload['room'] ?? 'match');
                $bet = (int)($payload['bet'] ?? 10);
                $boardSize = (int)($payload['boardSize'] ?? 3);
                $search = $games->startSearch($data, $user, $room, $bet, $boardSize);

                if (!empty($search['game']['id'])) {
                    $gameId = (string)$search['game']['id'];
                    if (isset($data['games'][$gameId])) {
                        $randomizedGame = mgw_randomize_symbols_for_new_game($data, $data['games'][$gameId]);
                        $search['game'] = $games->publicGame($randomizedGame, $userId);
                    }
                }

                return $search + [
                    'user' => $users->publicUser($user),
                    'stats' => $statsService->build($data),
                    'shop' => $shop->status($user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];

            case 'leave_search':
                $games->leaveSearch($data, $user);
                if (($user['status'] ?? '') !== 'playing') {
                    $sessions->releaseIfCurrent($user, $sessionId);
                }

                return [
                    'user' => $users->publicUser($user),
                    'stats' => $statsService->build($data),
                    'shop' => $shop->status($user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];

            case 'game_state':
                $game = null;
                if (($user['status'] ?? '') === 'searching') {
                    $games->refreshSearch($data, $user);
                    $game = $games->maybeCreateBotGameForSearchingUser($data, $user);
                }

                $gameId = clean_string($payload['gameId'] ?? '', 80);

                if ($gameId !== '' && isset($data['games'][$gameId])) {
                    $candidate = $data['games'][$gameId];
                    if (in_array($userId, array_map('strval', $candidate['player_ids'] ?? []), true)) {
                        $game = $candidate;
                    }
                }

                if (!$game) {
                    $game = $games->findActiveGameForUser($data, $userId);
                }

                if ($game) {
                    $game = mgw_randomize_symbols_for_new_game($data, $game);
                }

                if ($game && ($game['status'] ?? '') === 'active') {
                    $sessions->assertCanPlay($user, $sessionId);
                    $sessions->touch($user, $sessionId);
                }

                if ($game && ($game['status'] ?? '') === 'finished') {
                    $sessions->releaseIfCurrent($user, $sessionId);
                }

                return [
                    'user' => $users->publicUser($user),
                    'me' => ['id' => $userId],
                    'game' => $game ? $games->publicGame($game, $userId) : null,
                    'shop' => $shop->status($user),
                    'session' => $sessions->publicState($user, $sessionId),
                    'stats' => $statsService->build($data),
                ];

            case 'make_move':
                $gameId = clean_string($payload['gameId'] ?? '', 80);

                if ($gameId !== '' && isset($data['games'][$gameId])) {
                    $candidate = $data['games'][$gameId];
                    if (($candidate['status'] ?? '') === 'finished'
                        && in_array($userId, array_map('strval', $candidate['player_ids'] ?? []), true)) {
                        $sessions->releaseIfCurrent($user, $sessionId);

                        return [
                            'user' => $users->publicUser($user),
                            'me' => ['id' => $userId],
                            'game' => $games->publicGame($candidate, $userId),
                            'shop' => $shop->status($user),
                            'session' => $sessions->publicState($user, $sessionId),
                        ];
                    }
                }

                $sessions->assertCanPlay($user, $sessionId);
                $sessions->touch($user, $sessionId);

                $cell = (int)($payload['cell'] ?? -1);
                $game = $games->makeMove($data, $user, $gameId, $cell);

                if (($game['status'] ?? '') === 'finished') {
                    $sessions->releaseIfCurrent($user, $sessionId);
                }

                return [
                    'user' => $users->publicUser($user),
                    'me' => ['id' => $userId],
                    'game' => $games->publicGame($game, $userId),
                    'shop' => $shop->status($user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];

            case 'leave_game':
                $sessions->assertCanPlay($user, $sessionId);
                $sessions->touch($user, $sessionId);

                $gameId = clean_string($payload['gameId'] ?? '', 80);
                $game = $games->surrenderGame($data, $user, $gameId);

                $sessions->releaseIfCurrent($user, $sessionId);

                return [
                    'user' => $users->publicUser($user),
                    'me' => ['id' => $userId],
                    'game' => $games->publicGame($game, $userId),
                    'shop' => $shop->status($user),
                    'session' => $sessions->publicState($user, $sessionId),
                    'stats' => $statsService->build($data),
                ];

            case 'support':
                $type = clean_string($payload['type'] ?? 'message', 40);
                $message = clean_string($payload['message'] ?? '', 1200);

                if ($message === '') {
                    throw new RuntimeException('Сообщение пустое.');
                }

                $data['support'][] = [
                    'id' => make_id('support'),
                    'user_id' => $userId,
                    'username' => $user['username'] ?? '',
                    'type' => $type,
                    'message' => $message,
                    'created_at' => now_iso(),
                ];

                return ['saved' => true];

            case 'request_rematch':
                $sessions->assertCanPlay($user, $sessionId);
                return ['message' => 'Реванш будет подключён следующим этапом.'];

            default:
                throw new RuntimeException('Неизвестное действие.');
        }
    });

    if ($action === 'payment_create_draft'
        && !empty($result['saved'])
        && isset($result['payment'])
        && is_array($result['payment'])) {
        try {
            $telegram->notifyAdminsAboutPayment($result['payment']);
        } catch (Throwable $notifyError) {
            error_log('Mini Games World payment admin notification failed: ' . $notifyError->getMessage());
        }
    }

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
