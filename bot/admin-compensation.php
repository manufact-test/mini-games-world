<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/AdminWebAuth.php';
require_once __DIR__ . '/economy/CompensationService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok'=>false,'error'=>'Некорректный запрос.'], 400);
    }

    $action = strtolower(trim((string)($payload['action'] ?? 'snapshot')));
    if (!in_array($action, ['snapshot','lookup','request','confirm'], true)) {
        json_response(['ok'=>false,'error'=>'Некорректное действие компенсации.'], 400);
    }

    $telegramUser = AdminWebAuth::authorize($config, (string)($payload['initData'] ?? ''));
    $telegramId = trim((string)($telegramUser['id'] ?? ''));
    if ($telegramId === '') {
        throw new RuntimeException('Authorized Telegram admin identity is unavailable.');
    }
    $actorRef = 'telegram:' . $telegramId;

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('Canonical database is unavailable.');
    }
    $router = new RuntimeStorageRouter($config);
    if (!$router->enabled()
        || $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
        || $router->routeFor('economy') !== RuntimeStorageRouter::DRIVER_DATABASE) {
        throw new RuntimeException('Canonical account/economy routing is unavailable.');
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $runtimeStorage = StorageFactory::create($config);
    $service = new CompensationService(
        $database,
        new LedgerWriteService($database),
        $runtimeStorage
    );

    $response = [
        'ok' => true,
        'generated_at' => gmdate(DATE_ATOM),
        'limits' => $service->limits(),
    ];

    if ($action === 'lookup') {
        $response['operation'] = $service->lookupOperation((string)($payload['operation_ref'] ?? ''));
    } elseif ($action === 'request') {
        $response['compensation'] = $service->requestCompensation(
            (string)($payload['operation_ref'] ?? ''),
            $payload['amount'] ?? null,
            (string)($payload['reason'] ?? ''),
            $actorRef,
            (string)($payload['request_token'] ?? '')
        );
        $response['history'] = $service->history(25);
    } elseif ($action === 'confirm') {
        $response['compensation'] = $service->confirm(
            (string)($payload['compensation_id'] ?? ''),
            $actorRef
        );
        $response['history'] = $service->history(25);
    } else {
        $response['history'] = $service->history(25);
    }

    json_response($response);
} catch (AdminWebAuthException $error) {
    json_response(['ok'=>false,'error'=>$error->publicMessage()], $error->httpStatus());
} catch (InvalidArgumentException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld admin compensation] ' . $error->getMessage());
    json_response(['ok'=>false,'error'=>'Не удалось выполнить операцию компенсации.'], 500);
}
