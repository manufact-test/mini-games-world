<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/social/FriendGraphService.php';
require_once __DIR__ . '/social/SocialPlayerProfileReader.php';

function mgw_friend_error_status(string $reason): int
{
    return match ($reason) {
        'self_relation' => 422,
        'user_unavailable' => 404,
        'request_unavailable', 'incoming_request_exists', 'request_not_incoming', 'request_not_outgoing' => 409,
        default => 409,
    };
}

function mgw_friend_error_message(string $reason): string
{
    return match ($reason) {
        'self_relation' => 'Нельзя выполнить это действие со своим профилем.',
        'user_unavailable' => 'Игрок MGW не найден.',
        'incoming_request_exists' => 'У вас уже есть входящая заявка от этого игрока.',
        'request_not_incoming' => 'Входящая заявка уже недоступна.',
        'request_not_outgoing' => 'Исходящая заявка уже недоступна.',
        default => 'Это действие сейчас недоступно.',
    };
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) json_response(['ok' => false, 'error' => 'Некорректный запрос.'], 400);

    $configRef = $config;
    $authenticatedUser = (new AuthService($configRef))->getUserFromRequest($payload);
    $actorMgwId = trim((string)($authenticatedUser['mgw_id'] ?? ''));
    if (!MgwIdGenerator::isValid($actorMgwId)) {
        json_response(['ok' => false, 'error' => 'Профиль MGW недоступен для этой сессии.'], 401);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($configRef);
    $router = new RuntimeStorageRouter($configRef);
    if (!$databaseConfig->enabled()
        || ($router->enabled() && $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE)) {
        json_response(['ok' => false, 'error' => 'Друзья MGW временно недоступны.'], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $service = new FriendGraphService($database);
    $profileReader = new SocialPlayerProfileReader($database);
    $action = strtolower(trim((string)($payload['action'] ?? 'snapshot')));
    $target = trim((string)($payload['target_mgw_id'] ?? ''));

    try {
        $result = match ($action) {
            'snapshot' => $service->snapshot($actorMgwId),
            'lookup' => $service->lookupExact($actorMgwId, (string)($payload['query'] ?? '')),
            'player_profile' => (function () use ($service, $profileReader, $actorMgwId, $target): array {
                if ($service->lookupExact($actorMgwId, $target) === null) {
                    throw new FriendGraphException('user_unavailable', 'MGW account is unavailable.');
                }
                return $profileReader->read($target);
            })(),
            'request' => $service->requestFriend($actorMgwId, $target),
            'accept' => $service->acceptFriendRequest($actorMgwId, $target),
            'decline' => $service->declineFriendRequest($actorMgwId, $target),
            'cancel' => $service->cancelFriendRequest($actorMgwId, $target),
            'remove' => $service->removeFriend($actorMgwId, $target),
            'block' => $service->block($actorMgwId, $target),
            'unblock' => $service->unblock($actorMgwId, $target),
            default => throw new InvalidArgumentException('unknown_action'),
        };
    } catch (FriendGraphException $error) {
        json_response([
            'ok' => false,
            'code' => $error->reason,
            'error' => mgw_friend_error_message($error->reason),
        ], mgw_friend_error_status($error->reason));
    } catch (InvalidArgumentException $error) {
        json_response(['ok' => false, 'code' => 'invalid_request', 'error' => 'Некорректный запрос.'], 422);
    }

    json_response(['ok' => true, 'action' => $action, 'result' => $result]);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld Friends] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => 'Не удалось выполнить действие с друзьями MGW.'], 500);
}
