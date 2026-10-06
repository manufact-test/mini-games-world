<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/localization/ServerLocalization.php';
require_once __DIR__ . '/tournaments/TournamentHallService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok'=>false,'error'=>ServerLocalization::copy('server.tournament_status.invalid_request', 'Invalid request.')], 400);
    }

    $action = strtolower(trim((string)($payload['action'] ?? 'status')));
    if (!in_array($action, ['status','enter','heartbeat'], true)) {
        json_response(['ok'=>false,'error'=>ServerLocalization::copy('server.tournament_runtime.hall.invalid_action', 'Invalid Tournament Hall action.')], 400);
    }

    $user = (new AuthService($config))->getUserFromRequest($payload);
    $mgwId = trim((string)($user['mgw_id'] ?? ''));
    $accountRef = trim((string)($user['mgw_account_ref'] ?? ''));
    $legacyUserId = trim((string)($user['id'] ?? ''));
    if ($mgwId === '' || $accountRef === '' || $legacyUserId === '') {
        json_response(['ok'=>false,'error'=>ServerLocalization::copy('server.tournament_runtime.hall.account_unavailable', 'Tournament participant could not be verified.')], 409);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        json_response(['ok'=>false,'error'=>ServerLocalization::copy('server.tournament_runtime.hall.unavailable', 'Tournament Hall is temporarily unavailable.')], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $presence = new PresenceService();
    $hall = new TournamentHallService(
        $database,
        static fn(string $legacyId): array => $presence->gameplaySnapshot($legacyId)
    );

    $snapshot = match ($action) {
        'enter' => $hall->enter($mgwId, $accountRef, $legacyUserId),
        'heartbeat' => $hall->heartbeat($mgwId, $accountRef, $legacyUserId),
        default => $hall->status($mgwId, $accountRef, $legacyUserId),
    };

    json_response([
        'ok'=>true,
        'generated_at'=>gmdate(DATE_ATOM),
        'snapshot'=>$snapshot,
    ]);
} catch (Throwable $error) {
    $message = trim($error->getMessage());
    $public = [
        ServerLocalization::copy('server.tournament_runtime.hall.registered_only', 'Tournament Hall is available only to registered participants.'),
        ServerLocalization::copy('server.tournament_runtime.hall.after_schedule', 'Tournament Hall opens after the tournament is scheduled.'),
        ServerLocalization::copy('server.tournament_runtime.hall.opens_15', 'Tournament Hall opens 15 minutes before the start.'),
        ServerLocalization::copy('server.tournament_runtime.hall.enter_first', 'Enter Tournament Hall first.'),
        ServerLocalization::copy('server.tournament_runtime.hall.date_not_set', 'Tournament start time is not scheduled.'),
    ];
    if (in_array($message, $public, true)) {
        json_response(['ok'=>false,'error'=>$message], 409);
    }

    error_log('[MiniGamesWorld tournament hall] ' . $message);
    $response = ['ok'=>false,'error'=>ServerLocalization::copy('server.tournament_runtime.hall.load_error', 'Tournament Hall could not be loaded.')];
    if (strtolower(trim((string)($config['environment'] ?? ''))) === 'staging'
        && isset($user)
        && is_array($user)
        && !empty($user['is_staging_test_user'])) {
        $response['debug_error'] = substr($message, 0, 1200);
        $response['debug_exception'] = get_class($error);
    }
    json_response($response, 500);
}
