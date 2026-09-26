<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/AdminWebAuth.php';
require_once __DIR__ . '/operations/AdminOperationsService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'Метод запроса не поддерживается.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok'=>false,'error'=>'Некорректный запрос.'], 400);
    }

    $admin = AdminWebAuth::authorize($config, (string)($payload['initData'] ?? ''));
    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        json_response(['ok'=>false,'error'=>'Операционные данные недоступны: база данных отключена.'], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $service = new AdminOperationsService($database);
    $actorRef = 'telegram:' . trim((string)($admin['id'] ?? 'unknown'));
    $action = strtolower(trim((string)($payload['action'] ?? 'snapshot')));
    $result = null;

    if ($action === 'create_task') {
        $result = $service->createTask(
            is_array($payload['task'] ?? null) ? $payload['task'] : [],
            $actorRef
        );
    } elseif ($action === 'update_task') {
        $result = $service->updateTask(
            (string)($payload['task_id'] ?? ''),
            is_array($payload['changes'] ?? null) ? $payload['changes'] : [],
            $actorRef
        );
    } elseif ($action === 'create_plan') {
        $result = $service->createPlan(
            is_array($payload['plan'] ?? null) ? $payload['plan'] : [],
            $actorRef
        );
    } elseif ($action === 'update_plan') {
        $result = $service->updatePlan(
            (string)($payload['plan_id'] ?? ''),
            is_array($payload['changes'] ?? null) ? $payload['changes'] : [],
            $actorRef
        );
    } elseif ($action === 'create_release') {
        $result = $service->createRelease(
            is_array($payload['release'] ?? null) ? $payload['release'] : [],
            $actorRef
        );
    } elseif ($action === 'update_release') {
        $result = $service->updateRelease(
            (string)($payload['release_id'] ?? ''),
            is_array($payload['changes'] ?? null) ? $payload['changes'] : [],
            $actorRef
        );
    } elseif ($action === 'update_season_readiness') {
        $result = $service->updateSeasonReadiness(
            (string)($payload['target_season_id'] ?? ''),
            is_array($payload['states'] ?? null) ? $payload['states'] : [],
            $actorRef
        );
    } elseif ($action !== 'snapshot') {
        json_response(['ok'=>false,'error'=>'Некорректное действие раздела задач и релизов.'], 400);
    }

    json_response([
        'ok'=>true,
        'environment'=>(string)($config['environment'] ?? 'production'),
        'runtime_build'=>FeatureFlagService::BUILD,
        'result'=>$result,
        'operations'=>$service->snapshot(),
        'task_statuses'=>AdminOperationsService::TASK_STATUSES,
        'task_recurrences'=>AdminOperationsService::TASK_RECURRENCES,
        'task_categories'=>AdminOperationsService::TASK_CATEGORIES,
        'plan_statuses'=>AdminOperationsService::PLAN_STATUSES,
        'plan_categories'=>AdminOperationsService::PLAN_CATEGORIES,
        'release_environments'=>AdminOperationsService::RELEASE_ENVIRONMENTS,
    ]);
} catch (AdminWebAuthException $error) {
    json_response(['ok'=>false,'error'=>$error->publicMessage()], $error->httpStatus());
} catch (InvalidArgumentException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld admin operations] ' . $error->getMessage());
    json_response(['ok'=>false,'error'=>'Не удалось обработать раздел задач и релизов.'], 500);
}
