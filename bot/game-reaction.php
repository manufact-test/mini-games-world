<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/GameReactionService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) json_response(['ok' => false, 'error' => 'Некорректный запрос.'], 400);

    $authenticatedUser = (new AuthService($config))->getUserFromRequest($payload);
    $providerUserId = trim((string)($authenticatedUser['id'] ?? ''));
    $mgwId = trim((string)($authenticatedUser['mgw_id'] ?? ''));
    if ($providerUserId === '' || !MgwIdGenerator::isValid($mgwId)) {
        json_response(['ok' => false, 'error' => 'Профиль MGW недоступен для этой сессии.'], 401);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        json_response(['ok' => false, 'error' => 'Реакции временно недоступны.'], 503);
    }
    $database = PdoConnectionFactory::create($databaseConfig);
    $service = new GameReactionService($config, $database);
    $gameId = clean_string($payload['gameId'] ?? '', 80);
    $code = clean_string($payload['reaction'] ?? '', 32);
    $event = $service->send($mgwId, $providerUserId, $gameId, $code);

    json_response(['ok' => true, 'reaction' => $event]);
} catch (GameReactionException $error) {
    json_response(['ok' => false, 'error' => $error->getMessage()], $error->status);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld reaction] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => 'Не удалось отправить реакцию.'], 500);
}
