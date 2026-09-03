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
    $inventory = new ProductInventoryService($database);
    $service = new GameReactionService($config, $database);
    $action = strtolower(trim((string)($payload['action'] ?? 'send')));

    if ($action === 'equip') {
        $itemId = strtolower(trim((string)($payload['item_id'] ?? '')));
        $snapshot = $inventory->snapshot($mgwId);
        $allowed = false;
        foreach ((array)($snapshot['catalog'] ?? []) as $item) {
            if (!is_array($item) || (string)($item['item_id'] ?? '') !== $itemId) continue;
            $allowed = (string)($item['item_type'] ?? '') === 'profile'
                && (string)($item['item_family'] ?? '') === 'reaction'
                && (string)($item['equip_slot'] ?? '') === GameReactionService::SLOT
                && (string)($item['catalog_status'] ?? '') === 'active'
                && !empty($item['owned']);
            break;
        }
        if (!$allowed) throw new GameReactionException(404, 'Набор реакций недоступен.');
        $inventory->equip($mgwId, $itemId);
        json_response(['ok' => true, 'inventory' => $inventory->snapshot($mgwId)]);
    }

    if ($action === 'unequip') {
        $inventory->unequip($mgwId, GameReactionService::SLOT);
        json_response(['ok' => true, 'inventory' => $inventory->snapshot($mgwId)]);
    }

    if ($action !== 'send') throw new GameReactionException(422, 'Неизвестное действие реакций.');

    $gameId = clean_string($payload['gameId'] ?? '', 80);
    $code = clean_string($payload['reaction'] ?? '', 32);
    $event = $service->send($mgwId, $providerUserId, $gameId, $code);
    json_response(['ok' => true, 'reaction' => $event]);
} catch (GameReactionException $error) {
    json_response(['ok' => false, 'error' => $error->getMessage()], $error->status);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld reaction] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => 'Не удалось выполнить действие с реакцией.'], 500);
}
