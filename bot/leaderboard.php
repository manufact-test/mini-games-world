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

    // Leaderboards are a read-heavy public surface. Catch up only already
    // normalized DB match rows here; do not run the heavier JSON->DB realtime
    // synchronization used by Profile. Realtime projection already owns match
    // publication on API success, so this keeps the board fresh without making
    // every tab switch pay a full runtime synchronization.
    //
    // Catch-up is opportunistic maintenance, not the read owner. It deliberately
    // gets its own DB connection. If concurrent post-match maintenance fails or
    // leaves its connection unusable, the public leaderboard snapshot below
    // still starts from a fresh read connection rather than inheriting that
    // connection state.
    try {
        $maintenanceDatabase = PdoConnectionFactory::create($databaseConfig);
        (new PerGameRatingRuntimeBridge($configRef, $router, $maintenanceDatabase))
            ->processProjectedMatches(50);
    } catch (Throwable $catchupError) {
        error_log('MGW leaderboard rating catch-up deferred: ' . $catchupError->getMessage());
    }

    $readDatabase = PdoConnectionFactory::create($databaseConfig);
    $leaderboard = (new LeaderboardService($readDatabase))->snapshot(
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
