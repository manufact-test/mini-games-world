<?php
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/GameLaunchFinalizationService.php';
require_once __DIR__ . '/services/MatchPreparationRuntimeService.php';

function mgw_cleanup_games_if_due(array &$data, ChessRuntimeService $games, bool $force = false): void
{
    if (!isset($data['system']) || !is_array($data['system'])) {
        $data['system'] = [];
    }

    $lastCleanup = strtotime((string)($data['system']['game_cleanup_at'] ?? '')) ?: 0;
    if (!$force && $lastCleanup > 0 && time() - $lastCleanup < 2) {
        return;
    }

    $games->cleanup($data);
    $data['system']['game_cleanup_at'] = now_iso();
}

function mgw_mark_matchmaking_presence(array &$user, string $room, string $gameType, int $boardSize): void
{
    $user['last_matchmaking_room'] = $room === 'gold' ? 'gold' : 'match';
    $user['last_matchmaking_game_type'] = $gameType;
    $user['last_matchmaking_board_size'] = $boardSize;
    $user['last_matchmaking_at'] = now_iso();
}

function mgw_room_for_recent_user(array $data, string $userId): ?string
{
    foreach ($data['queue'] ?? [] as $item) {
        if (!is_array($item) || (string)($item['user_id'] ?? '') !== $userId) continue;
        return ($item['room'] ?? 'match') === 'gold' ? 'gold' : 'match';
    }

    $currentGameId = trim((string)($data['users'][$userId]['current_game_id'] ?? ''));
    if ($currentGameId !== '' && isset($data['games'][$currentGameId]) && is_array($data['games'][$currentGameId])) {
        return ($data['games'][$currentGameId]['room'] ?? 'match') === 'gold' ? 'gold' : 'match';
    }

    $lastRoom = (string)($data['users'][$userId]['last_matchmaking_room'] ?? '');
    return in_array($lastRoom, ['match', 'gold'], true) ? $lastRoom : null;
}

function mgw_has_other_recent_human_in_room(array $data, string $userId, string $room): bool
{
    $now = time();
    $presenceWindowSec = 90;

    foreach ($data['users'] ?? [] as $otherId => $other) {
        $otherId = (string)$otherId;
        if ($otherId === '' || $otherId === $userId || str_starts_with($otherId, 'bot_') || !is_array($other)) {
            continue;
        }

        $lastSeen = strtotime((string)($other['last_seen_at'] ?? '')) ?: 0;
        if ($lastSeen <= 0 || $now - $lastSeen > $presenceWindowSec) {
            continue;
        }

        $knownRoom = mgw_room_for_recent_user($data, $otherId);
        if ($knownRoom !== null && $knownRoom !== $room) {
            continue;
        }

        return true;
    }

    return false;
}

function mgw_prepare_match_bot_fallback(array &$data, string $userId, bool $otherRecentHuman): void
{
    if (!isset($data['queue']) || !is_array($data['queue'])) return;

    foreach ($data['queue'] as &$item) {
        if (!is_array($item) || (string)($item['user_id'] ?? '') !== $userId) continue;

        // Queue creation time is immutable once realtime first projects the row.
        // Prepare the complete fallback policy inside the original start_search
        // transaction so every later request sees one stable queue identity.
        if ($otherRecentHuman) {
            // The bounded five-second policy starts two seconds in, preserving the
            // existing real-world ~3 second window for a human match to win first.
            $item['created_at'] = gmdate('c', time() - 2);
            $item['status'] = 'bot_fallback_5s';
            $item['updated_at'] = now_iso();
        } else {
            // Standard bot fallback is 15 seconds. Backdating by 12 seconds leaves
            // the same actual three-second human window without a later mutation.
            $item['created_at'] = gmdate('c', time() - 12);
        }
        unset($item);
        return;
    }
    unset($item);
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        api_error('Некорректный запрос.');
    }

    $action = (string)($payload['action'] ?? '');
    $sessionId = clean_string($payload['sessionId'] ?? '', 120);
    $deviceId = clean_string($payload['deviceId'] ?? '', 120);

    $db = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $auth = new AuthService($config);
    $users = new UserService($config);
    $gameCatalog = new GameCatalogService($config);
    $games = new ChessRuntimeService($config, $gameCatalog, new GameService($config));
    $gameActions = new GameActionService($gameCatalog, $games);
    $matchPreparationRuntime = new MatchPreparationRuntimeService($config);
    $shop = new ShopService($config, $users);
    $payments = new PaymentService($config, $users);
    $telegram = new TelegramService($config);
    $sessions = new SessionService($config);
    $presenceService = new PresenceService();
    $statsService = new StatsService($presenceService);
    $history = new HistoryService($config, $users);
    $weeklyMatch = new WeeklyMatchEconomyService($config, new NotificationService());

    $tgUser = $auth->getUserFromRequest($payload);
    if ($action === 'bootstrap' && $sessionId !== '') {
        try {
            $presenceService->touch((string)($tgUser['id'] ?? ''), $sessionId);
        } catch (Throwable $presenceError) {
            error_log('Mini Games World bootstrap presence failed: ' . $presenceError->getMessage());
        }
    }

    $result = $db->transaction(function (array &$data) use ($action, $payload, $tgUser, $users, $games, $gameActions, $matchPreparationRuntime, $shop, $payments, $sessions, $statsService, $history, $weeklyMatch, $sessionId, $deviceId) {
        $user = $users->ensureUser($data, $tgUser);
        $userId = (string)$user['id'];
        $data['users'][$userId] = $user;
        $user =& $data['users'][$userId];

        $sessions->ensureSessionShape($user);

        // MVP-9: если плановый cron был пропущен, первый вход игрока безопасно
        // догоняет только его собственное недельное начисление. Повтор невозможен
        // благодаря cycle key на пользователе.
        $weeklyMatch->applyDueForUser($data, $user);

        // game_state owns a new session-first ordering below: its polling may
        // refresh search, create a bot game or advance Phase B lifecycle, so even
        // the bounded cleanup must wait until active session ownership is checked.
        $forceCleanup = in_array($action, ['start_search', 'leave_search', 'game_action', 'make_move'], true);
        if ($action !== 'game_state') {
            mgw_cleanup_games_if_due($data, $games, $forceCleanup);
        }

        switch ($action) {
            case 'bootstrap':
                $sessions->touch($user, $sessionId);
                $active = $games->findActiveGameForUser($data, $userId);
                return [
                    'user' => $users->publicUser($user),
                    'session' => $sessions->publicState($user, $sessionId),
                    'shop' => $shop->status($user),
                    'weekly_match' => $weeklyMatch->status($data, $user),
                    'match_economy' => MatchEconomyRuntimeConfig::publicStatus($config),
                    'games' => $games->catalog(),
                    'stats' => $statsService->build($data),
                    'active_game' => $active ? $games->publicGame($active, $userId) : null,
                ];

            case 'stats':
                return [
                    'stats' => $statsService->build($data),
                    'session' => $sessions->publicState($user, $sessionId),
                ];

            case 'weekly_match_status':
                return [
                    'user' => $users->publicUser($user),
                    'weekly_match' => $weeklyMatch->status($data, $user),
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

                $itemId = clean_string($payload['itemId'] ?? '', 80);
                $denominationId = clean_string($payload['denominationId'] ?? '', 80);
                $requestToken = (int)($payload['requestToken'] ?? 0);
                $order = $shop->createOrder($data, $user, $itemId, $denominationId, $requestToken);

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
                $room = $room === 'gold' ? 'gold' : 'match';
                $bet = (int)($payload['bet'] ?? 10);
                $boardSize = (int)($payload['boardSize'] ?? 3);
                $gameType = clean_string($payload['gameType'] ?? 'tictactoe', 60);
                mgw_mark_matchmaking_presence($user, $room, $gameType, $boardSize);

                $existingGameIdBeforeSearch = ($user['status'] ?? '') === 'playing'
                    ? trim((string)($user['current_game_id'] ?? ''))
                    : '';
                $search = $games->startSearch($data, $user, $room, $bet, $boardSize, $gameType);

                if (empty($search['game']) && $room === 'match') {
                    mgw_prepare_match_bot_fallback(
                        $data,
                        $userId,
                        mgw_has_other_recent_human_in_room($data, $userId, $room)
                    );
                }

                if (!empty($search['game']['id'])) {
                    $gameId = (string)$search['game']['id'];
                    $finalizedGame = GameLaunchFinalizationService::finalizeStoredGame(
                        $data,
                        $gameId,
                        $existingGameIdBeforeSearch === '' || $existingGameIdBeforeSearch !== $gameId
                    );
                    if (is_array($finalizedGame)) {
                        $search['game'] = $games->publicGame($finalizedGame, $userId);
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
                $requestedGameId = clean_string($payload['gameId'] ?? '', 80);
                $lifecycleSessionOwned = false;
                if (in_array((string)($user['status'] ?? 'idle'), ['searching', 'playing'], true)) {
                    $sessions->assertCanPlay($user, $sessionId);
                    $sessions->touch($user, $sessionId);
                    $lifecycleSessionOwned = true;
                }

                mgw_cleanup_games_if_due($data, $games, false);

                $game = null;
                $createdFallbackGameId = '';
                if (($user['status'] ?? '') === 'searching') {
                    $games->refreshSearch($data, $user);
                    $game = $games->maybeCreateBotGameForSearchingUser($data, $user);
                    if (is_array($game)) {
                        $createdFallbackGameId = (string)($game['id'] ?? '');
                    }
                }

                if ($requestedGameId !== '' && isset($data['games'][$requestedGameId])) {
                    $candidate = $data['games'][$requestedGameId];
                    if (in_array($userId, array_map('strval', $candidate['player_ids'] ?? []), true)) {
                        $game = $candidate;
                    }
                }

                if (!$game) {
                    $game = $games->findActiveGameForUser($data, $userId);
                }

                if ($game) {
                    $storedGameId = (string)($game['id'] ?? '');
                    $isCurrentParticipant = $storedGameId !== ''
                        && (string)($user['current_game_id'] ?? '') === $storedGameId
                        && in_array($userId, array_map('strval', $game['player_ids'] ?? []), true);

                    if ($isCurrentParticipant && !$lifecycleSessionOwned) {
                        $sessions->assertCanPlay($user, $sessionId);
                        $sessions->touch($user, $sessionId);
                        $lifecycleSessionOwned = true;
                    }

                    if ($isCurrentParticipant) {
                        $finalizedGame = GameLaunchFinalizationService::finalizeStoredGame(
                            $data,
                            $storedGameId,
                            $createdFallbackGameId !== '' && $createdFallbackGameId === $storedGameId
                        );
                        if (is_array($finalizedGame)) $game = $finalizedGame;

                        $synchronizedGame = $matchPreparationRuntime->synchronizeCurrentGame(
                            $data,
                            $user,
                            $storedGameId,
                            $requestedGameId,
                            $sessionId,
                            $deviceId
                        );
                        if (is_array($synchronizedGame)) $game = $synchronizedGame;
                    }
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
                ];

            case 'game_action':
                $sessions->assertCanPlay($user, $sessionId);
                $sessions->touch($user, $sessionId);

                $gameId = clean_string($payload['gameId'] ?? '', 80);
                $gameAction = $payload['gameAction'] ?? null;
                if (!is_array($gameAction)) {
                    $gameAction = [
                        'type' => clean_string($payload['actionType'] ?? '', 40),
                        'cell' => $payload['cell'] ?? null,
                    ];
                }

                $game = $gameActions->apply($data, $user, $gameId, $gameAction);

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
                $game = $gameActions->apply($data, $user, $gameId, [
                    'type' => 'cell',
                    'cell' => $cell,
                ]);

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
