<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok'=>false,'error'=>'Некорректный запрос.'], 400);
    }

    $user = (new AuthService($config))->getUserFromRequest($payload);
    $mgwId = trim((string)($user['mgw_id'] ?? ''));
    $accountRef = trim((string)($user['mgw_account_ref'] ?? ''));
    if ($mgwId === '' || $accountRef === '') {
        json_response([
            'ok'=>false,
            'error'=>'Регистрация турниров требует канонической MGW account identity.',
        ], 503);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        json_response(['ok'=>false,'error'=>'Турниры временно недоступны.'], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $service = new TournamentRegistrationService($database, new LedgerWriteService($database));
    $action = strtolower(trim((string)($payload['action'] ?? 'status')));

    if ($action === 'status') {
        $snapshot = $service->snapshot($mgwId, $accountRef);
    } elseif ($action === 'register') {
        $snapshot = $service->register($mgwId, $accountRef);
    } elseif ($action === 'leave') {
        $snapshot = $service->leave($mgwId, $accountRef);
    } else {
        json_response(['ok'=>false,'error'=>'Неизвестное действие турнира.'], 422);
    }

    json_response([
        'ok'=>true,
        'generated_at'=>gmdate(DATE_ATOM),
        'snapshot'=>$snapshot,
    ]);
} catch (InvalidArgumentException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage()], 422);
} catch (RuntimeException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage()], 409);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld tournaments] ' . $error->getMessage());
    json_response(['ok'=>false,'error'=>'Не удалось выполнить операцию турнира.'], 500);
}
