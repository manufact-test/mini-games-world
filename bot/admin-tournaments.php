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

    json_response([
        'ok'=>true,
        'generated_at'=>gmdate(DATE_ATOM),
        'notifications'=>$notifications,
        'manual_acceptance'=>$manualAvailability,
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
