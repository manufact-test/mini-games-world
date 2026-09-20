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
        json_response(['ok'=>false,'error'=>'Не удалось подтвердить игровой аккаунт.'], 409);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        json_response(['ok'=>false,'error'=>'Турниры временно недоступны.'], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $service = new TournamentRegistrationService(
        $database,
        new LedgerWriteService($database)
    );

    json_response([
        'ok'=>true,
        'generated_at'=>gmdate(DATE_ATOM),
        'snapshot'=>$service->snapshot($mgwId, $accountRef),
    ]);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld tournament status] ' . $error->getMessage());
    json_response(['ok'=>false,'error'=>'Не удалось загрузить турнир.'], 500);
}
