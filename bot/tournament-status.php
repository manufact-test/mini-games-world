<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/app/runtime/localization/LocalizationCatalog.php';

function mgw_tournament_status_copy(string $key, string $emergencyFallback): string
{
    try {
        static $catalog = null;
        if (!$catalog instanceof LocalizationCatalog) {
            $catalog = new LocalizationCatalog(dirname(__DIR__) . '/app/locales');
        }
        return $catalog->translate($key);
    } catch (Throwable $error) {
        error_log('[MiniGamesWorld tournament status localization] ' . $error->getMessage());
        return $emergencyFallback;
    }
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok'=>false,'error'=>mgw_tournament_status_copy('server.tournament_status.invalid_request', 'Invalid request.')], 400);
    }

    $user = (new AuthService($config))->getUserFromRequest($payload);
    $mgwId = trim((string)($user['mgw_id'] ?? ''));
    $accountRef = trim((string)($user['mgw_account_ref'] ?? ''));
    if ($mgwId === '' || $accountRef === '') {
        json_response(['ok'=>false,'error'=>mgw_tournament_status_copy('server.tournament_status.account_unavailable', 'Game account could not be verified.')], 409);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        json_response(['ok'=>false,'error'=>mgw_tournament_status_copy('server.tournament_status.unavailable', 'Tournaments are temporarily unavailable.')], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $service = new TournamentRegistrationService(
        $database,
        new LedgerWriteService($database)
    );

    $snapshot = $service->snapshot($mgwId, $accountRef);
    $snapshot['last_cancellation'] = (new TournamentCancellationService(
        $database,
        new LedgerWriteService($database)
    ))->lastCancellationForParticipant($mgwId);

    json_response([
        'ok'=>true,
        'generated_at'=>gmdate(DATE_ATOM),
        'snapshot'=>$snapshot,
    ]);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld tournament status] ' . $error->getMessage());

    $response = ['ok'=>false,'error'=>mgw_tournament_status_copy('arena.errors.load', 'Tournament could not be loaded.')];
    if (strtolower(trim((string)($config['environment'] ?? ''))) === 'staging'
        && isset($user)
        && is_array($user)
        && !empty($user['is_staging_test_user'])) {
        $response['debug_error'] = substr($error->getMessage(), 0, 1200);
        $response['debug_exception'] = get_class($error);
    }

    json_response($response, 500);
}
