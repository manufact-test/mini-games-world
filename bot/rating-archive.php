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

    $configRef = $config;
    $authenticatedUser = (new AuthService($configRef))->getUserFromRequest($payload);
    $mgwId = trim((string)($authenticatedUser['mgw_id'] ?? ''));
    if (!MgwIdGenerator::isValid($mgwId)) {
        json_response(['ok'=>false,'error'=>'Архив рейтинга недоступен для этой сессии.'], 401);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($configRef);
    $router = new RuntimeStorageRouter($configRef);
    if (!$databaseConfig->enabled()
        || ($router->enabled() && $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE)
        || ($router->enabled() && $router->routeFor('realtime') !== RuntimeStorageRouter::DRIVER_DATABASE)) {
        json_response(['ok'=>false,'error'=>'Архив рейтинга временно недоступен.'], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $service = new RatingArchiveService($database);
    $mode = strtolower(trim(clean_string($payload['mode'] ?? 'overview', 24)));

    if ($mode === 'overview') {
        json_response([
            'ok'=>true,
            'archive'=>$service->publicOverview(),
        ]);
    }

    if ($mode === 'season') {
        $seasonId = clean_string($payload['season_id'] ?? '', 64);
        $gameType = (new GameCatalogService($configRef))->normalizeGameType(
            clean_string($payload['game_type'] ?? 'tictactoe', 60)
        );
        json_response([
            'ok'=>true,
            'archive'=>$service->seasonArchive($seasonId, $gameType),
        ]);
    }

    json_response(['ok'=>false,'error'=>'Неизвестный режим архива рейтинга.'], 422);
} catch (InvalidArgumentException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('MGW rating archive failed: ' . $error->getMessage());

    $response = ['ok'=>false,'error'=>'Не удалось загрузить архив рейтинга.'];
    if (strtolower(trim((string)($config['environment'] ?? ''))) === 'staging'
        && isset($authenticatedUser)
        && is_array($authenticatedUser)
        && !empty($authenticatedUser['is_staging_test_user'])) {
        $response['debug_error'] = substr($error->getMessage(), 0, 1200);
        $response['debug_exception'] = get_class($error);
    }

    json_response($response, 500);
}
