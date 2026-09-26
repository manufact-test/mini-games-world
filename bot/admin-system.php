<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/AdminWebAuth.php';
require_once __DIR__ . '/system/SystemAdminService.php';
require_once __DIR__ . '/system/RuntimeFeatureFlagAdminService.php';
require_once __DIR__ . '/notifications/AdminNotificationEventService.php';

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
    if ($telegramId === '') throw new RuntimeException('Authorized Telegram admin identity is unavailable.');
    $actorRef = 'telegram:' . $telegramId;
    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        json_response(['ok'=>false,'error'=>'System Admin недоступен: DB отключена.'], 503);
    }
    $database = PdoConnectionFactory::create($databaseConfig);
    $system = new SystemAdminService($database);

    $externalConfigFile = getenv('MGW_CONFIG_FILE') ?: dirname(__DIR__, 2) . '/_private_mgw/config.php';
    $primaryConfigFile = is_file($externalConfigFile)
        ? $externalConfigFile
        : __DIR__ . '/config/config.php';
    $flagEditor = new RuntimeFeatureFlagAdminService(dirname($primaryConfigFile) . '/runtime.php');

    $action = strtolower(trim((string)($payload['action'] ?? 'snapshot')));
    $operation = null;

    if ($action === 'update_flags') {
        $reason = clean_string($payload['reason'] ?? '', 800);
        if ($reason === '') throw new InvalidArgumentException('Укажите причину изменения feature flags.');
        $flags = is_array($payload['flags'] ?? null) ? $payload['flags'] : [];
        $operation = $flagEditor->update($flags);
        if ($operation['changed']) {
            $system->recordAudit('feature_flags_updated', $actorRef, $reason, $operation['before'], $operation['after']);
        }
    } elseif ($action === 'acknowledge_readiness') {
        $operation = $system->acknowledgeReadiness(
            $actorRef,
            clean_string($payload['reason'] ?? '', 800)
        );
    } elseif ($action === 'accept_staging') {
        $checklist = is_array($payload['checklist'] ?? null) ? $payload['checklist'] : [];
        $operation = $system->acceptStaging(
            clean_string($payload['staging_sha'] ?? '', 40),
            $checklist,
            clean_string($payload['notes'] ?? '', 2000),
            $actorRef
        );
    } elseif ($action === 'start_staging_rehearsal') {
        $operation = $system->startStagingRehearsal(
            $environment,
            $actorRef,
            clean_string($payload['reason'] ?? '', 800)
        );
    } elseif ($action === 'stop_staging_rehearsal') {
        $operation = $system->stopStagingRehearsal(
            $environment,
            $actorRef,
            clean_string($payload['reason'] ?? '', 800)
        );
    } elseif ($action === 'activate_official_competition') {
        $activation = $system->activateOfficialCompetition(
            $environment,
            $actorRef,
            clean_string($payload['reason'] ?? '', 800)
        );
        $announcement = null;

        if (($activation['announcement_required'] ?? false) === true) {
            $storage = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
            $notificationService = new AdminNotificationEventService();
            $announcement = $storage->transaction(
                static function (array &$data) use ($notificationService, $actorRef): array {
                    return $notificationService->createEvent(
                        $data,
                        [
                            'request_id'=>'official-rating-seasons-first-activation-v1',
                            'source_type'=>'system',
                            'audience_type'=>'all',
                            'title'=>'Официальные рейтинговые сезоны запущены',
                            'text'=>'Поздравляем! В MINI GAMES WORLD запущены официальные рейтинговые сезоны. Теперь результаты по играм участвуют в официальных таблицах, сезонных местах и наградах.',
                            'deep_link'=>'',
                        ],
                        $actorRef
                    );
                }
            );
            $eventId = trim((string)($announcement['event_id'] ?? ''));
            if ($eventId === '') throw new RuntimeException('Canonical activation announcement did not return event id.');
            $system->markActivationAnnouncement($eventId, $actorRef);
        }

        $operation = [
            'activation'=>$activation,
            'announcement'=>$announcement,
        ];
    } elseif ($action !== 'snapshot') {
        json_response(['ok'=>false,'error'=>'Неизвестное действие System Admin.'], 422);
    }

    json_response([
        'ok'=>true,
        'generated_at'=>gmdate(DATE_ATOM),
        'operation'=>$operation,
        'runtime'=>(new FeatureFlagService($config))->publicStatus(),
        'persisted_flags'=>$flagEditor->persistedSnapshot(),
        'system'=>$system->snapshot($environment),
    ]);
} catch (AdminWebAuthException $error) {
    json_response(['ok'=>false,'error'=>$error->publicMessage()], $error->httpStatus());
} catch (AdminNotificationEventException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage(),'reason'=>$error->reason], 422);
} catch (InvalidArgumentException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage()], 422);
} catch (RuntimeException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage()], 409);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld system admin] ' . $error->getMessage());
    json_response(['ok'=>false,'error'=>'Не удалось выполнить операцию System Admin.'], 500);
}
