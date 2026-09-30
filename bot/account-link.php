<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/accounts/AccountLinkService.php';
require_once __DIR__ . '/services/PresenceService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'Метод запроса не поддерживается.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok'=>false,'error'=>'Некорректный запрос.'], 400);
    }

    $authenticated = (new AuthService($config))->getUserFromRequest($payload);
    if ((string)($authenticated['mgw_identity_provider'] ?? '') !== 'android_device') {
        json_response([
            'ok'=>false,
            'code'=>'android_auth_required',
            'error'=>'Привязку аккаунта нужно начать из Android-приложения.',
        ], 403);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    $router = new RuntimeStorageRouter($config);
    if (!$databaseConfig->enabled()
        || !$router->enabled()
        || $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
        || $router->routeFor('economy') !== RuntimeStorageRouter::DRIVER_DATABASE) {
        json_response(['ok'=>false,'error'=>'Привязка аккаунта временно недоступна.'], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $storage = new JsonStorageAdapter((string)$config['data_dir']);
    $service = new AccountLinkService($config, $database, $storage, new PresenceService());
    $action = strtolower(trim((string)($payload['action'] ?? 'status')));

    if ($action === 'create') {
        json_response([
            'ok'=>true,
            'link'=>$service->createChallenge($authenticated),
        ]);
    }

    $challengeId = (string)($payload['challenge_id'] ?? '');
    if ($action === 'status') {
        json_response([
            'ok'=>true,
            'link'=>$service->statusForAndroid($challengeId, $authenticated),
        ]);
    }

    if ($action === 'finalize') {
        json_response([
            'ok'=>true,
            'link'=>$service->finalizeForAndroid($challengeId, $authenticated),
        ]);
    }

    json_response(['ok'=>false,'error'=>'Некорректное действие привязки аккаунта.'], 400);
} catch (AccountLinkException $error) {
    if ($error->reason === 'rate_limited') {
        header('Retry-After: 3600');
    }
    json_response([
        'ok'=>false,
        'code'=>$error->reason,
        'error'=>mgw_public_api_error($error->getMessage()),
    ], $error->httpStatus);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld account link] ' . $error::class . ': ' . $error->getMessage());
    json_response([
        'ok'=>false,
        'error'=>'Не удалось обработать привязку аккаунта. Попробуйте ещё раз.',
    ], 500);
}
