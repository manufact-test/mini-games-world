<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/AdminWebAuth.php';

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
        json_response(['ok'=>false,'error'=>'Rating Admin недоступен: DB отключена.'], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $service = new RatingAdminService($database);
    $action = strtolower(trim((string)($payload['action'] ?? 'snapshot')));
    $actorRef = 'telegram:' . $telegramId;

    $result = null;
    if ($action === 'snapshot') {
        $result = ['snapshot'=>$service->snapshot()];
    } elseif ($action === 'exclude') {
        $result = [
            'exclusion'=>$service->setExclusion(
                clean_string($payload['season_id'] ?? '', 64),
                clean_string($payload['mgw_id'] ?? '', 64),
                clean_string($payload['reason_code'] ?? '', 64),
                clean_string($payload['review_note'] ?? '', 500),
                $actorRef
            ),
            'snapshot'=>$service->snapshot(),
        ];
    } elseif ($action === 'restore') {
        $result = [
            'exclusion'=>$service->revokeExclusion(
                clean_string($payload['season_id'] ?? '', 64),
                clean_string($payload['mgw_id'] ?? '', 64),
                clean_string($payload['review_note'] ?? '', 500),
                $actorRef
            ),
            'snapshot'=>$service->snapshot(),
        ];
    } elseif ($action === 'recalculate') {
        $result = [
            'recalculation'=>$service->recalculateSeason(
                clean_string($payload['season_id'] ?? '', 64),
                clean_string($payload['reason'] ?? '', 500),
                $actorRef
            ),
            'snapshot'=>$service->snapshot(),
        ];
    } elseif ($action === 'rehearsal') {
        $result = [
            'rehearsal'=>$service->seasonCloseRehearsal(
                clean_string($payload['season_id'] ?? '', 64)
            ),
            'snapshot'=>$service->snapshot(),
        ];
    } else {
        json_response(['ok'=>false,'error'=>'Неизвестное действие Rating Admin.'], 422);
    }

    json_response([
        'ok'=>true,
        'generated_at'=>gmdate(DATE_ATOM),
    ] + $result);
} catch (AdminWebAuthException $error) {
    json_response(['ok'=>false,'error'=>$error->publicMessage()], $error->httpStatus());
} catch (InvalidArgumentException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage()], 422);
} catch (RuntimeException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage()], 409);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld rating admin] ' . $error->getMessage());
    json_response(['ok'=>false,'error'=>'Не удалось выполнить операцию Rating Admin.'], 500);
}
