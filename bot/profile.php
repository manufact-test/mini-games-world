<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/accounts/MgwProfileService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok' => false, 'error' => 'Некорректный запрос.'], 400);
    }

    // AuthService remains the only provider authentication owner. It resolves
    // the provider identity to an internal MGW id before the profile layer runs.
    $authenticatedUser = (new AuthService($config))->getUserFromRequest($payload);
    $mgwId = trim((string)($authenticatedUser['mgw_id'] ?? ''));
    if (!MgwIdGenerator::isValid($mgwId)) {
        json_response(['ok' => false, 'error' => 'Профиль MGW недоступен для этой сессии.'], 401);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    $router = new RuntimeStorageRouter($config);
    if (!$databaseConfig->enabled()
        || ($router->enabled() && $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE)) {
        json_response(['ok' => false, 'error' => 'Профиль MGW временно недоступен.'], 503);
    }

    $profile = (new MgwProfileService(
        PdoConnectionFactory::create($databaseConfig)
    ))->publicProfile($mgwId);

    $provider = strtolower(trim((string)($authenticatedUser['mgw_identity_provider'] ?? '')));
    json_response([
        'ok' => true,
        'profile' => $profile,
        'auth' => [
            'provider' => $provider !== '' ? $provider : null,
            'provider_neutral' => true,
        ],
    ]);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld MGW profile] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => 'Не удалось загрузить профиль MGW.'], 500);
}
