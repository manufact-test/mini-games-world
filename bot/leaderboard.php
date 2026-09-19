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
        json_response(['ok'=>false,'error'=>'Рейтинг недоступен для этой сессии.'], 401);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($configRef);
    $router = new RuntimeStorageRouter($configRef);
    if (!$databaseConfig->enabled()
        || ($router->enabled() && $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE)
        || ($router->enabled() && $router->routeFor('realtime') !== RuntimeStorageRouter::DRIVER_DATABASE)) {
        json_response(['ok'=>false,'error'=>'Таблица лидеров временно недоступна.'], 503);
    }

    $gameCatalog = new GameCatalogService($configRef);
    $gameType = $gameCatalog->normalizeGameType(
        clean_string($payload['game_type'] ?? 'tictactoe', 60)
    );

    $database = PdoConnectionFactory::create($databaseConfig);

    // Reuse the exact visible-rating projection owner before reading the board.
    // This keeps leaderboard data on the same normalized DB result source as
    // Profile rating and never invents a second result writer.
    (new PerGameRatingRuntimeBridge($configRef, $router, $database))
        ->snapshotForProfile($mgwId);

    $leaderboard = (new LeaderboardService($database))->snapshot(
        $gameType,
        $mgwId,
        LeaderboardService::MAX_LIMIT
    );

    json_response([
        'ok' => true,
        'leaderboard' => $leaderboard,
    ]);
} catch (InvalidArgumentException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('MGW leaderboard failed: ' . $error->getMessage());
    json_response(['ok'=>false,'error'=>'Не удалось загрузить таблицу лидеров.'], 500);
}
