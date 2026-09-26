<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/AdminWebAuth.php';
require_once __DIR__ . '/services/MatchmakingQueue.php';
require_once __DIR__ . '/analytics/ProductEconomyAnalyticsService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'Метод запроса не поддерживается.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload) || (string)($payload['action'] ?? '') !== 'snapshot') {
        json_response(['ok'=>false,'error'=>'Некорректный запрос аналитики.'], 400);
    }

    AdminWebAuth::authorize($config, (string)($payload['initData'] ?? ''));

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        json_response(['ok'=>false,'error'=>'Аналитика недоступна: база данных отключена.'], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $router = new RuntimeStorageRouter($config);
    $storage = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));

    $runtime = $storage->readOnly(static function (array $data) use ($config, $router, $database): array {
        $telemetry = (new MatchmakingQueue())->telemetry($data);
        try {
            $reconciliation = (new RuntimeEconomyRepository($config, $router, $database))
                ->auditParity($data);
        } catch (Throwable $error) {
            error_log('[MiniGamesWorld analytics reconciliation] ' . $error->getMessage());
            $reconciliation = [
                'ok'=>false,
                'read_only'=>true,
                'phase'=>'unavailable',
                'planned_delta_count'=>0,
                'integrity_failure_count'=>0,
                'active_reservation_count'=>0,
                'ledger_entry_count'=>0,
                'blockers'=>['Проверка согласованности экономики временно недоступна.'],
            ];
        }

        return [
            'matchmaking'=>$telemetry,
            'reconciliation'=>$reconciliation,
        ];
    });

    $analytics = (new ProductEconomyAnalyticsService($database))->snapshot(
        is_array($runtime['matchmaking'] ?? null) ? $runtime['matchmaking'] : [],
        is_array($runtime['reconciliation'] ?? null) ? $runtime['reconciliation'] : []
    );

    json_response([
        'ok'=>true,
        'environment'=>(string)($config['environment'] ?? 'production'),
        'analytics'=>$analytics,
    ]);
} catch (AdminWebAuthException $error) {
    json_response(['ok'=>false,'error'=>$error->publicMessage()], $error->httpStatus());
} catch (InvalidArgumentException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld analytics admin] ' . $error->getMessage());
    json_response(['ok'=>false,'error'=>'Не удалось загрузить продуктовую аналитику.'], 500);
}
