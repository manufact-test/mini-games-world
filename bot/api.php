<?php
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/GameLaunchFinalizationService.php';
require_once __DIR__ . '/services/MatchPreparationRuntimeService.php';
require_once __DIR__ . '/tournaments/TournamentAdminNotificationBridge.php';

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

function mgw_is_battleship_fire_fast_path(array $data, string $action, array $payload): bool
{
    if ($action !== 'game_action') return false;

    $gameId = clean_string($payload['gameId'] ?? '', 80);
    if ($gameId === '' || !isset($data['games'][$gameId]) || !is_array($data['games'][$gameId])) {
        return false;
    }

    $gameAction = $payload['gameAction'] ?? null;
    $actionType = is_array($gameAction)
        ? trim((string)($gameAction['type'] ?? ''))
        : trim((string)($payload['actionType'] ?? ''));

    return $actionType === 'fire'
        && (string)($data['games'][$gameId]['game_type'] ?? '') === 'battleship'
        && (string)($data['games'][$gameId]['phase'] ?? '') === 'battle';
}

function mgw_emit_tournament_full_admin_event(
    JsonDatabase $db,
    array $config,
    array $snapshot
): void {
    $adminIds = is_array($config['admin_ids'] ?? null) ? $config['admin_ids'] : [];
    if ($adminIds === []) return;

    $db->transaction(function (array &$data) use ($adminIds, $snapshot): void {
        (new TournamentAdminNotificationBridge())->emitRegistrationFull(
            $data,
            $adminIds,
            $snapshot
        );
    });
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

    $result = $db->transaction(function (array &$data) use ($action, $payload, $tgUser, $users, $games, $gameCatalog, $gameActions, $matchPreparationRuntime, $shop, $payments, $sessions, $statsService, $history, $weeklyMatch, $runtimeHiddenSkillBridge, $sessionId, $deviceId, $config) {
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
        $battleshipFireFastPath = mgw_is_battleship_fire_fast_path($data, $action, $payload);
        $forceCleanup = in_array($action, ['start_search', 'leave_search', 'game_action', 'make_move'], true);
        if ($action !== 'game_state' && !$battleshipFireFastPath) {
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

            case 'staging_test_tournament_balance':
                if (strtolower(trim((string)($config['environment'] ?? ''))) !== 'staging'
                    || empty($tgUser['is_staging_test_user'])
                    || !in_array($userId, ['stg_test_player_a', 'stg_test_player_b'], true)) {
                    throw new RuntimeException('Staging tournament balance control is unavailable.');
                }
                $targetBalance = filter_var(
                    $payload['targetBalance'] ?? null,
                    FILTER_VALIDATE_INT
                );
                if ($targetBalance === false || $targetBalance < 0 || $targetBalance > 250000) {
                    throw new InvalidArgumentException('Staging tournament target balance is invalid.');
                }
                $requestToken = trim((string)($payload['requestToken'] ?? ''));
                if (preg_match('/^[A-Za-z0-9._:-]{12,96}$/', $requestToken) !== 1) {
                    throw new InvalidArgumentException('Staging tournament request token is invalid.');
                }

                $mgwId = trim((string)($user['mgw_id'] ?? ''));
                $accountRef = trim((string)($user['mgw_account_ref'] ?? ''));
                if ($mgwId === '' || $accountRef === '') {
                    throw new RuntimeException('Staging tournament balance control requires canonical account identity.');
                }

                $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
                if (!$databaseConfig->enabled()) {
                    throw new RuntimeException('Staging tournament balance control requires an enabled database.');
                }
                $database = PdoConnectionFactory::create($databaseConfig);
                $ledger = new LedgerWriteService($database);
                $balance = $ledger->getBalance(
                    $accountRef,
                    TournamentRegistrationService::ENTRY_ASSET
                );
                if (!is_array($balance)) {
                    throw new RuntimeException('Staging tournament balance is unavailable.');
                }
                if ((int)($balance['reserved_amount'] ?? 0) !== 0) {
                    throw new RuntimeException('Staging tournament balance control refuses an active reservation.');
                }

                $available = (int)($balance['available_amount'] ?? 0);
                $delta = (int)$targetBalance - $available;
                if ($delta !== 0) {
                    $ledger->postAvailableDelta([
                        'operation_key'=>'staging-tournament-e2e:' . $userId . ':' . $requestToken,
                        'account_ref'=>$accountRef,
                        'mgw_id'=>$mgwId,
                        'legacy_user_id'=>$userId,
                        'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
                        'available_delta'=>$delta,
                        'category'=>'staging_test_adjustment',
                        'source_type'=>'staging_test',
                        'source_ref'=>'mvp21_1_live_e2e',
                        'metadata'=>[
                            'test_only'=>true,
                            'target_balance'=>(int)$targetBalance,
                        ],
                    ]);
                }

                $balance = $ledger->getBalance(
                    $accountRef,
                    TournamentRegistrationService::ENTRY_ASSET
                );
                if (!is_array($balance)
                    || (int)($balance['available_amount'] ?? -1) !== (int)$targetBalance
                    || (int)($balance['reserved_amount'] ?? -1) !== 0) {
                    throw new RuntimeException('Staging tournament balance control did not reach the requested state.');
                }
                $user[UnifiedBalanceRuntimeState::FIELD] = (int)$targetBalance;

                return [
                    'test_only'=>true,
                    'balance'=>$balance,
                    'user'=>$users->publicUser($user),
                    'session'=>$sessions->publicState($user, $sessionId),
                ];

            case 'tournament_status':
            case 'tournament_register':
            case 'tournament_leave':
                $mgwId = trim((string)($user['mgw_id'] ?? ''));
                $accountRef = trim((string)($user['mgw_account_ref'] ?? ''));
                if ($mgwId === '' || $accountRef === '') {
                    throw new RuntimeException('Регистрация турниров требует канонической MGW account identity.');
                }

                $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
                if (!$databaseConfig->enabled()) {
                    throw new RuntimeException('Турниры временно недоступны.');
                }
                $database = PdoConnectionFactory::create($databaseConfig);
                $tournaments = new TournamentRegistrationService(
                    $database,
                    new LedgerWriteService($database)
                );

                if ($action === 'tournament_status') {
                    $snapshot = $tournaments->snapshot($mgwId, $accountRef);
                } else {
                    // Tournament capacity and holds are durable DB-owned state.
                    // The normal application runtime may still be JSON-first
                    // outside the bounded DB-primary rehearsal window, so a
                    // tournament write must not depend on that temporary latch.
                    // Mirror the canonical ledger's spendable amount into the
                    // current runtime user. EconomyRuntimeBridge then verifies
                    // JSON/ledger parity after the successful API transaction
                    // while preserving reserved_amount as a held balance.
                    $rulesConsent = null;
                    if ($action === 'tournament_register') {
                        $rulesConsent = [
                            'accepted'=>($payload['tournamentRulesAccepted'] ?? false) === true,
                            'version'=>clean_string($payload['tournamentRulesVersion'] ?? '', 64),
                            'language'=>clean_string($payload['tournamentRulesLanguage'] ?? '', 12),
                            'sha256'=>clean_string($payload['tournamentRulesSha256'] ?? '', 64),
                        ];
                    }
                    $snapshot = $action === 'tournament_register'
                        ? $tournaments->register($mgwId, $accountRef, null, $rulesConsent)
                        : $tournaments->leave($mgwId, $accountRef);

                    $available = (int)($snapshot['balance']['available_amount'] ?? -1);
                    $reserved = (int)($snapshot['balance']['reserved_amount'] ?? -1);
                    if ($available < 0 || $reserved < 0) {
                        throw new RuntimeException('Tournament balance result is invalid.');
                    }
                    $user[UnifiedBalanceRuntimeState::FIELD] = $available;
                }

                return [
                    'snapshot' => $snapshot,
                    'user' => $users->publicUser($user),
                    'session' => $sessions->publicState($user, $sessionId),
                ];

            case 'tournament_match_state':
            case 'tournament_match_ready':
                $mgwId = trim((string)($user['mgw_id'] ?? ''));
                $accountRef = trim((string)($user['mgw_account_ref'] ?? ''));
                if ($mgwId === '' || $accountRef === '' || $userId === '') {
                    throw new RuntimeException('Турнирный матч требует канонической MGW account identity.');
                }

                $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
                if (!$databaseConfig->enabled()) {
                    throw new RuntimeException('Турнирные матчи временно недоступны.');
                }
                $database = PdoConnectionFactory::create($databaseConfig);
                $readiness = new TournamentMatchReadinessService($database);
                $progression = new TournamentRoundProgressionService($database);

                // MVP-21.5 owns the first-round Ready row. Resolve readiness first
                // so two live clients reaching T0 together cannot be blocked by the
                // later-round progression materializer racing to create the same row.
                $snapshot = $action === 'tournament_match_ready'
                    ? $readiness->markReady($mgwId, $accountRef, $userId)
                    : $readiness->status($mgwId, $accountRef, $userId);

                $readyMatch = is_array($snapshot['match'] ?? null)
                    ? $snapshot['match']
                    : null;
                $attachedReadyGameId = trim((string)($readyMatch['game_id'] ?? ''));
                if ($attachedReadyGameId !== ''
                    && isset($data['games'][$attachedReadyGameId])
                    && is_array($data['games'][$attachedReadyGameId])
                    && (string)($data['games'][$attachedReadyGameId]['status'] ?? '') === 'active'
                    && (string)($data['games'][$attachedReadyGameId]['match_source'] ?? '') === 'tournament'
                    && in_array($userId, array_map('strval', $data['games'][$attachedReadyGameId]['player_ids'] ?? []), true)) {
                    return [
                        'snapshot'=>$snapshot,
                        'progression'=>null,
                        'game'=>$games->publicGame($data['games'][$attachedReadyGameId], $userId),
                        'user'=>$users->publicUser($user),
                        'session'=>$sessions->publicState($user, $sessionId),
                    ];
                }
                $initialReadyOwnsWindow = is_array($readyMatch)
                    && (int)($readyMatch['round_no'] ?? 0) === 1
                    && trim((string)($readyMatch['game_id'] ?? '')) === ''
                    && in_array(
                        (string)($readyMatch['launch_state'] ?? ''),
                        [
                            TournamentMatchReadinessService::STATE_WAITING_READY,
                            TournamentMatchReadinessService::STATE_READY,
                        ],
                        true
                    );

                // MVP-21.6 progression is intentionally dormant while MVP-21.5
                // owns the initial two-minute Ready window. This avoids a second
                // first-round materializer touching the same pair at T0. Once a
                // game is attached (or readiness does not own this participant),
                // progression resumes and can observe finishes / later rounds.
                $progressionSnapshot = null;
                if (!$initialReadyOwnsWindow) {
                    $progressionSnapshot = $progression->statusForParticipant(
                        $mgwId,
                        $accountRef,
                        $userId
                    );
                    $latestProgressionMatch = is_array($progressionSnapshot['latest_match'] ?? null)
                        ? $progressionSnapshot['latest_match']
                        : null;
                    $finishedTournamentGameId = trim((string)($latestProgressionMatch['game_id'] ?? ''));
                    if ($finishedTournamentGameId !== ''
                        && isset($data['games'][$finishedTournamentGameId])
                        && is_array($data['games'][$finishedTournamentGameId])
                        && (string)($data['games'][$finishedTournamentGameId]['status'] ?? '') === 'finished') {
                        $progression->observeFinishedGame($data['games'][$finishedTournamentGameId]);
                        $progressionSnapshot = $progression->statusForParticipant(
                            $mgwId,
                            $accountRef,
                            $userId
                        );
                        // The durable progression owner may have completed the
                        // initial pair or moved it into a replay/next-round wait.
                        // Re-read the MVP-21.5 projection in the same response so
                        // the Hall can never keep rendering a stale "both ready /
                        // match starting" card after the runtime game is terminal.
                        $snapshot = $readiness->status($mgwId, $accountRef, $userId);
                    }
                }

                $launch = $readiness->launchContext($mgwId, $accountRef, $userId);
                $progressionOwnsLaunch = false;
                if (!is_array($launch) && !$initialReadyOwnsWindow) {
                    $launch = $progression->launchContextForParticipant(
                        $mgwId,
                        $accountRef,
                        $userId
                    );
                    $progressionOwnsLaunch = is_array($launch);
                }

                $publicGame = null;
                if (is_array($launch)) {
                    $gameId = (string)$launch['game_id'];
                    $attachedGameId = trim((string)($launch['attached_game_id'] ?? ''));
                    if ($attachedGameId !== '' && $attachedGameId !== $gameId) {
                        throw new RuntimeException('Tournament pair is attached to an unexpected game.');
                    }

                    $launchPlayers = is_array($launch['players'] ?? null) ? $launch['players'] : [];
                    if (count($launchPlayers) !== 2) {
                        throw new RuntimeException('Tournament launch requires exactly two runtime players.');
                    }
                    $aId = trim((string)($launchPlayers[0]['legacy_user_id'] ?? ''));
                    $bId = trim((string)($launchPlayers[1]['legacy_user_id'] ?? ''));
                    if ($aId === '' || $bId === '' || $aId === $bId
                        || !isset($data['users'][$aId]) || !is_array($data['users'][$aId])
                        || !isset($data['users'][$bId]) || !is_array($data['users'][$bId])) {
                        throw new RuntimeException('Один из игроков ещё не синхронизировал игровой клиент.');
                    }

                    $gameType = $gameCatalog->normalizeGameType((string)$launch['game_type']);
                    $definition = $gameCatalog->get($gameType);
                    $boardSize = (int)($definition['default_board_size'] ?? 3);
                    $a =& $data['users'][$aId];
                    $b =& $data['users'][$bId];

                    $games->createTournamentGame(
                        $data,
                        $a,
                        $b,
                        $gameType,
                        $boardSize,
                        $gameId,
                        [
                            'tournament_id'=>(string)$launch['tournament_id'],
                            'tournament_round_no'=>(int)$launch['round_no'],
                            'tournament_pair_no'=>(int)$launch['pair_no'],
                            'tournament_attempt_no'=>(int)($launch['attempt_no'] ?? 1),
                            'tournament_match_kind'=>(string)($launch['match_kind'] ?? 'elimination'),
                            'tournament_wait_kind'=>(string)($launch['wait_kind'] ?? 'initial_ready'),
                            'tournament_side_swap'=>!empty($launch['side_swap']),
                        ]
                    );
                    if (!isset($data['games'][$gameId]) || !is_array($data['games'][$gameId])) {
                        throw new RuntimeException('Tournament game was not persisted.');
                    }

                    $data['games'][$gameId]['launch_countdown_sec'] = 10;
                    GameLaunchFinalizationService::finalizeStoredGame($data, $gameId, true);
                    $tournamentGame =& $data['games'][$gameId];

                    // Tournament Ready only selects and creates the shared game.
                    // The common 10-second countdown starts later, after BOTH real
                    // clients have actually adopted this exact game through
                    // game_state and Phase-B has recorded both device-ready marks.
                    // This prevents the second Ready request from spending half of
                    // the countdown before the first client has entered the game.
                    if ($progressionOwnsLaunch) {
                        $progression->attachGame(
                            (string)$launch['tournament_id'],
                            (int)$launch['round_no'],
                            (int)$launch['pair_no'],
                            (int)($launch['attempt_no'] ?? 1),
                            $gameId
                        );
                    } else {
                        $readiness->attachGame(
                            (string)$launch['tournament_id'],
                            (int)$launch['round_no'],
                            (int)$launch['pair_no'],
                            $gameId
                        );
                    }
                    $snapshot = $readiness->status($mgwId, $accountRef, $userId);
                    if ($progressionOwnsLaunch) {
                        $progressionSnapshot = $progression->statusForParticipant(
                            $mgwId,
                            $accountRef,
                            $userId
                        );
                    }
                    $publicGame = in_array($userId, array_map('strval', $tournamentGame['player_ids'] ?? []), true)
                        ? $games->publicGame($tournamentGame, $userId)
                        : null;
                    unset($a, $b, $tournamentGame);
                }

                if ($publicGame === null) {
                    $currentGame = $games->findActiveGameForUser($data, $userId);
                    if (is_array($currentGame)
                        && (string)($currentGame['match_source'] ?? '') === 'tournament') {
                        $publicGame = $games->publicGame($currentGame, $userId);
                    }
                }

                return [
                    'snapshot'=>$snapshot,
                    'progression'=>$progressionSnapshot,
                    'game'=>$publicGame,
                    'user'=>$users->publicUser($user),
                    'session'=>$sessions->publicState($user, $sessionId),
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
                $gameType = $gameCatalog->normalizeGameType(
                    clean_string($payload['gameType'] ?? 'tictactoe', 60)
                );
                $skillBand = $runtimeHiddenSkillBridge->skillBandForUser(
                    trim((string)($user['mgw_id'] ?? '')),
                    $gameType
                );
                mgw_mark_matchmaking_presence($user, $gameType, $boardSize);

                $existingGameIdBeforeSearch = ($user['status'] ?? '') === 'playing'
                    ? trim((string)($user['current_game_id'] ?? ''))
                    : '';
                $search = $games->startSearch(
                    $data,
                    $user,
                    $room,
                    $bet,
                    $boardSize,
                    $gameType,
                    $skillBand
                );

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

    if ($action === 'tournament_register'
        && !empty($result['snapshot']['transition']['registration_closed_now'])) {
        try {
            mgw_emit_tournament_full_admin_event($db, $config, (array)$result['snapshot']);
        } catch (Throwable $notifyError) {
            // Registration is already authoritative DB state. Notification
            // failure must never roll back or misreport the successful last seat.
            error_log('Mini Games World tournament-full admin notification failed: ' . $notifyError->getMessage());
        }
    }

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
    $isStagingTournamentTest = strtolower(trim((string)($config['environment'] ?? ''))) === 'staging'
        && in_array(
            (string)($action ?? ''),
            ['bootstrap', 'staging_test_tournament_balance', 'tournament_register', 'tournament_leave'],
            true
        )
        && is_array($tgUser ?? null)
        && !empty($tgUser['is_staging_test_user'])
        && in_array(
            (string)($tgUser['id'] ?? ''),
            ['stg_test_player_a', 'stg_test_player_b'],
            true
        );

    if ($isStagingTournamentTest) {
        $storageContext = class_exists('RuntimePrimaryEntrypointStorageContext', false)
            ? RuntimePrimaryEntrypointStorageContext::safeReport()
            : ['installed'=>false,'storage_driver'=>'json'];
        $selectorFallback = null;
        try {
            $selectorMethod = new ReflectionMethod(
                StorageFactory::class,
                'stagingApiPrimaryNotificationSnapshotIsBehind'
            );
            $selectorMethod->setAccessible(true);
            $selectorFallback = (bool)$selectorMethod->invoke(null, $config);
        } catch (Throwable) {
            $selectorFallback = null;
        }

        json_response([
            'ok'=>false,
            'error'=>mgw_public_api_error($e->getMessage()),
            'debug_error'=>substr($e->getMessage(), 0, 1800),
            'debug_exception'=>get_class($e),
            'debug_storage'=>[
                'adapter_class'=>isset($db) && is_object($db) ? get_class($db) : '',
                'driver'=>isset($db) && is_object($db) && method_exists($db, 'driver')
                    ? (string)$db->driver()
                    : '',
                'primary_context'=>$storageContext,
                'stale_notification_fallback'=>$selectorFallback,
            ],
            'test_only'=>true,
        ], 400);
    }

    api_error($e->getMessage());
}
