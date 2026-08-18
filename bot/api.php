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

function mgw_mark_matchmaking_presence(array &$user, string $gameType, int $boardSize): void
{
    unset($user['last_matchmaking_room']);
    $user['last_matchmaking_game_type'] = $gameType;
    $user['last_matchmaking_board_size'] = $boardSize;
    $user['last_matchmaking_at'] = now_iso();
}

function mgw_observe_matchmaking_source(array &$data, array $game): void
{
    if (!isset($data['system']) || !is_array($data['system'])) {
        $data['system'] = [];
    }
    if (!isset($data['system']['telemetry']) || !is_array($data['system']['telemetry'])) {
        $data['system']['telemetry'] = [];
    }

    $key = !empty($game['is_bot_game'])
        ? 'matchmaking_bot_match_total'
        : 'matchmaking_human_match_total';
    $data['system']['telemetry'][$key] = (int)($data['system']['telemetry'][$key] ?? 0) + 1;
}

function mgw_public_game_with_result(
    array $data,
    ?array $game,
    string $userId,
    ChessRuntimeService $games,
    HistoryService $history
): ?array {
    if (!is_array($game)) return null;

    $public = $games->publicGame($game, $userId);
    if ((string)($game['status'] ?? '') !== 'finished') return $public;

    $gameId = trim((string)($game['id'] ?? ''));
    if ($gameId === '') return $public;

    foreach ($history->matchHistory($data, $userId, 12) as $match) {
        if (!is_array($match) || (string)($match['id'] ?? '') !== $gameId) continue;
        $public['result_presentation'] = [
            'id' => $gameId,
            'opponent' => (string)($match['opponent'] ?? 'Соперник'),
            'result' => (string)($match['result'] ?? 'Матч завершён'),
            'tone' => (string)($match['tone'] ?? 'zero'),
            'game_type' => (string)($match['game_type'] ?? ''),
            'game_title' => (string)($match['game_title'] ?? 'Матч'),
            'board_size' => (int)($match['board_size'] ?? 0),
            'board_columns' => (int)($match['board_columns'] ?? 0),
            'board_rows' => (int)($match['board_rows'] ?? 0),
            'economy' => is_array($match['economy'] ?? null) ? $match['economy'] : null,
            'finished_at' => (string)($match['finished_at'] ?? ''),
        ];
        break;
    }

    return $public;
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

    $result = $db->transaction(function (array &$data) use ($action, $payload, $tgUser, $users, $games, $gameActions, $matchPreparationRuntime, $shop, $payments, $sessions, $statsService, $history, $weeklyMatch, $sessionId, $deviceId, $config) {
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
                    'active_game' => mgw_public_game_with_result($data, $active, $userId, $games, $history),
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
                UnifiedGameZonePolicy::rejectLegacyCommerceWrite();

            case 'shop_order':
                UnifiedGameZonePolicy::rejectLegacyCommerceWrite();

            case 'start_search':
                $sessions->assertCanPlay($user, $sessionId);
                $sessions->touch($user, $sessionId);

                $room = UnifiedGameZonePolicy::storageRoom();
                $bet = UnifiedGameZonePolicy::entryCost($config);
                $boardSize = (int)($payload['boardSize'] ?? 3);
                $gameType = clean_string($payload['gameType'] ?? 'tictactoe', 60);
                mgw_mark_matchmaking_presence($user, $gameType, $boardSize);

                $existingGameIdBeforeSearch = ($user['status'] ?? '') === 'playing'
                    ? trim((string)($user['current_game_id'] ?? ''))
                    : '';
                $search = $games->startSearch($data, $user, $room, $bet, $boardSize, $gameType);

                if (!empty($search['game']['id'])) {
                    $gameId = (string)$search['game']['id'];
                    if ($existingGameIdBeforeSearch === '' || $existingGameIdBeforeSearch !== $gameId) {
                        mgw_observe_matchmaking_source($data, $search['game']);
                    }
                    $finalizedGame = GameLaunchFinalizationService::finalizeStoredGame(
                        $data,
                        $gameId,
                        $existingGameIdBeforeSearch === '' || $existingGameIdBeforeSearch !== $gameId
                    );
                    if (is_array($finalizedGame)) {
                        $search['game'] = mgw_public_game_with_result($data, $finalizedGame, $userId, $games, $history);
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
                        if ($createdFallbackGameId !== '') {
                            mgw_observe_matchmaking_source($data, $game);
                        }
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
                    'game' => mgw_public_game_with_result($data, $game, $userId, $games, $history),
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
                    'game' => mgw_public_game_with_result($data, $game, $userId, $games, $history),
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
                            'game' => mgw_public_game_with_result($data, $candidate, $userId, $games, $history),
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
                    'game' => mgw_public_game_with_result($data, $game, $userId, $games, $history),
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
                    'game' => mgw_public_game_with_result($data, $game, $userId, $games, $history),
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