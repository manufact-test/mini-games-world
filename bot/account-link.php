<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/app/runtime/localization/LocalizationCatalog.php';

function mgw_account_link_copy(string $key, string $emergencyFallback): string
{
    try {
        static $catalog = null;
        if (!$catalog instanceof LocalizationCatalog) {
            $catalog = new LocalizationCatalog(dirname(__DIR__) . '/app/locales');
        }
        return $catalog->translate($key);
    } catch (Throwable $error) {
        error_log('[MiniGamesWorld account link localization] ' . $error->getMessage());
        return $emergencyFallback;
    }
}
require_once __DIR__ . '/accounts/AccountLinkService.php';
require_once __DIR__ . '/services/PresenceService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>mgw_account_link_copy('server.account_link.method_not_allowed', 'Request method is not supported.')], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok'=>false,'error'=>mgw_account_link_copy('server.account_link.invalid_request', 'Invalid request.')], 400);
    }

    $authenticated = (new AuthService($config))->getUserFromRequest($payload);
    if ((string)($authenticated['mgw_identity_provider'] ?? '') !== 'android_device') {
        json_response([
            'ok'=>false,
            'code'=>'android_auth_required',
            'error'=>mgw_account_link_copy('server.account_link.android_required', 'Account linking must be started from the Android app.'),
        ], 403);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    $router = new RuntimeStorageRouter($config);
    if (!$databaseConfig->enabled()
        || !$router->enabled()
        || $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
        || $router->routeFor('economy') !== RuntimeStorageRouter::DRIVER_DATABASE) {
        json_response(['ok'=>false,'error'=>mgw_account_link_copy('server.account_link.unavailable', 'Account linking is temporarily unavailable.')], 503);
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

    json_response(['ok'=>false,'error'=>mgw_account_link_copy('server.account_link.invalid_action', 'Invalid account-link action.')], 400);
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
        'error'=>mgw_account_link_copy('server.account_link.failed', 'Account linking could not be processed. Please try again.'),
    ], 500);
}
