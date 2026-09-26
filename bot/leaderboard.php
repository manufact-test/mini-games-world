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

    // Leaderboards are a read-heavy public surface. Catch up only already
    // normalized DB match rows here; do not run the heavier JSON->DB realtime
    // synchronization used by Profile. Realtime projection already owns match
    // publication on API success, so this keeps the board fresh without making
    // every tab switch pay a full runtime synchronization.
    //
    // Catch-up is opportunistic maintenance, not the read owner. A concurrent
    // or malformed pending projection must not turn an otherwise readable
    // leaderboard snapshot into HTTP 500. Keep retrying on later reads and log
    // the deferred projection; the canonical snapshot below still fails closed
    // if leaderboard storage itself is unavailable.
    try {
        (new PerGameRatingRuntimeBridge($configRef, $router, $database))
            ->processProjectedMatches(50);
    } catch (Throwable $catchupError) {
        error_log('MGW leaderboard rating catch-up deferred: ' . $catchupError->getMessage());
    }

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
