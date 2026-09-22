<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/AdminWebAuth.php';
require_once __DIR__ . '/tournaments/TournamentParticipantNotificationBridge.php';
require_once __DIR__ . '/tournaments/StagingTournamentManualAcceptanceService.php';

function mgw_apply_tournament_cancellation_runtime(
    array $config,
    string $tournamentId,
    string $kind,
    string $reason,
    array $balances,
    string $cancelledAt
): array {
    $storage = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $noContest = new GameNoContestSettlementService($config);
    $finishReason = $kind === TournamentCancellationService::KIND_EMERGENCY
        ? 'tournament_emergency_stop'
        : 'tournament_cancelled';

    return $storage->transaction(function (array &$data) use (
        $tournamentId,
        $kind,
        $reason,
        $balances,
        $cancelledAt,
        $noContest,
        $finishReason
    ): array {
        if (!isset($data['users']) || !is_array($data['users'])) $data['users'] = [];
        if (!isset($data['games']) || !is_array($data['games'])) $data['games'] = [];

        $updatedBalances = 0;
        foreach ($balances as $legacyUserId=>$balance) {
            if (!isset($data['users'][$legacyUserId]) || !is_array($data['users'][$legacyUserId])) continue;
            $available = (int)($balance['available_amount'] ?? -1);
            if ($available < 0) continue;
            $data['users'][$legacyUserId][UnifiedBalanceRuntimeState::FIELD] = $available;
            $updatedBalances++;
        }

        $cancelledGames = 0;
        $annulledGames = 0;
        foreach ($data['games'] as &$game) {
            if (!is_array($game)
                || (string)($game['match_source'] ?? '') !== 'tournament'
                || (string)($game['tournament_id'] ?? '') !== $tournamentId) {
                continue;
            }

            if ((string)($game['status'] ?? '') !== 'finished') {
                $noContest->cancel(
                    $data,
                    $game,
                    $finishReason,
                    $kind === TournamentCancellationService::KIND_EMERGENCY
                        ? 'Возврат: аварийная остановка турнира'
                        : 'Возврат: турнир отменён',
                    [
                        'tournament_id'=>$tournamentId,
                        'tournament_cancellation_kind'=>$kind,
                        'tournament_cancellation_reason'=>$reason,
                    ]
                );
                $cancelledGames++;
            }

            $game['tournament_result_annulled'] = true;
            $game['tournament_result_annulled_at'] = $cancelledAt;
            $game['tournament_annulment_reason'] = $finishReason;
            $game['updated_at'] = $cancelledAt;
            $annulledGames++;
        }
        unset($game);

        $hiddenNotifications = 0;
        $audienceRef = 'official-tournament:' . $tournamentId;
        if (isset($data['notifications']) && is_array($data['notifications'])) {
            foreach ($data['notifications'] as &$notification) {
                if (!is_array($notification)
                    || (string)($notification['audience_type'] ?? '') !== 'tournament'
                    || (string)($notification['audience_ref'] ?? '') !== $audienceRef
                    || (string)($notification['source_type'] ?? '') !== 'system'
                    || !empty($notification['hidden_at'])) {
                    continue;
                }
                if (empty($notification['read_at'])) $notification['read_at'] = $cancelledAt;
                $notification['hidden_at'] = $cancelledAt;
                $hiddenNotifications++;
            }
            unset($notification);
        }

        return [
            'updated_balances'=>$updatedBalances,
            'cancelled_active_games'=>$cancelledGames,
            'annulled_runtime_games'=>$annulledGames,
            'hidden_tournament_notifications'=>$hiddenNotifications,
        ];
    });
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok'=>false,'error'=>'Некорректный запрос.'], 400);
    }

    $admin = AdminWebAuth::authorize($config, (string)($payload['initData'] ?? ''));
    $telegramId = trim((string)($admin['id'] ?? ''));
    if ($telegramId === '') {
        throw new RuntimeException('Authorized Telegram admin identity is unavailable.');
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        json_response(['ok'=>false,'error'=>'Tournament Admin недоступен: DB отключена.'], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $ledger = new LedgerWriteService($database);
    $service = new TournamentRegistrationService($database, $ledger);
    $catalog = new GameCatalogService($config);
    $manualAcceptance = new StagingTournamentManualAcceptanceService($config, $database, $ledger, $service);
    $cancellation = new TournamentCancellationService($database, $ledger);
    $actorRef = 'telegram:' . $telegramId;
    $action = strtolower(trim((string)($payload['action'] ?? 'snapshot')));

    if ($action === 'snapshot') {
        $result = [
            'snapshot'=>$service->snapshot(),
            'games'=>$catalog->publicCatalog(),
        ];
    } elseif ($action === 'create_draft') {
        $gameType = $catalog->resolveGameType(clean_string($payload['game_type'] ?? '', 32));
        $capacity = (int)($payload['capacity'] ?? 0);
        $title = clean_string($payload['title'] ?? '', 160);
        $result = [
            'snapshot'=>$service->createDraft($gameType, $capacity, $title, $actorRef),
            'games'=>$catalog->publicCatalog(),
        ];
    } elseif ($action === 'open_registration') {
        $result = [
            'snapshot'=>$service->openRegistration(
                clean_string($payload['tournament_id'] ?? '', 64),
                $actorRef
            ),
            'games'=>$catalog->publicCatalog(),
        ];
    } elseif ($action === 'assign_date') {
        $result = [
            'snapshot'=>$service->assignFinalDate(
                clean_string($payload['tournament_id'] ?? '', 64),
                clean_string($payload['start_at_utc'] ?? '', 80),
                $actorRef
            ),
            'games'=>$catalog->publicCatalog(),
        ];
    } elseif ($action === 'prepare_manual_acceptance') {
        $fixture = $manualAcceptance->fillToOneManualSeat($_SERVER);
        $result = [
            'snapshot'=>$fixture['snapshot'],
            'games'=>$catalog->publicCatalog(),
            'manual_acceptance_fixture'=>$fixture,
        ];
    } elseif ($action === 'complete_fixture_pairs') {
        $completion = $manualAcceptance->completeFixtureOnlyPairs($_SERVER, $actorRef);
        $result = [
            'snapshot'=>$service->snapshot(),
            'games'=>$catalog->publicCatalog(),
            'manual_progression_result'=>$completion,
        ];
    } elseif ($action === 'reset_manual_acceptance') {
        $reset = $manualAcceptance->resetForFreshManualAcceptance(
            $_SERVER,
            $actorRef
        );
        $result = [
            'snapshot'=>$service->snapshot(),
            'games'=>$catalog->publicCatalog(),
            'manual_reset_result'=>$reset,
        ];
    } elseif ($action === 'cancel_tournament' || $action === 'emergency_stop') {
        $kind = $action === 'emergency_stop'
            ? TournamentCancellationService::KIND_EMERGENCY
            : TournamentCancellationService::KIND_CANCEL;
        $tournamentId = clean_string($payload['tournament_id'] ?? '', 64);
        $reason = clean_string($payload['reason'] ?? '', 1200);
        $confirmation = is_array($payload['confirmation'] ?? null)
            ? $payload['confirmation']
            : [];

        $cancelled = $cancellation->cancel(
            $tournamentId,
            $kind,
            $reason,
            $actorRef,
            $confirmation
        );
        $runtime = mgw_apply_tournament_cancellation_runtime(
            $config,
            $tournamentId,
            $kind,
            (string)($cancelled['reason'] ?? $reason),
            is_array($cancelled['runtime_balances'] ?? null) ? $cancelled['runtime_balances'] : [],
            (string)($cancelled['cancelled_at_utc'] ?? gmdate('Y-m-d H:i:s.u'))
        );
        unset($cancelled['runtime_balances']);

        $result = [
            'snapshot'=>$service->snapshot(),
            'games'=>$catalog->publicCatalog(),
            'cancellation_result'=>$cancelled + ['runtime'=>$runtime],
        ];
    } else {
        json_response(['ok'=>false,'error'=>'Неизвестное действие Tournament Admin.'], 422);
    }

    $notifications = null;
    $scheduledTournament = $result['snapshot']['tournament'] ?? null;
    if (is_array($scheduledTournament)
        && (string)($scheduledTournament['state'] ?? '') === TournamentRegistrationService::STATE_SCHEDULED) {
        try {
            $tournamentId = (string)($scheduledTournament['tournament_id'] ?? '');
            $participantMgwIds = $service->registeredParticipantMgwIds($tournamentId);
            $storage = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
            $bridge = new TournamentParticipantNotificationBridge();
            $notifications = $storage->transaction(
                static function (array &$data) use ($bridge, $result, $participantMgwIds): array {
                    return $bridge->ensureScheduleNotifications(
                        $data,
                        $result['snapshot'],
                        $participantMgwIds
                    );
                }
            );
            $notifications = ['ok'=>true] + $notifications;
        } catch (Throwable $notificationError) {
            error_log('[MiniGamesWorld tournament schedule notifications] ' . $notificationError->getMessage());
            $notifications = [
                'ok'=>false,
                'warning'=>'Дата сохранена. Напоминания будут повторно синхронизированы при следующем открытии Tournament Admin.',
            ];
        }
    }

    $manualAvailability = $manualAcceptance->availability($_SERVER);
    $manualResetAvailability = $manualAcceptance->resetAvailability($_SERVER);
    $manualProgressionAvailability = $manualAcceptance->progressionAcceptanceAvailability($_SERVER);
    $cancellationAvailability = $cancellation->availability();

    json_response([
        'ok'=>true,
        'generated_at'=>gmdate(DATE_ATOM),
        'notifications'=>$notifications,
        'manual_acceptance'=>$manualAvailability,
        'manual_reset'=>$manualResetAvailability,
        'manual_progression'=>$manualProgressionAvailability,
        'cancellation'=>$cancellationAvailability,
    ] + $result);
} catch (AdminWebAuthException $error) {
    json_response(['ok'=>false,'error'=>$error->publicMessage()], $error->httpStatus());
} catch (InvalidArgumentException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage()], 422);
} catch (RuntimeException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage()], 409);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld tournament admin] ' . $error->getMessage());
    json_response(['ok'=>false,'error'=>'Не удалось выполнить операцию Tournament Admin.'], 500);
}
