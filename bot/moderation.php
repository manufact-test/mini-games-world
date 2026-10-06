<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/accounts/MgwIdGenerator.php';
require_once __DIR__ . '/moderation/ModerationService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok'=>false,'error'=>ServerLocalization::copy('server.moderation.endpoint.invalid_request', 'Invalid request.')], 400);
    }

    $configRef = $config;
    $authenticatedUser = (new AuthService($configRef))->getUserFromRequest($payload);
    $mgwId = strtoupper(trim((string)($authenticatedUser['mgw_id'] ?? '')));
    if (!MgwIdGenerator::isValid($mgwId)) {
        json_response(['ok'=>false,'error'=>ServerLocalization::copy('server.moderation.endpoint.profile_unavailable', 'The MGW profile is unavailable for this session.')], 401);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($configRef);
    $router = new RuntimeStorageRouter($configRef);
    if (!$databaseConfig->enabled()
        || ($router->enabled() && $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE)) {
        json_response(['ok'=>false,'error'=>ServerLocalization::copy('server.moderation.endpoint.unavailable', 'Moderation is temporarily unavailable.')], 503);
    }

    $service = new ModerationService(PdoConnectionFactory::create($databaseConfig));
    $action = strtolower(trim((string)($payload['action'] ?? 'snapshot')));

    if ($action === 'appeal') {
        $appeal = $service->submitAppeal(
            $mgwId,
            (string)($payload['action_id'] ?? ''),
            (string)($payload['message'] ?? '')
        );
        json_response([
            'ok'=>true,
            'appeal'=>$appeal,
            'moderation'=>$service->userSnapshot($mgwId),
        ]);
    }

    if ($action !== 'snapshot') {
        json_response(['ok'=>false,'error'=>ServerLocalization::copy('server.moderation.endpoint.invalid_action', 'Invalid moderation action.')], 400);
    }

    json_response([
        'ok'=>true,
        'moderation'=>$service->userSnapshot($mgwId),
    ]);
} catch (ModerationException $error) {
    $status = match ($error->reason) {
        'action_not_found','appeal_not_found' => 404,
        'appeal_forbidden' => 403,
        'appeal_exists' => 409,
        default => 422,
    };
    json_response(['ok'=>false,'code'=>$error->reason,'error'=>mgw_public_api_error($error->getMessage())], $status);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld moderation] ' . $error->getMessage());
    json_response(['ok'=>false,'error'=>ServerLocalization::copy('server.moderation.endpoint.load_failed', 'Moderation data could not be loaded.')], 500);
}
